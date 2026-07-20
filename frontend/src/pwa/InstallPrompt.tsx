import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Download, X } from 'lucide-react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export default function InstallPrompt() {
  const { t } = useTranslation()
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstallable, setIsInstallable] = useState(false)
  const [isDismissed, setIsDismissed] = useState(false)
  const [isIOS, setIsIOS] = useState(false)
  const [isStandalone, setIsStandalone] = useState(false)
  const [isInstalling, setIsInstalling] = useState(false)

  useEffect(() => {
    const standalone = window.matchMedia('(display-mode: standalone)').matches
      || (window.navigator as { standalone?: boolean }).standalone === true

    setIsStandalone(standalone)

    const ua = navigator.userAgent
    const iOS = /iPad|iPhone|iPod/.test(ua) && !('MSStream' in window)
    setIsIOS(iOS)

    const handler = (e: Event) => {
      e.preventDefault()
      setDeferredPrompt(e as BeforeInstallPromptEvent)
      setIsInstallable(true)
    }

    window.addEventListener('beforeinstallprompt', handler)

    window.addEventListener('appinstalled', () => {
      setIsInstallable(false)
      setDeferredPrompt(null)
      setIsStandalone(true)
    })

    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [])

  const handleInstall = useCallback(async () => {
    if (!deferredPrompt) return

    setIsInstalling(true)
    deferredPrompt.prompt()
    const { outcome } = await deferredPrompt.userChoice

    if (outcome === 'accepted') {
      setIsInstallable(false)
      setDeferredPrompt(null)
    }
    setIsInstalling(false)
  }, [deferredPrompt])

  const handleDismiss = useCallback(() => {
    setIsDismissed(true)
    setIsInstallable(false)
    setDeferredPrompt(null)
  }, [])

  if (isStandalone || (!isInstallable && !isIOS) || isDismissed) return null

  if (isIOS) {
    return (
      <div className="fixed bottom-0 left-0 right-0 z-50 p-4 animate-slide-up" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom, 0px))' }}>
        <div className="mx-auto max-w-md rounded-2xl border bg-surface p-4 shadow-2xl shadow-black/20">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-400/10">
                <Download className="h-5 w-5 text-primary-400" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-text-primary">
                  {t('pwa.installIOS')}
                </p>
                <p className="mt-1 text-xs text-text-secondary">
                  {t('pwa.installIOSSteps')}
                </p>
              </div>
            </div>
            <button
              onClick={handleDismiss}
              className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-text-muted hover:bg-surface-tertiary hover:text-text-primary transition-colors"
              aria-label="Dismiss"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 p-4 animate-slide-up" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom, 0px))' }}>
      <div className="mx-auto max-w-md rounded-2xl border bg-surface p-4 shadow-2xl shadow-black/20">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-400/10">
              <Download className="h-5 w-5 text-primary-400" />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-text-primary">
                {t('pwa.installTitle')}
              </p>
              <p className="mt-1 text-xs text-text-secondary">
                {t('pwa.installDescription')}
              </p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <button
              onClick={handleInstall}
              disabled={isInstalling}
              className="btn-primary btn-sm whitespace-nowrap"
            >
              {isInstalling ? t('common.loading') : t('pwa.installButton')}
            </button>
            <button
              onClick={handleDismiss}
              className="flex h-7 w-7 items-center justify-center rounded-lg text-text-muted hover:bg-surface-tertiary hover:text-text-primary transition-colors"
              aria-label="Dismiss"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
