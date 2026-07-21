import { Share2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'

export default function IOSInstallGuide() {
  const { t } = useTranslation()

  return (
    <div className="card p-4">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-secondary">
        <Share2 className="h-4 w-4" />
        {t('installApp.iosTitle', 'Install on iOS')}
      </h3>
      <ol className="space-y-2 text-sm text-secondary">
        <li className="flex items-start gap-2">
          <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">1</span>
          <span>{t('installApp.iosStep1', 'Tap the Share button')} <Share2 className="inline h-3.5 w-3.5 text-primary-400" /></span>
        </li>
        <li className="flex items-start gap-2">
          <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">2</span>
          <span>{t('installApp.iosStep2', 'Scroll down and tap "Add to Home Screen"')}</span>
        </li>
        <li className="flex items-start gap-2">
          <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">3</span>
          <span>{t('installApp.iosStep3', 'Tap "Add" in the top-right corner')}</span>
        </li>
      </ol>
    </div>
  )
}
