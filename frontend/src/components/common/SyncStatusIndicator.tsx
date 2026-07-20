import { RefreshCw, Upload } from 'lucide-react'
import { useTranslation } from 'react-i18next'

interface SyncStatusIndicatorProps {
  isSyncing: boolean
  pendingCount: number
  onSync: () => void
}

export default function SyncStatusIndicator({ isSyncing, pendingCount, onSync }: SyncStatusIndicatorProps) {
  const { t } = useTranslation()

  if (pendingCount === 0 && !isSyncing) return null

  return (
    <button
      onClick={onSync}
      disabled={isSyncing}
      className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed"
      title={t('sync.syncNow', 'Sync now')}
    >
      {isSyncing ? (
        <>
          <RefreshCw className="h-3.5 w-3.5 animate-spin text-primary-500" />
          <span className="text-primary-600 dark:text-primary-400">
            {t('sync.syncing', 'Syncing...')}
          </span>
        </>
      ) : pendingCount > 0 ? (
        <>
          <Upload className="h-3.5 w-3.5 text-amber-500" />
          <span className="text-amber-600 dark:text-amber-400">
            {t('sync.pending', '{{count}} pending', { count: pendingCount })}
          </span>
        </>
      ) : null}
    </button>
  )
}
