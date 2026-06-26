import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { FileQuestion, Home } from 'lucide-react'

export default function NotFound() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md text-center">
        <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl bg-amber-50 dark:bg-amber-900/20">
          <FileQuestion className="h-10 w-10 text-amber-500" />
        </div>
        <h1 className="text-7xl font-bold text-muted">404</h1>
        <h2 className="mt-4 text-xl font-semibold">{t('errors.notFound')}</h2>
        <p className="mt-2 text-sm text-secondary">{t('errors.notFoundMessage')}</p>
        <button onClick={() => navigate('/')} className="btn-primary btn-md">
          <Home className="h-4 w-4" /> {t('errors.goHome')}
        </button>
      </div>
    </div>
  )
}
