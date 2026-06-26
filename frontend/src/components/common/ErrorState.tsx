import { AlertTriangle } from 'lucide-react'
import { useTranslation } from 'react-i18next'

interface Props {
  message?: string
  onRetry?: () => void
}

export default function ErrorState({ message, onRetry }: Props) {
  const { t } = useTranslation()

  return (
    <div className="error-state">
      <div className="w-16 h-16 mb-4 text-danger">
        <AlertTriangle className="w-full h-full" />
      </div>
      <p className="text-lg font-semibold mb-1">{t('errors.serverError')}</p>
      <p className="text-sm text-secondary max-w-xs">{message || t('errors.serverErrorMessage')}</p>
      {onRetry && (
        <button onClick={onRetry} className="btn-primary btn-md mt-6">
          {t('common.retry')}
        </button>
      )}
    </div>
  )
}
