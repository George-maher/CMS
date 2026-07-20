import { useState, useEffect, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/hooks/useTheme'
import { Download, X, Monitor, Smartphone, Shield } from 'lucide-react'
import { isIOS, isStandalone, PWA_STORAGE_KEYS, type BeforeInstallPromptEvent } from './pwa'

export default function InstallPrompt() {
  const { t } = useTranslation()
  const { theme } = useTheme()
  const isDark = theme === 'dark'

  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstallable, setIsInstallable] = useState(false)
  const [isDismissed, setIsDismissed] = useState(false)
  const [isInstalling, setIsInstalling] = useState(false)
  const [animatingOut, setAnimatingOut] = useState(false)
  const [visible, setVisible] = useState(false)
  const [supportsInstall, setSupportsInstall] = useState(false)
  const mountedRef = useRef(true)

  useEffect(() => {
    return () => { mountedRef.current = false }
  }, [])

  useEffect(() => {
    if (isStandalone()) return
    if (isIOS()) return

    const alreadyDismissed = localStorage.getItem(PWA_STORAGE_KEYS.INSTALL_PROMPT_DISMISSED)
    if (alreadyDismissed === 'true') {
      setIsDismissed(true)
      return
    }

    const supportsBeforeInstall =
      'BeforeInstallPromptEvent' in window ||
      'onbeforeinstallprompt' in window
    setSupportsInstall(supportsBeforeInstall)

    const handler = (e: Event) => {
      e.preventDefault()
      if (!mountedRef.current) return
      const promptEvent = e as BeforeInstallPromptEvent
      setDeferredPrompt(promptEvent)
      setIsInstallable(true)
      setSupportsInstall(true)

      setTimeout(() => {
        if (mountedRef.current) setVisible(true)
      }, 1500)
    }

    window.addEventListener('beforeinstallprompt', handler)

    window.addEventListener('appinstalled', () => {
      if (!mountedRef.current) return
      setIsInstallable(false)
      setDeferredPrompt(null)
    })

    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
    }
  }, [])

  const handleInstall = useCallback(async () => {
    if (!deferredPrompt) return
    setIsInstalling(true)

    try {
      deferredPrompt.prompt()
      const { outcome } = await deferredPrompt.userChoice

      if (outcome === 'accepted') {
        setIsInstallable(false)
        setDeferredPrompt(null)
        setAnimatingOut(true)
        setTimeout(() => {
          if (mountedRef.current) {
            setVisible(false)
            setIsDismissed(true)
          }
        }, 300)
      }
    } catch {
    } finally {
      if (mountedRef.current) setIsInstalling(false)
    }
  }, [deferredPrompt])

  const handleDismiss = useCallback(() => {
    setAnimatingOut(true)
    setTimeout(() => {
      if (!mountedRef.current) return
      setVisible(false)
      setIsDismissed(true)
      setIsInstallable(false)
      setDeferredPrompt(null)
      try {
        localStorage.setItem(PWA_STORAGE_KEYS.INSTALL_PROMPT_DISMISSED, 'true')
      } catch {}
    }, 300)
  }, [])

  if (!visible || (!isInstallable && !supportsInstall) || isDismissed) return null

  const isDesktop = !('ontouchstart' in window) && window.innerWidth >= 1024

  return (
    <div className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-4">
      <div
        className={`absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity duration-300 ${animatingOut ? 'opacity-0' : 'opacity-100'}`}
        onClick={handleDismiss}
      />

      <div
        className={`
          relative w-full max-w-sm rounded-2xl border shadow-2xl overflow-hidden
          transition-all duration-300
          ${animatingOut ? 'translate-y-8 opacity-0 scale-95' : 'translate-y-0 opacity-100 scale-100'}
          ${isDark ? 'bg-[#1e293b] border-[#334155]' : 'bg-white border-[#e8e5d9]'}
        `}
        style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}
      >
        <div className={`px-5 pt-5 pb-3 ${isDark ? 'bg-gradient-to-br from-[#1e3a8a]/20 to-[#0f1d3d]/20' : 'bg-gradient-to-br from-[#d4af37]/5 to-[#f9efc8]/10'}`}>
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
                  {t('pwa.installTitle')}
                </h3>
                <p className={`text-xs mt-0.5 ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                  {t('pwa.installDescription')}
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

        <div className="px-5 py-3">
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
        </div>

        <div className="px-5 pb-5 pt-1 flex gap-2">
          <button
            onClick={handleDismiss}
            className={`flex-1 py-2.5 px-4 rounded-xl text-sm font-medium transition-all duration-200 ${
              isDark
                ? 'bg-[#334155] text-[#cbd5e1] hover:bg-[#475569] active:scale-[0.98]'
                : 'bg-[#f3f1ea] text-[#5b5b6e] hover:bg-[#e8e5d9] active:scale-[0.98]'
            }`}
          >
            {t('common.cancel')}
          </button>
          <button
            onClick={handleInstall}
            disabled={isInstalling || !deferredPrompt}
            className={`
              flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold transition-all duration-200
              flex items-center justify-center gap-2
              ${isInstalling || !deferredPrompt ? 'opacity-50 cursor-not-allowed' : 'active:scale-[0.98]'}
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
        </div>
      </div>
    </div>
  )
}
