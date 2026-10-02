import axios from 'axios'
import { resolveApiUrl } from '@/lib/apiUrl'
import { addToSyncQueue, getPendingSyncItems, markSyncCompleted, markSyncFailed, markSyncAbandoned, clearCompletedSyncItems, clearAllData } from './db'

const MAX_RETRIES = 5
const RETRY_BASE_DELAY = 2000

export type SyncEventCallback = (event: { type: 'start' | 'item-complete' | 'item-failed' | 'complete' | 'error'; item?: string; message?: string }) => void

const listeners: Set<SyncEventCallback> = new Set()

export function onSyncEvent(cb: SyncEventCallback) {
  listeners.add(cb)
  return () => listeners.delete(cb)
}

/** The bearer token of the session that is currently active, if any. */
function currentSessionToken(): string | null {
  return localStorage.getItem('auth_token')
}

/** HTTP status of a replay failure, when there is a response. */
function statusOf(error: unknown): number | null {
  const response = (error as { response?: { status?: number } } | null)?.response
  return typeof response?.status === 'number' ? response.status : null
}

/**
 * `Retry-After` (in seconds) a server attached to a replay failure, if any.
 * axios exposes response headers as an AxiosHeaders instance (`.get()`) in
 * the browser and as a plain object in adapters/tests, so both shapes are
 * accepted.
 */
function retryAfterSeconds(error: unknown): number | null {
  const response = (error as { response?: { headers?: unknown } } | null)?.response
  const headers = response?.headers
  if (!headers || typeof headers !== 'object') return null

  let raw: unknown
  const getter = (headers as { get?: unknown }).get
  if (typeof getter === 'function') {
    raw = (headers as { get: (name: string) => unknown }).get('Retry-After')
  } else {
    const record = headers as Record<string, unknown>
    raw = record['Retry-After'] ?? record['retry-after']
  }

  if (typeof raw !== 'string' && typeof raw !== 'number') return null
  const seconds = Number(raw)
  return Number.isFinite(seconds) && seconds >= 0 ? seconds : null
}

/** Longest server-directed delay the queue will honor, in milliseconds. */
const RETRY_AFTER_MAX_DELAY = 60_000

/**
 * Delay before the next replay attempt for a transient failure.
 *
 * A 429 carries `Retry-After` (the API's per-minute limiters set it) — the
 * server has told us exactly how long the window lasts, so honoring it beats
 * guessing: the previous fixed exponential (2s, 4s, ...) could exhaust all
 * five retries inside a single 60s window and dead-letter a healthy item.
 * The value is capped so a malformed header can never park the queue for
 * hours, and absent/invalid headers fall back to the exponential schedule.
 *
 * Exported for direct testing — the delay policy is pure arithmetic and the
 * behavioural tests below deliberately avoid sleeping through it.
 */
export function backoffDelayFor(error: unknown, retries: number): number {
  const fallback = RETRY_BASE_DELAY * Math.pow(2, retries)
  const seconds = retryAfterSeconds(error)
  if (seconds === null) return fallback
  return Math.min(seconds * 1000, RETRY_AFTER_MAX_DELAY)
}

/**
 * Whether a replay failure is permanent, i.e. retrying cannot help.
 *
 * 4xx means the server understood the request and refused it. 401 and 403/404
 * are refusals of the credential or the target; 409/422 are business-rule
 * refusals (most importantly a duplicate attendance, which the backend
 * returns so the client stops trying). None of them become valid by being sent
 * again, and 5xx/transport failures genuinely might.
 *
 * Two 4xx statuses are deliberately EXCLUDED from "permanent" despite the
 * range check, because the refusal is about *when*, not *whether* (Phase 1C
 * finding A-1):
 *
 *   - 429: the server is rate-limiting, not rejecting. Treating it as
 *     permanent abandoned the queued attendance write after ONE rate-limit
 *     response — silent data loss for an offline-first capture flow. The
 *     backend throttles the replay routes (`throttle:attendance-record`
 *     100/min, `throttle:api` 300/min), so 429 is an expected outcome of a
 *     large replay, not a poisoned item.
 *   - 408: the server timed out waiting for the request — by definition a
 *     condition a later attempt can avoid.
 *
 * Note 401 is treated as permanent here rather than as "session expired":
 * this loop runs a snapshot of items captured under one session, and a 401
 * means that specific item's stored token is no longer valid. The live
 * session's own 401 handling is the response interceptor's job, and this
 * replay path deliberately does not go through it.
 */
function isPermanentRejection(error: unknown): boolean {
  const status = statusOf(error)
  if (status === null || status < 400 || status >= 500) return false
  return status !== 429 && status !== 408
}

function emit(event: { type: 'start' | 'item-complete' | 'item-failed' | 'complete' | 'error'; item?: string; message?: string }) {
  listeners.forEach(cb => cb(event))
}

export async function queueOfflineRequest(
  operation: 'create' | 'update' | 'delete',
  endpoint: string,
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  body: unknown,
  token: string,
): Promise<number> {
  return addToSyncQueue({ operation, endpoint, method, body, token, status: 'pending', retries: 0 })
}

export async function processSyncQueue(): Promise<void> {
  // Delegate to the real replay routine. The previous implementation marked
  // every queued item "completed" without ever issuing the HTTP request,
  // silently dropping all pending offline writes.
  await trySyncAll()
}

export async function trySyncAll(): Promise<{ synced: number; failed: number }> {
  const items = await getPendingSyncItems()
  if (items.length === 0) return { synced: 0, failed: 0 }

  emit({ type: 'start', message: `Syncing ${items.length} item(s)...` })

  let synced = 0
  let failed = 0

  // The session this replay belongs to.
  //
  // `AuthContext.login()` / `logout()` / `platformLogin()` clear the queue,
  // but they do so with a fire-and-forget `clearAllData()` and this loop holds
  // an in-memory snapshot taken before the wipe. A session switch landing
  // mid-replay therefore used to keep the loop running: it would transmit the
  // PREVIOUS tenant's queued writes, signed with that tenant's bearer token,
  // after the switch. `markSyncCompleted` then no-opped because the row was
  // already gone, so the write happened and the queue silently lost the record
  // of it.
  //
  // Re-checking the active token before every send closes that window: if the
  // session changed, the run aborts and the remaining items are left pending
  // for the next session's own queue (which is empty) rather than being
  // transmitted under a foreign credential.
  const sessionToken = currentSessionToken()

  for (const item of items) {
    if (item.status === 'completed') {
      synced++
      continue
    }

    if (currentSessionToken() !== sessionToken) {
      emit({
        type: 'error',
        message: 'Session changed during sync — remaining items were left queued.',
      })
      // Deliberately NOT counted as failures and NOT retried: the items are
      // untouched, and this run is void.
      break
    }

    try {
      await axios({
        method: item.method,
        // Use the same base URL as the live client. This used to be built
        // inline as `${VITE_API_URL}/v1${endpoint}`, which omitted Laravel's
        // `/api` prefix and 404'd on every replay in production.
        url: item.endpoint.startsWith('http') ? item.endpoint : resolveApiUrl(item.endpoint),
        data: item.body,
        headers: {
          Authorization: `Bearer ${item.token}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
      await markSyncCompleted(item.id!)
      synced++
      emit({ type: 'item-complete', item: item.endpoint })
    } catch (error) {
      // A REFUSAL is a permanent failure, not a transient one. Retrying a
      // 4xx five times cannot succeed; it only burns the retry budget and
      // delays every item queued behind it by the backoff. The common case is
      // a duplicate attendance, which the server answers 422 precisely so the
      // client stops trying. 429/408 are the documented exceptions (see
      // isPermanentRejection): there the server is saying "later", so the
      // item flows into the transient path below and stays queued.
      if (isPermanentRejection(error)) {
        await markSyncAbandoned(item.id!, item.retries + 1)
        failed++
        emit({
          type: 'item-failed',
          item: item.endpoint,
          message: `Rejected by server (${statusOf(error)}) — not retried`,
        })
        continue
      }

      const nextRetries = item.retries + 1
      if (nextRetries >= MAX_RETRIES) {
        // Terminal dead-letter. Previously the item stayed 'failed', so
        // getPendingSyncItems() returned it again on the next sync run and it
        // retried forever. It is now parked and never re-selected, while the
        // record is retained so the write is not silently lost.
        await markSyncAbandoned(item.id!, nextRetries)
        failed++
        emit({ type: 'item-failed', item: item.endpoint, message: 'Max retries reached — abandoned' })
      } else {
        await markSyncFailed(item.id!, nextRetries)
        failed++
        const delay = backoffDelayFor(error, item.retries)
        await new Promise(resolve => setTimeout(resolve, delay))
      }
    }
  }

  await clearCompletedSyncItems()
  emit({ type: 'complete', message: `Synced ${synced}, failed ${failed}` })

  return { synced, failed }
}

export async function clearAllSyncData(): Promise<void> {
  await clearAllData()
}
