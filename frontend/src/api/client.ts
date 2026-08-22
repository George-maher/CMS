import axios from 'axios'
import { logAxiosError } from '@/lib/debug'
import { addToSyncQueue } from '@/lib/db'

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

const OFFLINE_WRITABLE_PATTERNS = [
  /\/api\/v1\/attendance$/,
  /\/api\/v1\/attendance\/bulk$/,
  /\/api\/v1\/attendance\/scan$/,
]

client.interceptors.request.use(async (config) => {
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

  return config
})

client.interceptors.response.use(
  (response) => response,
  (error) => {
    logAxiosError('Response Interceptor', error)

    if (error.response?.status === 401) {
      const publicPaths = ['/login', '/register', '/invite/', '/forgot-password']
      const onPublicPage = publicPaths.some(p => window.location.pathname.startsWith(p))
      if (!onPublicPage) {
        localStorage.removeItem('auth_token')
        localStorage.removeItem('auth_user')
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

export default client
