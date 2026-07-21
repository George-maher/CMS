import { useTranslation } from 'react-i18next'
import { Share2, ArrowUpFromLine, Plus } from 'lucide-react'

export default function IOSInstallGuide() {
  const { t, i18n } = useTranslation()
  const isRtl = i18n.dir() === 'rtl'

  const steps = [
    { icon: Share2, text: t('installApp.iosStep1') },
    { icon: ArrowUpFromLine, text: t('installApp.iosStep2') },
    { icon: Plus, text: t('installApp.iosStep3') },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-center gap-6 py-3">
        {steps.map((step, i) => (
          <div key={i} className="flex flex-col items-center gap-2">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-50 dark:bg-primary-900/20">
              <step.icon className="h-6 w-6 text-primary-500" />
            </div>
            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-[10px] font-bold text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">
              {i + 1}
            </span>
            <p className="text-[11px] text-center text-secondary max-w-[100px] leading-tight">
              {step.text}
            </p>
          </div>
        ))}
      </div>
      <div className={`flex items-center gap-2 rounded-xl bg-primary-50/50 p-3 text-xs text-secondary dark:bg-primary-900/10 ${isRtl ? 'flex-row-reverse' : ''}`}>
        <Share2 className="h-4 w-4 shrink-0 text-primary-400" />
        <span>{t('installApp.iosStep1')} → {t('installApp.iosStep2')} → {t('installApp.iosStep3')}</span>
      </div>
    </div>
  )
}
