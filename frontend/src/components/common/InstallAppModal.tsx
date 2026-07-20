import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import {
  X, Share2, Plus, Check, ArrowDown,
  Smartphone, Globe, Monitor, Chrome,
} from 'lucide-react'
import type { ModalType } from '@/hooks/usePwaInstall'

interface InstallAppModalProps {
  open: boolean
  type: ModalType
  onClose: () => void
  onInstalled: () => void
}

const iosSteps = [
  { icon: Share2, key: 'pwa.iosStep1' },
  { icon: ArrowDown, key: 'pwa.iosStep2' },
  { icon: Plus, key: 'pwa.iosStep3' },
  { icon: Check, key: 'pwa.iosStep4' },
]

const androidSteps = [
  { icon: Smartphone, key: 'pwa.androidStep1' },
  { icon: Globe, key: 'pwa.androidStep2' },
  { icon: Plus, key: 'pwa.androidStep3' },
  { icon: Check, key: 'pwa.androidStep4' },
]

export default function InstallAppModal({ open, type, onClose, onInstalled }: InstallAppModalProps) {
  const { t } = useTranslation()
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', handler)
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', handler)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  useEffect(() => {
    if (open) {
      setTimeout(() => closeRef.current?.focus(), 100)
    }
  }, [open])

  if (!open || !type) return null

  const isIOS = type === 'ios'
  const isAndroid = type === 'android'
  const isNotInstallable = type === 'not_installable'

  const title = isIOS
    ? t('pwa.iosInstallTitle')
    : isAndroid
      ? t('pwa.androidInstallTitle')
      : t('pwa.notInstallableTitle')

  const description = isIOS
    ? t('pwa.iosInstallDescription')
    : isAndroid
      ? t('pwa.androidInstallDescription')
      : t('pwa.notInstallableDescription')

  const steps = isIOS ? iosSteps : androidSteps

  return (
    <div
      className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm animate-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Sheet */}
      <div
        className="relative w-full max-w-sm bg-white dark:bg-[#1c1c1e] rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl shadow-black/30 overflow-hidden animate-slide-up max-h-[90vh] flex flex-col"
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 16px)' }}
      >
        {/* Drag Handle */}
        <div className="flex justify-center pt-2 pb-1 shrink-0">
          <div className="w-9 h-1 rounded-full bg-gray-300 dark:bg-gray-600" />
        </div>

        {/* Close button */}
        <button
          ref={closeRef}
          onClick={onClose}
          className="absolute top-3 end-3 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
          aria-label={t('common.close')}
        >
          <X className="h-4 w-4" />
        </button>

        {/* Scrollable body */}
        <div className="flex-1 overflow-y-auto px-6 pt-4 pb-2">
          {/* App Icon */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-5">
              <div className="w-20 h-20 rounded-[1.25rem] bg-gradient-to-br from-[#d4af37] to-[#c5a028] shadow-xl shadow-[#d4af37]/25 flex items-center justify-center ring-2 ring-white/10">
                <svg viewBox="0 0 24 24" className="w-11 h-11 text-[#0f172a]" fill="currentColor">
                  <rect x="6" y="11" width="12" height="10" rx="1" opacity="0.9" />
                  <polygon points="12,4 4,11 20,11" />
                  <rect x="11" y="2" width="2" height="5" rx="0.5" />
                  <rect x="9" y="9" width="6" height="12" rx="3" opacity="0.4" />
                </svg>
              </div>
            </div>

            <h2 className="text-xl font-bold text-gray-900 dark:text-white">
              {title}
            </h2>
            <p className="mt-1.5 text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">
              {description}
            </p>
          </div>

          {/* Steps or Not-installable content */}
          <div className="mt-7 space-y-3">
            {!isNotInstallable && steps.map((step, i) => {
              const Icon = step.icon
              return (
                <div
                  key={i}
                  className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]"
                >
                  <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#d4af37]/10 dark:bg-[#d4af37]/15 text-xs font-bold text-[#d4af37]">
                    {i + 1}
                  </div>
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Icon className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                    {t(step.key)}
                  </p>
                </div>
              )
            })}

            {isNotInstallable && (
              <div className="space-y-3">
                <div className="flex items-start gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-amber-500">
                    <Monitor className="h-5 w-5" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-800 dark:text-gray-200">
                      {t('pwa.notInstallableDesktop')}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                      {t('pwa.notInstallableDesktopDesc')}
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-blue-500">
                    <Chrome className="h-5 w-5" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-800 dark:text-gray-200">
                      {t('pwa.notInstallableChrome')}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                      {t('pwa.notInstallableChromeDesc')}
                    </p>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="shrink-0 px-6 pt-3 pb-5 space-y-3">
          {!isNotInstallable ? (
            <button
              onClick={onInstalled}
              className="w-full py-3 px-6 rounded-2xl text-sm font-semibold transition-all duration-200 active:scale-[0.98] gold-gradient text-navy-900 shadow-lg shadow-[#d4af37]/30 hover:shadow-xl hover:shadow-[#d4af37]/40 hover:brightness-110"
            >
              <Check className="h-4 w-4 shrink-0 inline me-2" />
              {t('pwa.iosInstalledButton')}
            </button>
          ) : (
            <button
              onClick={onClose}
              className="w-full py-3 px-6 rounded-2xl text-sm font-semibold transition-all duration-200 active:scale-[0.98] bg-gray-200 dark:bg-[#2c2c2e] text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-[#3a3a3c]"
            >
              {t('common.gotIt')}
            </button>
          )}

          <div className="flex items-center justify-center gap-3">
            <button
              onClick={onClose}
              className="text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
              {t('pwa.remindLater')}
            </button>
            <span className="text-[10px] text-gray-300 dark:text-gray-600">|</span>
            <button
              onClick={onInstalled}
              className="text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
              {t('pwa.dontShowAgain')}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
