import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/hooks/useTheme'
import {
  Download,
  X,
  Monitor,
  Smartphone,
  Shield,
  Share2,
  ArrowDown,
  Plus,
  Check,
  ExternalLink,
  AppWindow,
} from 'lucide-react'
import { getIOSVersion } from './pwa'
import type { UseAppInstallReturn } from './useAppInstall'

export default function AppInstallModal({
  showModal,
  platform,
  isInstalling,
  isStandalone,
  handleInstall,
  handleClose,
  handleRemindLater,
  handleNeverShowAgain,
  handleInstalledOnIOS,
}: UseAppInstallReturn) {
  const { t } = useTranslation()
  const { theme } = useTheme()
  const isDark = theme === 'dark'
  const iosVersion = getIOSVersion()

  useEffect(() => {
    if (showModal) {
      const timer = setTimeout(() => {
        document.getElementById('app-modal-close')?.focus()
      }, 100)
      return () => clearTimeout(timer)
    }
  }, [showModal])

  useEffect(() => {
    if (!showModal) return
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose()
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [showModal, handleClose])

  if (!showModal) return null

  const isIOSGuide = platform === 'ios'
  const isDesktop = platform === 'desktop'

  const iosSteps = [
    {
      icon: iosVersion >= 15 ? (
        <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
          <line x1="3" y1="9" x2="21" y2="9" />
          <line x1="9" y1="21" x2="9" y2="9" />
          <polyline points="9,14 6,17 9,17" />
        </svg>
      ) : (
        <Share2 className="h-6 w-6" />
      ),
      label: t('pwa.iosStep1'),
    },
    {
      icon: <ArrowDown className="h-6 w-6" />,
      label: t('pwa.iosStep2'),
    },
    {
      icon: <Plus className="h-6 w-6" />,
      label: t('pwa.iosStep3'),
    },
    {
      icon: <Check className="h-6 w-6" />,
      label: t('pwa.iosStep4'),
    },
  ]

  return (
    <div
      className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-3 sm:p-4"
      role="dialog"
      aria-modal="true"
      aria-label={isIOSGuide ? t('pwa.iosInstallTitle') : t('pwa.installTitle')}
    >
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm animate-fade-in"
        onClick={handleClose}
      />

      <div
        className={`
          relative w-full max-w-sm animate-slide-up
          rounded-2xl border shadow-2xl overflow-hidden
          max-h-[90vh] flex flex-col
          ${isDark ? 'bg-[#1e293b] border-[#334155]' : 'bg-white border-[#e8e5d9]'}
        `}
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}
      >
        {/* ── Header ── */}
        <div className={`shrink-0 px-5 pt-5 pb-3 ${isDark ? 'bg-gradient-to-br from-[#1e3a8a]/20 to-[#0f1d3d]/20' : 'bg-gradient-to-br from-[#d4af37]/5 to-[#f9efc8]/10'}`}>
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3 min-w-0">
              <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ${isDark ? 'bg-[#d4af37]/15' : 'bg-[#d4af37]/10'} shadow-sm`}>
                <AppWindow className={`h-6 w-6 ${isDark ? 'text-[#d4af37]' : 'text-[#c5a028]'}`} />
              </div>
              <div className="min-w-0">
                <h3 className={`text-base font-bold truncate ${isDark ? 'text-white' : 'text-[#1a1a2e]'}`}>
                  {isStandalone
                    ? t('app.alreadyInstalled')
                    : isIOSGuide
                      ? t('pwa.iosInstallTitle')
                      : t('pwa.installTitle')}
                </h3>
                <p className={`text-xs mt-0.5 truncate ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                  {isStandalone
                    ? t('app.alreadyInstalledDesc')
                    : isIOSGuide
                      ? t('pwa.iosInstallDescription')
                      : t('pwa.installDescription')}
                </p>
              </div>
            </div>
            <button
              id="app-modal-close"
              onClick={handleClose}
              className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl transition-colors ${
                isDark ? 'hover:bg-[#334155] text-[#64748b] hover:text-white' : 'hover:bg-[#f3f1ea] text-[#6b7280] hover:text-[#1a1a2e]'
              }`}
              aria-label={t('common.close')}
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* ── Scrollable Body ── */}
        <div className="flex-1 overflow-y-auto px-5 py-3 space-y-3">
          {isStandalone ? (
            <div className={`p-6 text-center rounded-xl ${isDark ? 'bg-[#0f172a]/50 border border-[#334155]' : 'bg-[#faf9f6] border border-[#e8e5d9]'}`}>
              <div className={`mx-auto flex h-16 w-16 items-center justify-center rounded-2xl mb-4 ${
                isDark ? 'bg-[#10b981]/15' : 'bg-[#10b981]/10'
              }`}>
                <Check className={`h-8 w-8 ${isDark ? 'text-[#34d399]' : 'text-[#059669]'}`} />
              </div>
              <p className={`text-sm font-semibold mb-2 ${isDark ? 'text-white' : 'text-[#1a1a2e]'}`}>
                {t('app.alreadyInstalled')}
              </p>
              <p className={`text-xs ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                {t('app.alreadyInstalledDesc')}
              </p>
            </div>
          ) : isIOSGuide ? (
            <div className="space-y-2">
              {iosSteps.map((step, i) => (
                <div
                  key={i}
                  className={`flex items-center gap-3 p-3 rounded-xl ${
                    isDark ? 'bg-[#0f172a]/60 border border-[#334155]' : 'bg-[#faf9f6] border border-[#e8e5d9]'
                  }`}
                >
                  <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold ${
                    isDark ? 'bg-[#d4af37]/15 text-[#d4af37]' : 'bg-[#d4af37]/10 text-[#c5a028]'
                  }`}>
                    {i + 1}
                  </div>
                  <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                    isDark ? 'bg-[#1e293b] text-[#cbd5e1]' : 'bg-white text-[#5b5b6e]'
                  }`}>
                    {step.icon}
                  </div>
                  <p className={`text-sm leading-snug ${isDark ? 'text-[#e2e8f0]' : 'text-[#1a1a2e]'}`}>
                    {step.label}
                  </p>
                </div>
              ))}
              <div className={`p-3 rounded-xl ${isDark ? 'bg-[#d4af37]/5 border border-[#d4af37]/10' : 'bg-[#fdf8e8] border border-[#d4af37]/20'}`}>
                <div className="flex items-start gap-2">
                  <ExternalLink className={`h-4 w-4 mt-0.5 shrink-0 ${isDark ? 'text-[#d4af37]' : 'text-[#c5a028]'}`} />
                  <p className={`text-xs leading-relaxed ${isDark ? 'text-[#cbd5e1]' : 'text-[#5b5b6e]'}`}>
                    {t('pwa.iosTip')}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <div className={`p-4 rounded-xl ${isDark ? 'bg-[#0f172a]/50 border border-[#334155]' : 'bg-[#faf9f6] border border-[#e8e5d9]'}`}>
              <div className="flex items-center gap-3 mb-3">
                <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                  isDesktop
                    ? isDark ? 'bg-[#3b82f6]/15 text-[#60a5fa]' : 'bg-[#3b82f6]/10 text-[#3b82f6]'
                    : isDark ? 'bg-[#10b981]/15 text-[#34d399]' : 'bg-[#10b981]/10 text-[#059669]'
                }`}>
                  {isDesktop ? <Monitor className="h-4 w-4" /> : <Smartphone className="h-4 w-4" />}
                </div>
                <p className={`text-sm font-medium ${isDark ? 'text-white' : 'text-[#1a1a2e]'}`}>
                  {isDesktop ? t('pwa.desktopInstallDesc') : t('pwa.mobileInstallDesc')}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Shield className={`h-3.5 w-3.5 ${isDark ? 'text-[#34d399]' : 'text-[#059669]'}`} />
                <span className={`text-xs ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                  {t('pwa.secureInstallDesc')}
                </span>
              </div>
            </div>
          )}
        </div>

        {/* ── Footer ── */}
        <div className="shrink-0 px-5 pb-5 pt-2 space-y-2">
          {isStandalone ? (
            <button
              onClick={() => { window.location.href = '/'; handleClose() }}
              className={`
                w-full py-2.5 px-4 rounded-xl text-sm font-semibold transition-all duration-200
                flex items-center justify-center gap-2 active:scale-[0.98]
                ${isDark
                  ? 'bg-gradient-to-r from-[#d4af37] to-[#e8c95a] text-[#0f172a] hover:shadow-lg hover:shadow-[#d4af37]/25'
                  : 'bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-white hover:shadow-lg hover:shadow-[#d4af37]/25'
                }
              `}
            >
              <AppWindow className="h-4 w-4" />
              {t('app.openApp')}
            </button>
          ) : isIOSGuide ? (
            <button
              onClick={handleInstalledOnIOS}
              className={`
                w-full py-2.5 px-4 rounded-xl text-sm font-semibold transition-all duration-200
                flex items-center justify-center gap-2 active:scale-[0.98]
                ${isDark
                  ? 'bg-gradient-to-r from-[#d4af37] to-[#e8c95a] text-[#0f172a] hover:shadow-lg hover:shadow-[#d4af37]/25'
                  : 'bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-white hover:shadow-lg hover:shadow-[#d4af37]/25'
                }
              `}
            >
              <Check className="h-4 w-4" />
              {t('pwa.iosInstalledButton')}
            </button>
          ) : (
            <button
              onClick={handleInstall}
              disabled={isInstalling}
              className={`
                w-full py-2.5 px-4 rounded-xl text-sm font-semibold transition-all duration-200
                flex items-center justify-center gap-2
                ${isInstalling ? 'opacity-50 cursor-not-allowed' : 'active:scale-[0.98]'}
                ${isDark
                  ? 'bg-gradient-to-r from-[#d4af37] to-[#e8c95a] text-[#0f172a] hover:shadow-lg hover:shadow-[#d4af37]/25'
                  : 'bg-gradient-to-r from-[#d4af37] to-[#c5a028] text-white hover:shadow-lg hover:shadow-[#d4af37]/25'
                }
              `}
            >
              {isInstalling ? (
                <>
                  <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  {t('common.loading')}
                </>
              ) : (
                <>
                  <Download className="h-4 w-4" />
                  {t('pwa.installButton')}
                </>
              )}
            </button>
          )}

          {!isStandalone && (
            <div className="flex items-center justify-center gap-4">
              <button
                onClick={handleRemindLater}
                className={`text-xs font-medium transition-colors ${
                  isDark ? 'text-[#94a3b8] hover:text-[#e2e8f0]' : 'text-[#6b7280] hover:text-[#1a1a2e]'
                }`}
              >
                {t('pwa.remindLater')}
              </button>
              <span className={`text-[10px] ${isDark ? 'text-[#475569]' : 'text-[#d1d5db]'}`}>|</span>
              <button
                onClick={handleNeverShowAgain}
                className={`text-xs font-medium transition-colors ${
                  isDark ? 'text-[#94a3b8] hover:text-[#e2e8f0]' : 'text-[#6b7280] hover:text-[#1a1a2e]'
                }`}
              >
                {t('pwa.dontShowAgain')}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
