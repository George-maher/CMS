import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Church, Loader2, ArrowLeft, Mail, AlertCircle, MessageSquare } from 'lucide-react'
import { submitPasswordResetRequest } from '@/api/passwordResetRequests'

export default function ForgotPassword() {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [notes, setNotes] = useState('')
  const [emailError, setEmailError] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)

  const validateEmail = (value: string): string => {
    if (!value.trim()) return t('auth.emailRequired') || 'Email is required.'
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      return t('auth.emailInvalid') || 'Please enter a valid email address.'
    }
    return ''
  }

  const handleEmailChange = (value: string) => {
    setEmail(value)
    if (emailError) setEmailError(validateEmail(value))
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    const err = validateEmail(email)
    if (err) {
      setEmailError(err)
      return
    }
    setLoading(true)
    try {
      await submitPasswordResetRequest({ email, notes: notes.trim() || undefined })
      setSent(true)
      toast.success(t('auth.forgotPasswordSent'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || t('auth.forgotPasswordFailed')
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
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-primary-100 dark:bg-primary-900/50">
              <Church className="h-7 w-7 text-primary-600 dark:text-primary-400" />
            </div>
            <h1 className="mt-4 text-2xl font-bold">{t('app.name')}</h1>
            <p className="mt-1 text-sm text-secondary">{t('auth.forgotPasswordTitle')}</p>
          </div>

          {sent ? (
            <div className="text-center space-y-4">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success-light">
                <Mail className="h-7 w-7 text-success-dark" />
              </div>
              <p className="text-sm text-secondary">{t('auth.forgotPasswordDescription')}</p>
              <Link to="/login" className="btn-secondary btn-md inline-flex items-center gap-2">
                <ArrowLeft className="h-4 w-4" />
                {t('auth.backToLogin')}
              </Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
              <div>
                <label className="label">{t('auth.email')}</label>
                <div className="relative">
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => handleEmailChange(e.target.value)}
                    onBlur={() => setEmailError(validateEmail(email))}
                    required
                    className={`input-field${emailError ? ' border-red-500 focus:border-red-500 focus:ring-red-500/30' : ''}`}
                    placeholder={t('auth.emailPlaceholder')}
                  />
                  {emailError && (
                    <div className="absolute inset-y-0 right-0 flex items-center pr-3">
                      <AlertCircle className="h-4 w-4 text-red-500" />
                    </div>
                  )}
                </div>
                {emailError && (
                  <p className="mt-1 text-xs text-red-500 flex items-center gap-1">
                    <AlertCircle className="h-3 w-3 shrink-0" />
                    {emailError}
                  </p>
                )}
              </div>

              <div>
                <label className="label">{t('auth.optionalNote')}</label>
                <div className="relative">
                  <textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    className="input-field min-h-[80px] resize-none"
                    placeholder={t('auth.optionalNotePlaceholder')}
                    rows={3}
                    maxLength={1000}
                  />
                  <div className="absolute bottom-2 right-3">
                    <MessageSquare className="h-4 w-4 text-muted" />
                  </div>
                </div>
                <p className="mt-1 text-xs text-muted text-right">{notes.length}/1000</p>
              </div>

              <button type="submit" disabled={loading} className="btn-primary btn-md w-full">
                {loading && <Loader2 className="h-5 w-5 animate-spin" />}
                {loading ? t('common.loading') : t('auth.forgotPassword')}
              </button>
              <div className="text-center">
                <Link to="/login" className="text-sm gold-text hover:opacity-80">
                  <ArrowLeft className="mr-1 inline-block h-4 w-4" />
                  {t('auth.backToLogin')}
                </Link>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
