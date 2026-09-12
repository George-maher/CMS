import axios from 'axios'
import { logAxiosError } from '@/lib/debug'
import { addToSyncQueue } from '@/lib/db'
import { getCached, setCache, isStale, getInflight, setInflight, invalidateCache } from '@/lib/requestCache'
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
      const token = config.headers?.Authorization?.toString().replace('Bearer ', '') || ''
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
        const bgPromise = networkClient.get(config.url, { params: config.params, headers: bgHeaders })
          .then((res) => {
            setCache(cacheKey, res.data)
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
      const url = response.config.url
      if (url.includes('/users')) invalidateCache('/users')
      if (url.includes('/attendances')) invalidateCache('/attendances')
      if (url.includes('/events')) invalidateCache('/events')
      if (url.includes('/notifications')) invalidateCache('/notifications')
      if (url.includes('/password-reset-requests')) invalidateCache('/password-reset-requests')
      if (url.includes('/profile-update-requests')) invalidateCache('/profile-update-requests')
      if (url.includes('/feedback')) invalidateCache('/feedback')
      if (url.includes('/points')) invalidateCache('/points')
      if (url.includes('/leaderboard')) invalidateCache('/leaderboard')
      if (url.includes('/dashboard')) invalidateCache('/dashboard')
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
        window.location.href = '/login'
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
