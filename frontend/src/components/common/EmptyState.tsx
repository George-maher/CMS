import { Inbox } from 'lucide-react'
import { useTranslation } from 'react-i18next'

interface Props {
  message?: string
  actionLabel?: string
  onAction?: () => void
  icon?: React.ReactNode
}

export default function EmptyState({ message, actionLabel, onAction, icon }: Props) {
  const { t } = useTranslation()

  return (
    <div className="empty-state">
      <div className="empty-state-icon">
        {icon ?? <Inbox className="w-full h-full" />}
      </div>
      <p className="empty-state-title">{t('common.noData')}</p>
      <p className="empty-state-text">{message}</p>
      {actionLabel && onAction && (
        <button onClick={onAction} className="btn-primary btn-md mt-6">
          {actionLabel}
        </button>
      )}
    </div>
  )
}
