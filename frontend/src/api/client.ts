import axios from 'axios'
import { logAxiosError } from '@/lib/debug'
import { addToSyncQueue, clearAllData } from '@/lib/db'
import { getCached, setCache, isStale, getInflight, setInflight, invalidateCache, getGeneration } from '@/lib/requestCache'
import { recordApiTiming } from '@/lib/perf'

/*
 * VITE_API_URL handling:
 *   In production (Vercel + Railway), set VITE_API_URL to your Railway backend URL
 *   WITHOUT a trailing slash, WITHOUT the /api prefix:
 *     VITE_API_URL=https://your-railway-app.up.railway.app
 *   For Docker dev, leave as default:
 *     VITE_API_URL=/api
 *
 * The code below always appends /api/v1 to construct the final baseURL,
 * regardless of whether VITE_API_URL already includes /api or not.
 * This guarantees the URL always matches Laravel's automatic /api prefix.
 *
 * withCredentials: true is required for cross-origin requests (Vercel → Railway)
 * to send cookies for Sanctum SPA authentication and ensure CORS credentials flow.
 * It also forces the browser to include Origin header on every request.
 */
const API_URL = import.meta.env.VITE_API_URL || '/api'

function buildBaseUrl(rawUrl: string): string {
  if (rawUrl.startsWith('http')) {
    const url = new URL(rawUrl.replace(/\/+$/, ''))
    url.pathname = '/api/v1'
    return url.toString().replace(/\/+$/, '')
  }
  const normalized = rawUrl.replace(/\/+$/, '') || '/api'
  return `${normalized}/v1`
}

const client = axios.create({
  baseURL: buildBaseUrl(API_URL),
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

/*
 * Bare axios instance used ONLY for the stale-while-revalidate background
 * refresh. It has NO interceptors, so it will not re-enter the caching logic.
 * Using it (instead of a bare `axios.get(config.url, ...)`) guarantees the
 * relative url from the original config resolves against the API baseURL and
 * not against the SPA origin (which would hit the Vercel rewrite and cache
 * index.html as API data).
 */
const networkClient = axios.create({
  baseURL: buildBaseUrl(API_URL),
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

const OFFLINE_WRITABLE_PATTERNS = [
  /\/api\/v1\/attendance$/,
  /\/api\/v1\/attendance\/bulk$/,
  /\/api\/v1\/attendance\/scan$/,
]

/*
 * ---------------------------------------------------------------------------
 * Mutation → cache-key invalidation
 * ---------------------------------------------------------------------------
 * Every GET response is cached (URL + params, scoped by auth token). After a
 * successful mutation, the affected GET cache keys MUST be purged so the next
 * read hits the network and reflects the authoritative server state — this is
 * what makes browser-refresh unnecessary for CRUD/approval workflows.
 *
 * Each entry maps a mutation-URL fragment to the cache-key patterns it
 * invalidates. A mutation can invalidate MULTIPLE conceptual queries because
 * one mutation can staleness many surfaces:
 *
 *   approve/reject an application       → applications list, dashboard counts
 *   create/update a stage               → stages list, structure, classes
 *   assign a member to a class          → classes, structure, stages, users
 *   approve a profile-update request    → request list AND the user record
 *   record attendance                   → attendees, points, leaderboard, dashboard
 *
 * Patterns are matched with `url.includes(fragment)` and purge with
 * `key.includes(pattern)` — both are prefix-ish substring matches over
 * `/v1/...` API paths, so a pattern like `/users` also covers `/users/members`,
 * `/users/servants`, `/users/{id}`, etc. Order is irrelevant (all matching
 * rules apply).
 */
const MUTATION_SKIP_URL_FRAGMENTS = [
  /*
   * Analytics heartbeat, not a data mutation — must NOT purge the /events
   * cache (it's called on every event detail view).
   */
  '/track-view',
  // Auth lifecycle is handled by AuthContext (cache cleared on login/logout).
  '/auth/',
]

const MUTATION_CACHE_INVALIDATIONS: ReadonlyArray<readonly [string, string[]]> = [
  // User management (create/update/delete/promote/demote/regenerate token)
  ['/users', ['/users', '/dashboard', '/leaderboard', '/notifications']],
  // Own profile update
  ['/profile', ['/users', '/notifications']],
  // Profile update requests (submit/approve/reject) — also stale the user data they change
  ['/profile-update-requests', ['/profile-update-requests', '/users', '/dashboard', '/notifications']],
  // Password reset requests (submit/approve/reject/reset-password)
  ['/password-reset-requests', ['/password-reset-requests', '/notifications']],
  // Membership requests — approval creates a real member
  ['/membership-requests', ['/membership-requests', '/users', '/dashboard', '/notifications']],
  // Platform church applications — status/counts change after approve/reject
  ['/platform/applications', ['/platform/applications', '/platform/dashboard', '/church', '/notifications']],
  // Platform church deletion/restore — dashboard counters
  ['/platform/churches', ['/platform/churches', '/platform/dashboard', '/church', '/notifications']],
  // Stages — list + any structure/class view that embeds stage data
  ['/stages', ['/stages', '/structure', '/classes']],
  // Classes — assignments also stale stage counts + user memberships
  ['/classes', ['/classes', '/structure', '/stages', '/users']],
  // Attendance contexts CRUD/toggle
  ['/attendance-contexts', ['/attendance-contexts', '/notifications']],
  // Daily verses CRUD/activate
  ['/verses', ['/verses', '/notifications']],
  // Feedback submit/resolve/reply/mark-seen
  ['/feedback', ['/feedback', '/notifications']],
  // Events (CRUD, lifecycle, registrations, buses, payments, sessions, speakers, accommodation)
  ['/events', ['/events', '/notifications']],
  // QR invites create/revoke
  ['/qr/invites', ['/qr/invites']],
  // QR invite accept — changes the accepting user's class/church
  ['/invite/', ['/invite/', '/users', '/structure', '/notifications']],
  // Daily spiritual records
  ['/spiritual-records', ['/spiritual-records', '/notifications']],
  // Bonus points award
  ['/points', ['/points', '/leaderboard', '/dashboard', '/notifications']],
  // Notification read-state mutations
  ['/notifications', ['/notifications']],
  // Attendance recording — also adds points
  ['/attendances', ['/attendances', '/points', '/leaderboard', '/dashboard', '/notifications']],
  // Storage uploads that change displayed resources
  ['/storage/upload-profile-image', ['/users', '/notifications']],
  ['/storage/upload-event-image', ['/events']],
]

/**
 * Purge every cache key that a mutation may have made stale.
 */
function invalidateForMutation(url: string): void {
  if (MUTATION_SKIP_URL_FRAGMENTS.some((frag) => url.includes(frag))) return
  for (const [fragment, patterns] of MUTATION_CACHE_INVALIDATIONS) {
    if (url.includes(fragment)) {
      for (const pattern of patterns) invalidateCache(pattern)
    }
  }
}

/*
 * Small deterministic hash (FNV-1a, 32-bit). Used to scope in-memory cache
 * entries to the current session so a logged-out/logged-in or a different
 * account can never reuse data previously cached for another user.
 */
function hashScope(input: string): string {
  let h = 2166136261
  for (let i = 0; i < input.length; i++) {
    h ^= input.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return (h >>> 0).toString(36)
}

function getCacheScope(): string {
  const token = localStorage.getItem('auth_token')
  return token ? hashScope(token) : 'anon'
}

function buildCacheKey(url: string, params: unknown): string {
  return `${getCacheScope()}:${url}:${JSON.stringify(params || {})}`
}

client.interceptors.request.use(async (config) => {
  // Record request start time for perf monitoring
  if (config.method === 'get' && config.url && !config.url.includes('/auth/me')) {
    ;(config as { metadata?: { startTime?: number } }).metadata = { startTime: Date.now() }
  }

  if (!navigator.onLine && config.method && ['post', 'put', 'patch', 'delete'].includes(config.method) && config.url) {
    const isOfflineWritable = OFFLINE_WRITABLE_PATTERNS.some(p => p.test(config.url || ''))
    if (isOfflineWritable) {
      // Read the token directly from storage: this branch early-returns
      // BEFORE the Authorization header is attached below, so reading it
      // from config.headers queued every item with an empty token and the
      // replay later failed with 401 (silent data loss).
      const token = localStorage.getItem('auth_token') || ''
      await addToSyncQueue({
        operation: config.method === 'delete' ? 'delete' : config.method === 'put' || config.method === 'patch' ? 'update' : 'create',
        endpoint: config.url,
        method: config.method.toUpperCase() as 'POST' | 'PUT' | 'PATCH' | 'DELETE',
        body: config.data,
        token,
        status: 'pending',
        retries: 0,
      })
      return Promise.reject({ __offline_queued: true, message: 'Request queued for sync' })
    }
  }

  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  const lng = localStorage.getItem('i18nextLng')
  if (lng === 'ar') {
    config.headers['Accept-Language'] = 'ar'
  } else {
    config.headers['Accept-Language'] = 'en'
  }

  if (config.method === 'get' && config.url) {
    const cacheKey = buildCacheKey(config.url, config.params || {})
    const cached = getCached(cacheKey)

    if (cached !== null && !isStale(cacheKey)) {
      // Fresh cache hit — skip network entirely
      const headers = config.headers ?? {}
      return {
        ...config,
        adapter: () => Promise.resolve({ data: cached, status: 200, statusText: 'OK', headers, config }),
      } as typeof config
    }

    if (cached !== null && isStale(cacheKey)) {
      // Stale-while-revalidate: return stale data now, fire background refresh
      if (!getInflight(cacheKey)) {
        const bgHeaders = { ...(config.headers ?? {}) }
        // Guard: if a mutation invalidates this cache key while the refresh is
        // in flight, the refresh's result must NOT re-populate the cache (it
        // could be older than the invalidation). The generation captured at
        // request start is compared on completion.
        const refreshGeneration = getGeneration()
        const bgPromise = networkClient.get(config.url, { params: config.params, headers: bgHeaders })
          .then((res) => {
            if (getGeneration() === refreshGeneration) {
              setCache(cacheKey, res.data)
            }
            return res
          })
          .catch(() => {})
        setInflight(cacheKey, bgPromise)
      }
      const headers = config.headers ?? {}
      return {
        ...config,
        adapter: () => Promise.resolve({ data: cached, status: 200, statusText: 'OK', headers, config }),
      } as typeof config
    }
  }

  return config
})

client.interceptors.response.use(
  (response) => {
    // Record API timing
    const startTime = (response.config as { metadata?: { startTime?: number } }).metadata?.startTime
    if (startTime && response.config.url) {
      recordApiTiming(
        response.config.method?.toUpperCase() || 'GET',
        response.config.url,
        Date.now() - startTime,
        response.status,
      )
    }

    if (response.config.method === 'get' && response.config.url) {
      const cacheKey = buildCacheKey(response.config.url, response.config.params || {})
      setCache(cacheKey, response.data)
    } else if (response.config.url) {
      invalidateForMutation(response.config.url)
    }
    return response
  },
  (error) => {
    logAxiosError('Response Interceptor', error)

    if (error.response?.status === 401) {
      const publicPaths = ['/login', '/register', '/invite/', '/forgot-password']
      const onPublicPage = publicPaths.some(p => window.location.pathname.startsWith(p))
      if (!onPublicPage) {
        clearRequestCache()
        localStorage.removeItem('auth_token')
        localStorage.removeItem('auth_user')
        localStorage.removeItem('auth_validated_at')
        // Await the IndexedDB wipe before the hard redirect: queued offline
        // writes persist the (now dead) bearer token, and cached responses
        // must not leak into the next session.
        return clearAllData()
          .catch(() => {})
          .then(() => {
            window.location.href = '/login'
            return Promise.reject(error)
          })
      }
    }
    if (error.response?.status === 429) {
      const retryCount = error.config?._retryCount || 0
      if (retryCount >= 3) {
        return Promise.reject(error)
      }
      error.config._retryCount = retryCount + 1
      console.warn(`Rate limited — retrying in 2s (attempt ${retryCount + 1}/3)`)
      return new Promise((resolve) =>
        setTimeout(() => resolve(client.request(error.config)), 2000),
      )
    }
    return Promise.reject(error)
  },
)

/**
 * Wipe all in-memory request cache entries. Called on logout and on 401 so
 * the next account never reads another user's cached API responses.
 */
export function clearRequestCache(): void {
  invalidateCache()
}

export default client
