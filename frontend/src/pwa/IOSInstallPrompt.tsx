import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/hooks/useTheme'
import { isIOS, isStandalone, PWA_STORAGE_KEYS, getIOSVersion } from './pwa'
import { Plus, Check, X } from 'lucide-react'

function ShareIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
      <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
    </svg>
  )
}

function SafariShareIcon({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
      <line x1="3" y1="9" x2="21" y2="9" />
      <line x1="9" y1="21" x2="9" y2="9" />
      <polyline points="9,14 6,17 9,17" />
    </svg>
  )
}

export default function IOSInstallPrompt() {
  const { t } = useTranslation()
  const { theme } = useTheme()
  const [visible, setVisible] = useState(false)
  const [step, setStep] = useState(0)
  const [dismissed, setDismissed] = useState(false)
  const [animatingOut, setAnimatingOut] = useState(false)

  const isDark = theme === 'dark'
  const iosVersion = getIOSVersion()

  useEffect(() => {
    const alreadyDismissed = localStorage.getItem(PWA_STORAGE_KEYS.IOS_PROMPT_DISMISSED)
    if (alreadyDismissed === 'true') {
      setDismissed(true)
      return
    }

    const isiOS = isIOS()
    const standalone = isStandalone()

    if (isiOS && !standalone) {
      const timer = setTimeout(() => {
        setVisible(true)
        const stepTimer = setInterval(() => {
          setStep((prev) => (prev < 2 ? prev + 1 : prev))
        }, 1800)
        setTimeout(() => clearInterval(stepTimer), 5400)
      }, 2000)

      return () => {
        clearTimeout(timer)
      }
    }
  }, [])

  const handleDismiss = useCallback(() => {
    setAnimatingOut(true)
    setTimeout(() => {
      setVisible(false)
      setDismissed(true)
      try {
        localStorage.setItem(PWA_STORAGE_KEYS.IOS_PROMPT_DISMISSED, 'true')
      } catch {}
    }, 300)
  }, [])

  if (!visible || dismissed) return null

  const steps = [
    {
      icon: iosVersion >= 15 ? (
        <SafariShareIcon className="h-7 w-7" />
      ) : (
        <ShareIcon className="h-7 w-7" />
      ),
      label: t('pwa.iosStep1'),
    },
    {
      icon: <Plus className="h-7 w-7" />,
      label: t('pwa.iosStep2'),
    },
    {
      icon: <Check className="h-7 w-7" />,
      label: t('pwa.iosStep3'),
    },
  ]

  return (
    <div className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-4">
      <div
        className={`absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity duration-300 ${animatingOut ? 'opacity-0' : 'opacity-100'}`}
        onClick={handleDismiss}
      />

      <div
        className={`
          relative w-full max-w-md rounded-2xl border shadow-2xl overflow-hidden
          transition-all duration-300
          ${animatingOut ? 'translate-y-8 opacity-0 scale-95' : 'translate-y-0 opacity-100 scale-100'}
          ${isDark ? 'bg-[#1e293b] border-[#334155]' : 'bg-white border-[#e8e5d9]'}
        `}
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}
      >
        <div className={`px-5 pt-5 pb-3 ${isDark ? 'bg-gradient-to-br from-[#1e3a8a]/30 to-[#0f1d3d]/30' : 'bg-gradient-to-br from-[#d4af37]/5 to-[#f9efc8]/10'}`}>
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className={`flex h-12 w-12 items-center justify-center rounded-2xl ${isDark ? 'bg-[#d4af37]/15' : 'bg-[#d4af37]/10'} shadow-sm`}>
                <svg viewBox="0 0 24 24" className={`h-6 w-6 ${isDark ? 'text-[#d4af37]' : 'text-[#c5a028]'}`} fill="currentColor">
                  <rect x="6" y="11" width="12" height="10" rx="1" opacity="0.9" />
                  <polygon points="12,4 4,11 20,11" />
                  <rect x="11" y="2" width="2" height="5" rx="0.5" />
                  <rect x="9" y="9" width="6" height="12" rx="3" opacity="0.4" />
                </svg>
              </div>
              <div>
                <h3 className={`text-base font-bold ${isDark ? 'text-white' : 'text-[#1a1a2e]'}`}>
                  {t('pwa.iosInstallTitle')}
                </h3>
                <p className={`text-xs mt-0.5 ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                  {t('pwa.iosInstallDescription')}
                </p>
              </div>
            </div>
            <button
              onClick={handleDismiss}
              className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl transition-colors ${
                isDark ? 'hover:bg-[#334155] text-[#64748b] hover:text-white' : 'hover:bg-[#f3f1ea] text-[#6b7280] hover:text-[#1a1a2e]'
              }`}
              aria-label={t('common.close')}
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div className="px-5 py-4">
          <div className="grid grid-cols-3 gap-3">
            {steps.map((s, i) => (
              <div
                key={i}
                className={`flex flex-col items-center text-center gap-2 p-3 rounded-xl transition-all duration-500 ${
                  step >= i
                    ? isDark
                      ? 'bg-[#0f172a]/60 border border-[#334155]'
                      : 'bg-[#faf9f6] border border-[#e8e5d9]'
                    : isDark
                      ? 'bg-transparent border border-transparent opacity-40'
                      : 'bg-transparent border border-transparent opacity-40'
                }`}
              >
                <div className={`flex h-12 w-12 items-center justify-center rounded-xl transition-all duration-500 ${
                  step >= i
                    ? isDark
                      ? 'bg-[#d4af37]/15 text-[#d4af37]'
                      : 'bg-[#d4af37]/10 text-[#c5a028]'
                    : isDark
                      ? 'bg-[#334155] text-[#64748b]'
                      : 'bg-[#f3f1ea] text-[#6b7280]'
                }`}>
                  {s.icon}
                </div>
                <span className={`text-[11px] font-medium leading-tight ${
                  step >= i
                    ? isDark ? 'text-white' : 'text-[#1a1a2e]'
                    : isDark ? 'text-[#64748b]' : 'text-[#6b7280]'
                }`}>
                  {s.label}
                </span>
              </div>
            ))}
          </div>

          <div className={`mt-4 p-3 rounded-xl ${isDark ? 'bg-[#d4af37]/5 border border-[#d4af37]/10' : 'bg-[#fdf8e8] border border-[#d4af37]/20'}`}>
            <div className="flex items-center gap-2">
              <div className={`h-1.5 w-1.5 rounded-full animate-pulse ${isDark ? 'bg-[#d4af37]' : 'bg-[#c5a028]'}`} />
              <p className={`text-xs ${isDark ? 'text-[#cbd5e1]' : 'text-[#5b5b6e]'}`}>
                {t('pwa.iosTip', {
                  defaultValue: 'Tip: You can find the Share button at the bottom of the Safari address bar.',
                })}
              </p>
            </div>
          </div>
        </div>

        <div className={`px-5 pb-5 pt-0`}>
          <button
            onClick={handleDismiss}
            className={`w-full py-2.5 px-4 rounded-xl text-sm font-semibold transition-all duration-200 ${
              isDark
                ? 'bg-gradient-to-r from-[#d4af37] to-[#e8c95a] text-[#0f172a] hover:shadow-lg hover:shadow-[#d4af37]/25 active:scale-[0.98]'
                : 'bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-white hover:shadow-lg hover:shadow-[#d4af37]/25 active:scale-[0.98]'
            }`}
          >
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  )
}
