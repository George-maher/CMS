import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, RefreshCw } from 'lucide-react'

export default function ServerError() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md text-center">
        <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl bg-red-50 dark:bg-red-900/20">
          <AlertTriangle className="h-10 w-10 text-red-500" />
        </div>
        <h1 className="text-7xl font-bold text-muted">500</h1>
        <h2 className="mt-4 text-xl font-semibold">{t('errors.serverError')}</h2>
        <p className="mt-2 text-sm text-secondary">{t('errors.serverErrorMessage')}</p>
        <button onClick={() => navigate('/')} className="btn-primary btn-md">
          <RefreshCw className="h-4 w-4" /> {t('errors.reload')}
        </button>
      </div>
    </div>
  )
}
