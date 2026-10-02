/**
 * Offline replay must build the SAME URL as the live client, under the
 * production `VITE_API_URL` value.
 *
 * This is a separate file from `offlineAttendancePath.test.ts` because
 * `src/api/client.ts` reads `VITE_API_URL` into a module-scope constant at
 * import time, so the environment has to be stubbed BEFORE the module graph is
 * loaded. The sibling test exercises the interceptor, which does not depend on
 * the base URL, and can share one import.
 *
 * The defect
 * ----------
 * `trySyncAll()` built its replay URL inline:
 *
 *   `${import.meta.env.VITE_API_URL || '/api'}/v1${item.endpoint}`
 *
 * The live client instead goes through `buildBaseUrl()`, which is what
 * guarantees Laravel's `/api` prefix is present. With the documented
 * production value (`VITE_API_URL=https://backend.up.railway.app`, set on
 * Vercel per the project README) the inline form produced:
 *
 *   https://backend.up.railway.app/v1/attendances/record   ← 404
 *
 * so every replayed offline attendance failed. Docker dev (`VITE_API_URL=/api`)
 * produced the correct URL by coincidence, which is why this never showed up
 * in a local environment. That coincidence is exactly why the assertion below
 * pins an ABSOLUTE production URL rather than the dev default.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const request = vi.fn()

vi.mock('axios', () => ({
  default: Object.assign(
    (...args: unknown[]) => request(...args),
    { create: () => ({ interceptors: { request: { use: () => {} }, response: { use: () => {} } }, defaults: { headers: { common: {} } }, get: vi.fn(), post: vi.fn() }) },
  ),
}))

const PRODUCTION_API_URL = 'https://cms-production-7eb4.up.railway.app'

/** The config object the replay loop handed to axios on a given call. */
function asReplayedRequest(
  mock: { mock: { calls: unknown[][] } },
  index: number,
): { url?: string; headers?: Record<string, string> } {
  const call = mock.mock.calls[index]
  if (!call) throw new Error(`axios was not called ${index + 1} time(s)`)
  return (call[0] ?? {}) as { url?: string; headers?: Record<string, string> }
}

vi.stubEnv('VITE_API_URL', PRODUCTION_API_URL)

// Imported after stubEnv so apiUrl.ts captures the production value.
const { addToSyncQueue } = await import('@/lib/db')
const { trySyncAll } = await import('@/lib/sync')
const { resolveApiUrl, API_BASE_URL } = await import('@/lib/apiUrl')

async function seedQueue(endpoint: string): Promise<void> {
  await addToSyncQueue({
    operation: 'create',
    endpoint,
    method: 'POST',
    body: { qr_token: 'tok', attendance_context_id: 3 },
    token: 'tenant-a-token',
    status: 'pending',
    retries: 0,
  })
}

describe('offline replay under the production VITE_API_URL', () => {
  beforeEach(() => {
    request.mockReset()
    request.mockResolvedValue({ status: 200, data: {} })
  })

  it('replays to a URL carrying the /api/v1 prefix', async () => {
    await seedQueue('/attendances/record')
    await trySyncAll()

    expect(request).toHaveBeenCalledTimes(1)
    const sent = asReplayedRequest(request, 0) as { url: string }

    expect(sent.url).toBe(`${PRODUCTION_API_URL}/api/v1/attendances/record`)
  })

  it('never emits the un-prefixed /v1 path that 404s', async () => {
    await seedQueue('/attendances/record-by-member-id')
    await trySyncAll()

    const sent = asReplayedRequest(request, 0) as { url: string }

    expect(sent.url).not.toBe(`${PRODUCTION_API_URL}/v1/attendances/record-by-member-id`)
    expect(sent.url).toContain('/api/v1/')
  })

  it('resolveApiUrl agrees with the base URL the live client uses', () => {
    expect(API_BASE_URL).toBe(`${PRODUCTION_API_URL}/api/v1`)
    expect(resolveApiUrl('/attendances/record')).toBe(`${API_BASE_URL}/attendances/record`)
  })

  it('preserves the bearer token on the replayed request', async () => {
    await seedQueue('/attendances/record')
    await trySyncAll()

    const sent = asReplayedRequest(request, 0) as { headers: Record<string, string> }
    expect(sent.headers.Authorization).toBe('Bearer tenant-a-token')
  })
})
