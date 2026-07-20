import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import {
  X, Share2, Plus, Check, ArrowDownToLine,
  Smartphone, Monitor, Globe, Chrome,
} from 'lucide-react'
import type { ModalType } from '@/hooks/usePwaInstall'

interface InstallAppModalProps {
  open: boolean
  type: ModalType
  onClose: () => void
  onInstalled: () => void
}

const iosSteps = [
  { icon: Share2, labelKey: 'pwa.iosStep1' },
  { icon: ArrowDownToLine, labelKey: 'pwa.iosStep2' },
  { icon: Plus, labelKey: 'pwa.iosStep3' },
  { icon: Check, labelKey: 'pwa.iosStep4' },
]

const iosIcons = [Share2, ArrowDownToLine, Plus, Check]

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

  const title = type === 'ios' ? t('pwa.iosInstallTitle') :
    type === 'android' ? t('pwa.androidInstallTitle') :
    t('pwa.notInstallableTitle')

  const description = type === 'ios' ? t('pwa.iosInstallDescription') :
    type === 'android' ? t('pwa.androidInstallDescription') :
    t('pwa.notInstallableDescription')

  return (
    <div
      className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <div
        className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />

      <div
        className={`
          relative w-full max-w-sm
          bg-white dark:bg-[#1c1c1e]
          rounded-t-3xl sm:rounded-3xl
          shadow-2xl shadow-black/30
          overflow-hidden
          animate-slide-up
          max-h-[90vh] flex flex-col
        `}
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 16px)' }}
      >
        <button
          ref={closeRef}
          onClick={onClose}
          className="absolute top-4 right-4 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-black/5 dark:bg-white/10 text-gray-400 dark:text-gray-500 hover:bg-black/10 dark:hover:bg-white/20 transition-colors"
          aria-label={t('common.close')}
        >
          <X className="h-4 w-4" />
        </button>

        <div className="flex-1 overflow-y-auto px-6 pt-10 pb-4">
          {/* ─── App Icon ─── */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-6">
              <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-[#d4af37] to-[#c5a028] shadow-lg shadow-[#d4af37]/30 flex items-center justify-center">
                <svg viewBox="0 0 24 24" className="w-10 h-10 text-[#0f172a]" fill="currentColor">
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
            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">
              {description}
            </p>
          </div>

          {/* ─── Body ─── */}
          <div className="mt-8 space-y-3">
            {type === 'ios' && (
              <>
                {iosSteps.map((step, i) => {
                  const Icon = iosIcons[i]!
                  return (
                    <div
                      key={i}
                      className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]"
                    >
                      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#d4af37]/10 dark:bg-[#d4af37]/15 text-sm font-bold text-[#d4af37]">
                        {i + 1}
                      </div>
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                        <Icon className="h-5 w-5" />
                      </div>
                      <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                        {t(step.labelKey)}
                      </p>
                    </div>
                  )
                })}
              </>
            )}

            {type === 'android' && (
              <div className="space-y-3">
                <div className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Smartphone className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                    {t('pwa.androidStep1')}
                  </p>
                </div>
                <div className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Globe className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                    {t('pwa.androidStep2')}
                  </p>
                </div>
                <div className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Plus className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                    {t('pwa.androidStep3')}
                  </p>
                </div>
                <div className="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 dark:bg-[#2c2c2e] border border-gray-100 dark:border-[#3a3a3c]">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-[#3a3a3c] shadow-sm text-gray-600 dark:text-gray-300">
                    <Check className="h-5 w-5" />
                  </div>
                  <p className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                    {t('pwa.androidStep4')}
                  </p>
                </div>
              </div>
            )}

            {type === 'not_installable' && (
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

        {/* ─── Footer ─── */}
        <div className="shrink-0 px-6 pt-2 pb-4 space-y-3">
          {type === 'ios' || type === 'android' ? (
            <button
              onClick={onInstalled}
              className="w-full py-3 px-6 rounded-2xl text-sm font-semibold transition-all duration-200 active:scale-[0.98] bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-[#0f172a] hover:shadow-lg hover:shadow-[#d4af37]/30"
            >
              <Check className="h-4 w-4 shrink-0 inline mr-2" />
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
