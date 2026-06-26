import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Church, Eye, EyeOff, Loader2 } from 'lucide-react'
import PublicLayout from '@/components/layout/PublicLayout'
import client from '@/api/client'

export default function Login() {
  const { login, isAuthenticated, user } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)

  const roleRedirect: Record<string, string> = {
    platform_admin: '/platform',
    admin: '/admin',
    assistant_admin: '/assistant-admin',
    servant: '/servant',
    member: '/member',
  }

  if (isAuthenticated && user) {
    if (user.application_status === 'pending') return <Navigate to="/pending" replace />
    if (user.application_status === 'rejected') return <Navigate to="/rejected" replace />
    return <Navigate to={roleRedirect[user.role] || '/login'} replace />
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    try {
      const loggedInUser = await login({ email, password })
      if (loggedInUser.application_status === 'pending') { toast.success(t('auth.loginPendingInfo')); navigate('/pending'); return }
      if (loggedInUser.application_status === 'rejected') { navigate('/rejected'); return }
      navigate(roleRedirect[loggedInUser.role] || '/login')
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const msg = axiosErr?.response?.data?.errors
        ? Object.values(axiosErr.response.data.errors).flat().join('. ')
        : axiosErr?.response?.data?.message || t('auth.loginFailed')

      if (msg?.toLowerCase().includes('verify')) {
        toast((_: { id: string }) => (
          <div className="flex flex-col gap-2">
            <span>{msg}</span>
            <button onClick={() => { client.post('/auth/resend-verification', { email }).then(() => toast.success(t('auth.verificationSent'))).catch(() => toast.error(t('auth.verificationFailed'))) }} className="btn-primary btn-xs w-full">
              {t('auth.resendVerification')}
            </button>
          </div>
        ), { duration: 8000 })
      } else {
        toast.error(msg)
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <PublicLayout showFooter={false}>
      <div className="relative flex min-h-[calc(100vh-4rem)] items-center justify-center overflow-hidden px-4 py-12">
        {/* Background effects */}
        <div className="absolute inset-0 bg-gradient-to-br from-navy-900/5 via-transparent to-gold-500/5" />
        <div className="absolute top-1/3 left-1/4 w-72 h-72 rounded-full bg-gold-400/5 blur-3xl" />
        <div className="absolute bottom-1/3 right-1/4 w-96 h-96 rounded-full bg-navy-400/5 blur-3xl" />

        <div className="relative w-full max-w-md animate-fade-in-up">
          <div className="glass rounded-2xl p-8 sm:p-10">
            <div className="mb-6 text-center">
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl gold-gradient shadow-xl mb-4">
                <Church className="h-8 w-8 text-navy-900" />
              </div>
              <h1 className="text-2xl font-bold gold-text">{t('app.name')}</h1>
              <p className="mt-1 text-sm text-secondary">{t('auth.signInTo')}</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
              <div className="floating-input-group">
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  className="input-field"
                  placeholder=" "
                />
                <label>{t('auth.email')}</label>
              </div>

              <div className="floating-input-group">
                <div className="relative">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    className="input-field pr-12"
                    placeholder=" "
                  />
                  <label className="!top-3">{t('auth.password')}</label>
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 end-0 flex items-center pe-4 text-muted hover:text-secondary"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
              </div>

              <div className="flex items-center justify-between">
                <Link to="/forgot-password" className="text-sm gold-text hover:underline font-medium">
                  {t('auth.forgotPassword')}
                </Link>
              </div>

              <button type="submit" disabled={loading} className="btn-gold btn-md w-full text-base py-3">
                {loading && <Loader2 className="h-5 w-5 animate-spin" />}
                {loading ? t('common.loading') : t('auth.signIn')}
              </button>

              <p className="text-center text-sm text-secondary">
                {t('auth.noAccount')}{' '}
                <Link to="/join" className="gold-text hover:underline font-semibold">
                  {t('landing.heroCta')}
                </Link>
              </p>
            </form>
          </div>
        </div>
      </div>
    </PublicLayout>
  )
}
