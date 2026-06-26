import { useState, useEffect, type FormEvent } from 'react'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Church, Eye, EyeOff, Loader2, ArrowLeft, AlertCircle } from 'lucide-react'
import * as authApi from '@/api/auth'

export default function ResetPassword() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const emailParam = searchParams.get('email') || ''
  const tokenParam = searchParams.get('token') || ''

  const [email] = useState(emailParam)
  const [token] = useState(tokenParam)
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [success, setSuccess] = useState(false)
  const [passwordError, setPasswordError] = useState('')
  const [confirmError, setConfirmError] = useState('')

  const validatePassword = (value: string): string => {
    if (!value) return t('auth.passwordRequired') || 'Password is required.'
    if (value.length < 8) return t('auth.passwordMinLength')
    return ''
  }

  const validateConfirm = (confirm: string, pw: string): string => {
    if (!confirm) return t('auth.confirmPasswordRequired') || 'Confirm password is required.'
    if (confirm !== pw) return t('auth.passwordsDoNotMatch')
    return ''
  }

  const handlePasswordChange = (value: string) => {
    setPassword(value)
    if (passwordError) setPasswordError(validatePassword(value))
    if (confirmPassword) setConfirmError(validateConfirm(confirmPassword, value))
  }

  const handleConfirmChange = (value: string) => {
    setConfirmPassword(value)
    if (confirmError || value) setConfirmError(validateConfirm(value, password))
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()

    if (!token || !email) {
      toast.error(t('auth.invalidVerificationLink'))
      return
    }

    const pwErr = validatePassword(password)
    const confErr = validateConfirm(confirmPassword, password)
    setPasswordError(pwErr)
    setConfirmError(confErr)
    if (pwErr || confErr) return

    setLoading(true)
    try {
      await authApi.resetPassword({
        email,
        token,
        password,
        password_confirmation: confirmPassword,
      })
      setSuccess(true)
      toast.success(t('auth.resetPasswordSuccess'))
      setTimeout(() => navigate('/login'), 3000)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const msg = axiosErr?.response?.data?.errors
        ? Object.values(axiosErr.response.data.errors).flat().join(', ')
        : axiosErr?.response?.data?.message || t('auth.resetPasswordFailed')
      toast.error(msg)
    } finally {
      setLoading(false)
    }
  }

  if (!token || !email) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <div className="w-full max-w-md">
          <div className="card p-8 text-center space-y-4">
            <AlertCircle className="h-12 w-12 mx-auto text-red-500" />
            <p className="text-lg font-medium">{t('auth.invalidVerificationLink')}</p>
            <p className="text-sm text-secondary">{t('auth.forgotPasswordDescription')}</p>
            <Link to="/forgot-password" className="btn-primary btn-md inline-flex items-center gap-2">
              <ArrowLeft className="h-4 w-4" />
              {t('auth.forgotPassword')}
            </Link>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md">
        <div className="card p-8">
          <div className="mb-6 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-primary-100 dark:bg-primary-900/50">
              <Church className="h-7 w-7 text-primary-600 dark:text-primary-400" />
            </div>
            <h1 className="mt-4 text-2xl font-bold">{t('app.name')}</h1>
            <p className="mt-1 text-sm text-secondary">{t('auth.resetPasswordTitle')}</p>
          </div>

          {success ? (
            <div className="text-center space-y-4">
              <p className="text-sm text-secondary">{t('auth.resetPasswordSuccess')}</p>
              <Link to="/login" className="btn-primary btn-md inline-flex items-center gap-2">
                <ArrowLeft className="h-4 w-4" />
                {t('auth.signIn')}
              </Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
              <div>
                <div className="rounded-lg bg-surface-secondary p-3 text-sm text-secondary break-all">
                  <span className="font-medium">{t('auth.email')}: </span>{email}
                </div>
              </div>
              <div>
                <label className="label">{t('auth.password')}</label>
                <div className="relative">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => handlePasswordChange(e.target.value)}
                    onBlur={() => setPasswordError(validatePassword(password))}
                    required
                    minLength={8}
                    className={`input-field pr-10${passwordError ? ' border-red-500 focus:border-red-500 focus:ring-red-500/30' : ''}`}
                    placeholder={t('auth.passwordPlaceholder')}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
                {passwordError && (
                  <p className="mt-1 text-xs text-red-500 flex items-center gap-1">
                    <AlertCircle className="h-3 w-3 shrink-0" />
                    {passwordError}
                  </p>
                )}
              </div>
              <div>
                <label className="label">{t('auth.confirmPassword')}</label>
                <div className="relative">
                  <input
                    type={showConfirmPassword ? 'text' : 'password'}
                    value={confirmPassword}
                    onChange={(e) => handleConfirmChange(e.target.value)}
                    onBlur={() => setConfirmError(validateConfirm(confirmPassword, password))}
                    required
                    minLength={8}
                    className={`input-field pr-10${confirmError ? ' border-red-500 focus:border-red-500 focus:ring-red-500/30' : ''}`}
                    placeholder={t('auth.confirmPasswordPlaceholder')}
                  />
                  <button
                    type="button"
                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary"
                    tabIndex={-1}
                  >
                    {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
                {confirmError && (
                  <p className="mt-1 text-xs text-red-500 flex items-center gap-1">
                    <AlertCircle className="h-3 w-3 shrink-0" />
                    {confirmError}
                  </p>
                )}
              </div>
              <button type="submit" disabled={loading} className="btn-primary btn-md w-full">
                {loading && <Loader2 className="h-5 w-5 animate-spin" />}
                {loading ? t('common.loading') : t('auth.resetPassword')}
              </button>
              <div className="text-center">
                <Link to="/login" className="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400">
                  <ArrowLeft className="mr-1 inline-block h-4 w-4" />
                  {t('auth.backToLogin')}
                </Link>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )}