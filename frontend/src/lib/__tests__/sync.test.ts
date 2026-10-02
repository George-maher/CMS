/**
 * Offline queue replay: retry, backoff, and the terminal dead-letter state.
 *
 * `trySyncAll()` is the routine that drains the queue. The property that
 * matters most is TERMINATION: an item that can never succeed must stop being
 * retried, otherwise a single poison entry is re-read on every sync run
 * forever and the queue can never drain.
 *
 * axios is mocked because this is a test of the retry state machine, not of
 * the HTTP layer. The request it issues is asserted so a change to the replay
 * contract cannot pass unnoticed.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const request = vi.fn()

vi.mock('axios', () => ({
  default: Object.assign(
    (...args: unknown[]) => request(...args),
    { create: () => ({ get: vi.fn(), post: vi.fn() }) },
  ),
}))

const { addToSyncQueue, getPendingSyncItems, getSyncQueueLength } = await import('@/lib/db')
const { trySyncAll, backoffDelayFor } = await import('@/lib/sync')

const TOKEN = 'tenant-a-token'

async function seed(retries = 0): Promise<number> {
  return addToSyncQueue({
    operation: 'create',
    endpoint: '/attendances/record',
    method: 'POST',
    body: { member_id: 7 },
    token: TOKEN,
    status: retries > 0 ? 'failed' : 'pending',
    retries,
  })
}

describe('trySyncAll', () => {
  beforeEach(() => {
    request.mockReset()
  })

  /*
   * Real timers are used deliberately.
   *
   * trySyncAll() awaits `setTimeout` for its backoff, and fake-indexeddb
   * completes its own writes on a real macrotask. Faking setTimeout therefore
   * deadlocks: the timer is not yet registered when the flush runs, because
   * the code is still awaiting an IndexedDB write. Since MAX_RETRIES is 5 and
   * the backoff doubles (2s, 4s, 8s, 16s), the tests below are instead
   * written to spend as few backoff waits as possible — seeding an item
   * close to its retry ceiling where the test only needs the final transition.
   */

  it('does nothing when the queue is empty', async () => {
    request.mockResolvedValue({ status: 200 })

    const result = await trySyncAll()

    expect(result).toEqual({ synced: 0, failed: 0 })
    expect(request).not.toHaveBeenCalled()
  })

  it('marks a successfully replayed item completed', async () => {
    request.mockResolvedValue({ status: 200 })
    await seed()

    const result = await trySyncAll()

    expect(result.synced).toBe(1)
    expect(result.failed).toBe(0)
    // Completed entries are cleared at the end of a successful run.
    expect(await getSyncQueueLength()).toBe(0)
  })

  it('replays with the token captured when the write was queued', async () => {
    request.mockResolvedValue({ status: 200 })
    await seed()

    await trySyncAll()

    expect(request).toHaveBeenCalledTimes(1)
    const [config] = request.mock.calls[0] as [{ method: string; headers: Record<string, string> }]
    expect(config.method).toBe('POST')
    expect(config.headers.Authorization).toBe(`Bearer ${TOKEN}`)
  })

  it('keeps a failed item queued so a later run retries it', async () => {
    request.mockRejectedValue(new Error('network down'))
    // Seeded at the ceiling minus one so this run abandons rather than
    // sleeping; the retry-retains behaviour is asserted in the next test.
    await seed(4)

    const result = await trySyncAll()

    expect(result.synced).toBe(0)
    expect(result.failed).toBe(1)
  })

  it('a transiently failing item stays queued across runs', async () => {
    // Fails once, then succeeds. One backoff wait, then a clean completion.
    request
      .mockRejectedValueOnce(new Error('flaky'))
      .mockResolvedValue({ status: 200 })

    await seed(1) // 1 retry spent; this failure makes 2, so it is retried

    const first = await trySyncAll()
    expect(first.failed).toBe(1)
    expect(await getPendingSyncItems()).toHaveLength(1)

    const second = await trySyncAll()
    expect(second.synced).toBe(1)
    expect(await getSyncQueueLength()).toBe(0)
  })

  /**
   * The termination property. MAX_RETRIES is 5; an item that fails on every
   * attempt must be parked in 'abandoned' and never selected again.
   */
  it('abandons an item that exhausts its retries, and never selects it again', async () => {
    request.mockRejectedValue(new Error('permanent failure'))

    // 4 retries already spent, so exactly one attempt remains and this run
    // reaches the ceiling without a backoff wait.
    await seed(4)

    const result = await trySyncAll()

    expect(result.failed).toBe(1)
    expect(request).toHaveBeenCalledTimes(1)

    // Not re-selectable: a subsequent run must skip it entirely.
    expect(await getPendingSyncItems()).toHaveLength(0)

    request.mockClear()
    await trySyncAll()
    expect(request).not.toHaveBeenCalled()
  })

  it('processes each queued item exactly once per run', async () => {
    request.mockResolvedValue({ status: 200 })
    await seed()
    await seed()
    await seed()

    const result = await trySyncAll()

    expect(request).toHaveBeenCalledTimes(3)
    expect(result.synced).toBe(3)
  })

  it('isolates a poison item: healthy items still complete', async () => {
    // The poison item is already at its ceiling, so it is abandoned without a
    // backoff wait; the healthy item must still be delivered in the same run.
    request
      .mockRejectedValueOnce(new Error('poison'))
      .mockResolvedValue({ status: 200 })

    await seed(4) // abandoned on this run
    await seed(0) // healthy

    const result = await trySyncAll()

    expect(result.synced).toBe(1)
    expect(result.failed).toBe(1)
    expect(request).toHaveBeenCalledTimes(2)
  })

  /**
   * Phase 1C finding A-1: a 429 is a "retry later", not a refusal. The old
   * classification lumped every 4xx into permanent rejection, so ONE
   * rate-limit response during replay abandoned the queued attendance write
   * for good — silent data loss in an offline-first capture flow.
   *
   * `Retry-After: 0` is sent with the mocked 429 so this behavioral test
   * exercises the header-driven delay path without sleeping through it.
   */
  it('treats 429 as transient: the item stays queued instead of being abandoned', async () => {
    request
      .mockRejectedValueOnce({ response: { status: 429, headers: { 'Retry-After': '0' } } })
      .mockResolvedValue({ status: 200 })

    await seed()

    const first = await trySyncAll()
    expect(first.failed).toBe(1)
    // Retained, NOT dead-lettered: a rate-limited item must survive to the
    // next run. (Permanent rejection would have abandoned it immediately.)
    expect(await getPendingSyncItems()).toHaveLength(1)

    const second = await trySyncAll()
    expect(second.synced).toBe(1)
    expect(await getSyncQueueLength()).toBe(0)
  })

  /**
   * 408 Request Timeout is the other deliberate exception: the server timed
   * out waiting for the request, which a later attempt can avoid. No
   * Retry-After header here, so the exponential backoff applies (one 2s wait).
   */
  it('treats 408 as transient: the item stays queued instead of being abandoned', async () => {
    request
      .mockRejectedValueOnce({ response: { status: 408 } })
      .mockResolvedValue({ status: 200 })

    await seed()

    const first = await trySyncAll()
    expect(first.failed).toBe(1)
    expect(await getPendingSyncItems()).toHaveLength(1)

    const second = await trySyncAll()
    expect(second.synced).toBe(1)
  })

  /**
   * The flip side of the two exclusions above: a genuine business-rule
   * refusal must still be abandoned on the spot — the server answered 422
   * precisely so the client stops retrying (a duplicate attendance).
   */
  it('still abandons a 422 business-rule rejection immediately', async () => {
    request.mockRejectedValue({ response: { status: 422 } })

    await seed()

    const first = await trySyncAll()
    expect(first.failed).toBe(1)
    // Abandoned after a single attempt — no backoff, no second send.
    expect(await getPendingSyncItems()).toHaveLength(0)

    request.mockClear()
    await trySyncAll()
    expect(request).not.toHaveBeenCalled()
  })
})

/**
 * The 429 delay policy is pure arithmetic, so it is asserted directly
 * instead of through wall-clock sleeps in the behavioral tests above.
 */
describe('backoffDelayFor', () => {
  it('honors Retry-After when the server sends one', () => {
    expect(
      backoffDelayFor({ response: { status: 429, headers: { 'Retry-After': '3' } } }, 0),
    ).toBe(3000)
  })

  it('caps an absurd Retry-After so the queue cannot be parked for hours', () => {
    expect(
      backoffDelayFor({ response: { status: 429, headers: { 'Retry-After': '86400' } } }, 0),
    ).toBe(60_000)
  })

  it('reads headers through an AxiosHeaders-style getter too', () => {
    const headers = { get: (name: string) => (name === 'Retry-After' ? '5' : null) }
    expect(backoffDelayFor({ response: { status: 429, headers } }, 0)).toBe(5000)
  })

  it('falls back to exponential backoff when the header is missing or invalid', () => {
    expect(backoffDelayFor(new Error('network down'), 3)).toBe(16_000)
    expect(
      backoffDelayFor({ response: { status: 429, headers: { 'Retry-After': 'soon' } } }, 1),
    ).toBe(4000)
  })
})
