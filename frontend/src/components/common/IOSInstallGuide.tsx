import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { X, Share2, ArrowDown, Plus, Check, Smartphone } from 'lucide-react'

interface Props {
  open: boolean
  onClose: () => void
}

const steps = [
  { key: 'pwa.iosStep1' },
  { key: 'pwa.iosStep2' },
  { key: 'pwa.iosStep3' },
  { key: 'pwa.iosStep4' },
]

const stepIcons = [Share2, ArrowDown, Plus, Check]

export default function IOSInstallGuide({ open, onClose }: Props) {
  const { t } = useTranslation()
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', handler)
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', handler)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  useEffect(() => {
    if (open) setTimeout(() => closeRef.current?.focus(), 100)
  }, [open])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm animate-fade-in" onClick={onClose} aria-hidden="true" />

      <div
        className="relative w-full max-w-sm bg-white dark:bg-[#1c1c1e] rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl shadow-black/30 overflow-hidden animate-slide-up"
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 16px)' }}
      >
        <div className="flex justify-center pt-2 pb-1">
          <div className="w-9 h-1 rounded-full bg-gray-300 dark:bg-gray-600" />
        </div>

        <button
          ref={closeRef}
          onClick={onClose}
          className="absolute top-3 end-3 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
          aria-label={t('common.close')}
        >
          <X className="h-4 w-4" />
        </button>

        <div className="px-6 pt-4 pb-6">
          <div className="flex flex-col items-center text-center">
            <div className="w-20 h-20 rounded-[1.25rem] bg-gradient-to-br from-[#d4af37] to-[#c5a028] shadow-xl shadow-[#d4af37]/25 flex items-center justify-center ring-2 ring-white/10 mb-5">
              <Smartphone className="w-11 h-11 text-[#0f172a]" />
            </div>

            <h2 className="text-xl font-bold text-gray-900 dark:text-white">{t('pwa.iosInstallTitle')}</h2>
            <p className="mt-1.5 text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">{t('pwa.iosInstallDescription')}</p>
          </div>

          <div className="mt-6 space-y-3">
            {steps.map((step, i) => {
              const Icon = stepIcons[i]!
              return (
                <div key={i} className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#d4af37]/10 dark:bg-[#d4af37]/15 text-xs font-bold text-[#d4af37]">{i + 1}</div>
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Icon className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">{t(step.key)}</p>
                </div>
              )
            })}
          </div>

          <div className="mt-6 flex flex-col items-center gap-2">
            <button
              onClick={onClose}
              className="w-full rounded-xl bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-navy-900 font-semibold py-3 px-4 shadow-lg shadow-[#d4af37]/25 hover:shadow-xl hover:shadow-[#d4af37]/30 transition-all active:scale-[0.97]"
            >
              <Check className="h-4 w-4 inline mr-1.5" />
              {t('pwa.iosInstalledButton')}
            </button>
            <button onClick={onClose} className="text-sm font-medium text-gray-400 hover:text-gray-300 transition-colors py-1">
              {t('common.cancel')}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
