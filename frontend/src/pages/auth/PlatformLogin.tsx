import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Shield, Eye, EyeOff, Loader2 } from 'lucide-react'
import client from '@/api/client'
import type { AxiosError } from 'axios'

export default function PlatformLogin() {
  const { platformLogin, isAuthenticated, user } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)

  if (isAuthenticated && user) {
    if (user.role === 'platform_admin') return <Navigate to="/platform" replace />
    return <Navigate to="/login" replace />
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    try {
      const baseUrl = client.defaults.baseURL
      const fullPath = `${baseUrl}/auth/${import.meta.env.VITE_PLATFORM_ADMIN_LOGIN_PATH || 'platform-secure-admin-login'}`
      console.group('[DEBUG] Platform Login Request')
      console.log('Base URL:', baseUrl)
      console.log('Full Path:', fullPath)
      console.log('Auth endpoint:', `auth/${import.meta.env.VITE_PLATFORM_ADMIN_LOGIN_PATH || 'platform-secure-admin-login'}`)
      console.log('Method: POST')
      console.log('Payload:', { email, password: '***' })
      console.log('VITE_API_URL:', import.meta.env.VITE_API_URL)
      console.log('VITE_PLATFORM_ADMIN_LOGIN_PATH:', import.meta.env.VITE_PLATFORM_ADMIN_LOGIN_PATH)
      console.groupEnd()

      await platformLogin({ email, password })
      navigate('/platform')
    } catch (err: unknown) {
      const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
      console.group('[DEBUG] Platform Login Error')
      console.error('Full error:', axiosErr)
      console.error('Response data:', axiosErr?.response?.data)
      console.error('Response status:', axiosErr?.response?.status)
      console.error('Response headers:', axiosErr?.response?.headers)
      console.error('Request URL:', axiosErr?.config?.url)
      console.error('Request method:', axiosErr?.config?.method)
      console.error('Request baseURL:', axiosErr?.config?.baseURL)
      console.error('Request data:', axiosErr?.config?.data)
      console.groupEnd()

      const msg = axiosErr?.response?.data?.errors
        ? Object.values(axiosErr.response.data.errors).flat().join('. ')
        : axiosErr?.response?.data?.message || t('auth.loginFailed')
      toast.error(msg)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md">
        <div className="card p-8">
          <div className="mb-6 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-900/50">
              <Shield className="h-7 w-7 text-amber-600 dark:text-amber-400" />
            </div>
            <h1 className="mt-4 text-2xl font-bold">{t('platform.loginTitle')}</h1>
            <p className="mt-1 text-sm text-secondary">{t('platform.loginSubtitle')}</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">{t('auth.email')}</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="input-field" placeholder={t('auth.platformEmailPlaceholder')} />
            </div>
            <div>
              <label className="label">{t('auth.password')}</label>
              <div className="relative">
                <input type={showPassword ? 'text' : 'password'} value={password} onChange={(e) => setPassword(e.target.value)} required className="input-field pr-10" placeholder={t('auth.passwordPlaceholder')} />
                <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary" tabIndex={-1}>
                  {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                </button>
              </div>
            </div>
            <button type="submit" disabled={loading} className="btn-primary btn-md w-full !bg-amber-600 hover:!bg-amber-700">
              {loading && <Loader2 className="h-5 w-5 animate-spin" />}
              {loading ? t('common.signingIn') : t('auth.signIn')}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}