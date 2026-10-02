/**
 * The single place the API base URL is constructed.
 *
 * This exists as its own module rather than as a helper inside
 * `src/api/client.ts` because two independent call sites need it: the live
 * axios instance, and the offline replay loop in `src/lib/sync.ts` (which
 * issues bare `axios()` calls, not requests through the configured client).
 *
 * The bug it prevents
 * -------------------
 * `trySyncAll()` used to build its own URL inline:
 *
 *   `${import.meta.env.VITE_API_URL || '/api'}/v1${item.endpoint}`
 *
 * while the live client used `buildBaseUrl()`, which is what guarantees
 * Laravel's `/api` prefix is present. Under the documented production value
 * (`VITE_API_URL=https://backend.up.railway.app`) the inline form produced
 * `https://.../v1/attendances/record` — a 404 on every replayed offline
 * write. Docker dev (`VITE_API_URL=/api`) produced the right answer by
 * coincidence, which is why it survived.
 *
 * One implementation, imported by both call sites, so a second derivation
 * cannot appear without someone editing this file.
 */

const API_URL = import.meta.env.VITE_API_URL || '/api'

/**
 * Normalize whatever is in `VITE_API_URL` into an origin+`/api/v1` base.
 *
 * Laravel registers its API routes under the automatic `/api` prefix, so the
 * deployed API root is always `<origin>/api/v1` regardless of whether
 * `VITE_API_URL` already carries a prefix.
 */
function buildBaseUrl(rawUrl: string): string {
  if (rawUrl.startsWith('http')) {
    const url = new URL(rawUrl.replace(/\/+$/, ''))
    url.pathname = '/api/v1'
    return url.toString().replace(/\/+$/, '')
  }
  const normalized = rawUrl.replace(/\/+$/, '') || '/api'
  return `${normalized}/v1`
}

/** The base URL every API request is issued against. */
export const API_BASE_URL: string = buildBaseUrl(API_URL)

/**
 * Resolve a relative API path (e.g. `/attendances/record`) to a full URL.
 */
export function resolveApiUrl(path: string): string {
  const suffix = path.startsWith('/') ? path : `/${path}`
  return `${API_BASE_URL}${suffix}`
}
