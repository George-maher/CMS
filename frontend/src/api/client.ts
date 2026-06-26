import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || '/api'

const client = axios.create({
  baseURL: `${API_URL}/v1`,
  headers: { Accept: 'application/json' },
})

client.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const isInitialAuth = error.config?.url?.includes('/auth/me')
      if (!isInitialAuth) {
        const publicPaths = ['/login', '/register', '/invite/']
        const onPublicPage = publicPaths.some(p => window.location.pathname.startsWith(p))
        if (!onPublicPage) {
          localStorage.removeItem('auth_token')
          localStorage.removeItem('auth_user')
          window.location.href = '/login'
        }
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
