import { useEffect, useState } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import client from '@/api/client'
export default function VerifyEmail() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const [status, setStatus] = useState<'loading' | 'success' | 'error'>('loading')
  const [message, setMessage] = useState('')

  useEffect(() => {
    const token = searchParams.get('token')
    const email = searchParams.get('email')

    if (!token || !email) {
      setMessage(t('auth.invalidVerificationLink'))
      setStatus('error')
      return
    }

    client.post('/auth/verify-email', { token, email })
      .then(() => {
        setStatus('success')
        setMessage(t('auth.emailVerified'))
        setTimeout(() => navigate('/login'), 3000)
      })
      .catch((err: unknown) => {
        const axiosErr = err as { response?: { data?: { message?: string } } }
        setMessage(axiosErr?.response?.data?.message || t('auth.verificationFailed'))
        setStatus('error')
      })
  }, [searchParams, navigate])

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md">
        <div className="card p-8 text-center">
          {status === 'loading' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary-light">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
              </div>
              <h2 className="text-lg font-semibold">{t('auth.verifyingEmail')}</h2>
            </>
          )}

          {status === 'success' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-success-light">
                <CheckCircle className="h-8 w-8 text-success" />
              </div>
              <h2 className="text-lg font-semibold">{t('common.confirm')}</h2>
              <p className="mt-2 text-sm text-secondary">{message}</p>
              <p className="mt-1 text-xs text-muted">{t('auth.redirectToLogin')}</p>
            </>
          )}

          {status === 'error' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-danger-light">
                <XCircle className="h-8 w-8 text-danger" />
              </div>
              <h2 className="text-lg font-semibold">{t('errors.forbidden')}</h2>
              <p className="mt-2 text-sm text-secondary">{message}</p>
              <button onClick={() => navigate('/login')} className="btn-primary btn-md mt-6">
                {t('errors.goLogin')}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
