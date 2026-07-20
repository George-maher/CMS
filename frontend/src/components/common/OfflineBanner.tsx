import { WifiOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'

interface OfflineBannerProps {
  isOnline: boolean
  pendingCount?: number
}

export default function OfflineBanner({ isOnline, pendingCount = 0 }: OfflineBannerProps) {
  const { t } = useTranslation()

  if (isOnline) {
    if (pendingCount > 0) {
      return (
        <div className="flex items-center justify-center gap-2 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-white">
          <WifiOff className="h-4 w-4" />
          <span>
            {t('offline.pendingSync', '{{count}} change(s) waiting to sync', { count: pendingCount })}
          </span>
        </div>
      )
    }
    return null
  }

  return (
    <div className="flex items-center justify-center gap-2 bg-red-500 px-4 py-2 text-center text-sm font-medium text-white">
      <WifiOff className="h-4 w-4" />
      <span>
        {t('offline.offline', 'You are offline. Changes will be saved and synced when you reconnect.')}
      </span>
    </div>
  )
}
