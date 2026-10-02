/**
 * The offline write path has to actually work.
 *
 * Two defects made it inert while the code read as if it were live:
 *
 *  1. `OFFLINE_WRITABLE_PATTERNS` in `src/api/client.ts` matched
 *     `/api/v1/attendance`, but the request interceptor tests `config.url`,
 *     which is the RELATIVE path the API layer passes to `client.post(...)`
 *     — `/attendances/record`. The `/api/v1` prefix is only ever added by
 *     `buildBaseUrl()` to the instance's `baseURL`, never to `config.url`, and
 *     the real routes are `attendances` (plural), not `attendance`. So no
 *     pattern could match and no attendance write was ever queued.
 *
 *  2. `trySyncAll()` built its replay URL from `VITE_API_URL` directly as
 *     `${VITE_API_URL}/v1${endpoint}`, bypassing `buildBaseUrl()`. Under the
 *     documented production value (`https://backend.up.railway.app`) that
 *     produced `https://.../v1/attendances/record`, which is missing Laravel's
 *     `/api` prefix and 404s. Every replayed write would fail.
 *
 * Both are asserted here against the real modules. A test that only read the
 * source could agree with a pattern that no runtime input ever matches, so
 * these drive the actual interceptor and the actual replay loop.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const request = vi.fn()
request.mockResolvedValue({ status: 200, data: {} })

vi.mock('axios', () => ({
  default: Object.assign(
    (...args: unknown[]) => request(...args),
    {
      create: () => {
        // Hand back a real axios-shaped instance so `client.interceptors.*`
        // and `client(config)` behave as they do in the browser.
        const handlers: {
          request?: (c: unknown) => unknown
          rejected?: (e: unknown) => unknown
          response?: (r: unknown) => unknown
          responseRejected?: (e: unknown) => unknown
        } = {}
        const inst = {
          defaults: { headers: { common: {} } },
          interceptors: {
            request: {
              use: (ok: (c: unknown) => unknown) => { handlers.request = ok },
              eject: () => {},
            },
            response: {
              use: (ok: (r: unknown) => unknown, err: (e: unknown) => unknown) => {
                handlers.response = ok
                handlers.responseRejected = err
              },
              eject: () => {},
            },
          },
          get: vi.fn(),
          post: vi.fn(),
          put: vi.fn(),
          patch: vi.fn(),
          delete: vi.fn(),
          __handlers: handlers,
        }
        return inst
      },
    },
  ),
}))

const { getPendingSyncItems, getSyncQueueLength, addToSyncQueue } = await import('@/lib/db')
const { trySyncAll } = await import('@/lib/sync')
const { default: client } = await import('@/api/client')

type Handlers = {
  request?: (c: unknown) => unknown
  responseRejected?: (e: unknown) => unknown
}

function handlers(): Handlers {
  return (client as unknown as { __handlers: Handlers }).__handlers
}

/** A request config shaped the way axios hands one to a request interceptor. */
function config(overrides: Record<string, unknown> = {}) {
  return {
    method: 'post',
    url: '/attendances/record',
    headers: {} as Record<string, string>,
    data: { qr_token: 'tok', attendance_context_id: 3 },
    ...overrides,
  }
}

/**
 * Run the registered request interceptor and normalise the two outcomes into a
 * plain value: the interceptor either resolves with the config it should send,
 * or rejects with its own sentinel.
 */
async function runRequestInterceptor(
  cfg: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  const onHandled = handlers().request
  if (!onHandled) throw new Error('the request interceptor was never registered')

  try {
    return (await onHandled(cfg)) as Record<string, unknown>
  } catch (e) {
    return e as Record<string, unknown>
  }
}

const ATTENDANCE_WRITES = [
  '/attendances/record',
  '/attendances/record-by-member-id',
]

/**
 * The config object the replay loop handed to axios on a given call.
 *
 * Asserting on this is the point of the file: a replay that is sent to the
 * wrong URL, or without the right credential, is otherwise invisible.
 */
function asReplayedRequest(
  mock: { mock: { calls: unknown[][] } },
  index: number,
): { url?: string; headers?: Record<string, string> } {
  const call = mock.mock.calls[index]
  if (!call) throw new Error(`axios was not called ${index + 1} time(s)`)
  return (call[0] ?? {}) as { url?: string; headers?: Record<string, string> }
}

describe('offline attendance enqueue', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tenant-a-token')
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })
  })

  afterEach(() => {
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true })
  })

  it.each(ATTENDANCE_WRITES)('queues %s when the device is offline', async (url) => {
    // A rejection carrying `__offline_queued` is the interceptor's signal that
    // it took ownership of the request instead of sending it.
    const result = await runRequestInterceptor(config({ url }))

    expect(result).toMatchObject({ __offline_queued: true })

    const queued = await getPendingSyncItems()
    expect(queued).toHaveLength(1)
    expect(queued[0]?.endpoint).toBe(url)
    expect(queued[0]?.method).toBe('POST')
  })

  it('captures the bearer token, so the replay is not sent unauthenticated', async () => {
    await runRequestInterceptor(config())

    const [item] = await getPendingSyncItems()
    expect(item?.token).toBe('tenant-a-token')
  })

  it('does not queue when the device is online', async () => {
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true })

    // Resolving (rather than rejecting) means the request proceeds normally.
    const result = await runRequestInterceptor(config())

    expect(result).not.toMatchObject({ __offline_queued: true })
    expect(await getSyncQueueLength()).toBe(0)
  })

  it('does not queue reads', async () => {
    await runRequestInterceptor(config({ method: 'get' }))

    expect(await getSyncQueueLength()).toBe(0)
  })
})

describe('offline replay URL', () => {
  beforeEach(() => {
    request.mockClear()
  })

  it('replays to the same base URL the live client uses', async () => {
    await addToSyncQueue({
      operation: 'create',
      endpoint: '/attendances/record',
      method: 'POST',
      body: { qr_token: 'tok' },
      token: 'tenant-a-token',
      status: 'pending',
      retries: 0,
    })

    await trySyncAll()

    expect(request).toHaveBeenCalledTimes(1)
    const sent = asReplayedRequest(request, 0) as { url: string; headers: Record<string, string> }

    // Whatever `VITE_API_URL` is, the replayed path must carry Laravel's
    // `/api/v1` prefix exactly once — the same construction `buildBaseUrl()`
    // applies to the live axios instance.
    expect(sent.url).toMatch(/\/api\/v1\/attendances\/record$/)
    expect(sent.headers.Authorization).toBe('Bearer tenant-a-token')
  })

  it('never emits a /v1 path without the /api prefix', async () => {
    await addToSyncQueue({
      operation: 'create',
      endpoint: '/attendances/record-by-member-id',
      method: 'POST',
      body: { member_id: 'M1' },
      token: 'tenant-a-token',
      status: 'pending',
      retries: 0,
    })

    await trySyncAll()

    const sent = asReplayedRequest(request, 0) as { url: string }
    expect(sent.url).toContain('/api/v1/')
    expect(sent.url).not.toMatch(/:\/\/[^/]+\/v1\//)
  })
})
