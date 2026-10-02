/**
 * Replay must never cross a session boundary, and must not retry a refusal.
 *
 * Two properties that a test of the happy path cannot see:
 *
 *  1. SESSION BOUNDARY. `AuthContext.login()` / `logout()` clear the IndexedDB
 *     queue, but with a fire-and-forget `clearAllData()`, while `trySyncAll()`
 *     holds an in-memory snapshot taken before the wipe. A session switch
 *     landing mid-replay used to let the loop keep going and transmit the
 *     PREVIOUS tenant's queued writes under that tenant's bearer token — after
 *     the switch. That is a cross-tenant write, not just a lost record, and it
 *     is the reason the loop re-checks the active token before every send.
 *
 *  2. 4xx IS TERMINAL. The backend refuses a duplicate attendance with 422
 *     precisely so the client stops trying. Retrying it five times cannot
 *     succeed, and the exponential backoff delays every item queued behind it.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const request = vi.fn()

vi.mock('axios', () => ({
  default: Object.assign(
    (...args: unknown[]) => request(...args),
    { create: () => ({ get: vi.fn(), post: vi.fn() }) },
  ),
}))

const { addToSyncQueue, getPendingSyncItems, getActionableSyncCount } = await import('@/lib/db')
const { trySyncAll } = await import('@/lib/sync')

const TENANT_A = 'tenant-a-token'
const TENANT_B = 'tenant-b-token'

async function seed(token: string, endpoint = '/attendances/record', retries = 0): Promise<number> {
  return addToSyncQueue({
    operation: 'create',
    endpoint,
    method: 'POST',
    body: { qr_token: 'tok' },
    token,
    status: retries > 0 ? 'failed' : 'pending',
    retries,
  })
}

function httpError(status: number) {
  return Object.assign(new Error(`HTTP ${status}`), { response: { status } })
}

describe('replay session isolation', () => {
  beforeEach(() => {
    request.mockReset()
    localStorage.setItem('auth_token', TENANT_A)
  })

  /**
   * The reachable shape of the race: the switch lands WHILE the loop is
   * running, after the snapshot was taken.
   *
   * A switch that completes BEFORE `trySyncAll()` starts cannot leave stale
   * items behind — `AuthContext` clears the whole queue on login/logout and
   * `SyncContext` serialises runs behind `syncingRef`. That half is covered by
   * `src/test/authSession.test.tsx`. The uncovered half is the interleaving
   * below, and it is the one that transmits data.
   */
  it('stops before the second send when the session changed after the first', async () => {
    await seed(TENANT_A, '/attendances/record')
    await seed(TENANT_A, '/attendances/record-by-member-id')

    request.mockImplementationOnce(async () => {
      localStorage.setItem('auth_token', TENANT_B)
      return { status: 200 }
    })

    await trySyncAll()

    expect(request).toHaveBeenCalledTimes(1)
  })

  it('stops mid-run when the session changes between two items', async () => {
    await seed(TENANT_A, '/attendances/record')
    await seed(TENANT_A, '/attendances/record-by-member-id')
    await seed(TENANT_A, '/attendances/today')

    // First send succeeds and, as a side effect, the user logs in as another
    // tenant — the exact interleaving the guard exists for.
    request.mockImplementationOnce(async () => {
      localStorage.setItem('auth_token', TENANT_B)
      return { status: 200 }
    })

    await trySyncAll()

    // Exactly one send: the two remaining items must not be transmitted.
    expect(request).toHaveBeenCalledTimes(1)
    const sent = request.mock.calls[0]?.[0] as { headers?: Record<string, string> } | undefined
    expect(sent?.headers?.Authorization).toBe(`Bearer ${TENANT_A}`)
  })

  it('does not count an aborted run as failures', async () => {
    await seed(TENANT_A, '/attendances/record')
    await seed(TENANT_A, '/attendances/record-by-member-id')

    request.mockImplementationOnce(async () => {
      localStorage.setItem('auth_token', TENANT_B)
      return { status: 200 }
    })

    const result = await trySyncAll()

    // 1 completed, 1 voided by the session change — not a failure.
    expect(result.synced).toBe(1)
    expect(result.failed).toBe(0)
  })

  it('replays normally when the session has not changed', async () => {
    request.mockResolvedValue({ status: 200 })
    await seed(TENANT_A)

    const result = await trySyncAll()

    expect(result.synced).toBe(1)
    expect(request).toHaveBeenCalledTimes(1)
  })

  it('replays normally when there is no active session at all', async () => {
    localStorage.removeItem('auth_token')
    request.mockResolvedValue({ status: 200 })
    await seed(TENANT_A)

    // No session means no session TO CHANGE, so the run is not voided. The
    // backend is authoritative on whether the stored token is still valid.
    const result = await trySyncAll()

    expect(result.synced).toBe(1)
  })
})

describe('permanent rejections are terminal', () => {
  beforeEach(() => {
    request.mockReset()
    localStorage.setItem('auth_token', TENANT_A)
  })

  it.each([400, 401, 403, 404, 409, 422])(
    'abandons a %i immediately without burning the retry budget',
    async (status) => {
      request.mockRejectedValue(httpError(status))
      await seed(TENANT_A)

      const result = await trySyncAll()

      expect(result.failed).toBe(1)
      // One attempt only — not five.
      expect(request).toHaveBeenCalledTimes(1)
      // And never selected again.
      expect(await getPendingSyncItems()).toHaveLength(0)
    },
  )

  it('a duplicate attendance (422) is not retried', async () => {
    request.mockRejectedValue(httpError(422))
    await seed(TENANT_A)

    await trySyncAll()

    // The banner must not show a phantom pending item.
    expect(await getActionableSyncCount()).toBe(0)
  })

  it.each([500, 502, 503])('still retries a %i', async (status) => {
    request.mockRejectedValue(httpError(status))
    await seed(TENANT_A, '/attendances/record', 4)

    const result = await trySyncAll()

    // Transient: retried, and at retries=4 the next attempt hits the ceiling.
    expect(request).toHaveBeenCalledTimes(1)
    expect(result.failed).toBe(1)
    expect(await getPendingSyncItems()).toHaveLength(0)
  })

  it('still retries a transport failure with no response at all', async () => {
    request.mockRejectedValue(new Error('Network Error'))
    await seed(TENANT_A)

    const result = await trySyncAll()

    expect(result.failed).toBe(1)
    expect(await getPendingSyncItems()).toHaveLength(1)
  })
})

describe('pending count reflects only actionable items', () => {
  beforeEach(() => {
    request.mockReset()
    localStorage.setItem('auth_token', TENANT_A)
  })

  it('excludes abandoned items so the banner can clear', async () => {
    request.mockRejectedValue(httpError(422))
    await seed(TENANT_A)

    await trySyncAll()

    // Terminal dead-letter: retained for diagnostics, but it is not something
    // the user is still "waiting to sync".
    expect(await getActionableSyncCount()).toBe(0)
  })

  it('still counts pending and failed items', async () => {
    request.mockRejectedValue(new Error('network down'))
    await seed(TENANT_A, '/attendances/record', 0)

    expect(await getActionableSyncCount()).toBe(1)
  })
})
