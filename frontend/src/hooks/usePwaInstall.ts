import { useState, useEffect, useCallback, useRef } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export interface UsePwaInstallReturn {
  isInstalled: boolean
  canInstall: boolean
  showIOSGuide: boolean
  handleAppClick: () => Promise<'installed' | 'ios_guide' | 'dismissed' | 'noop'>
  closeIOSGuide: () => void
  browserSupported: boolean
  isIOS: boolean
}

function isIOSDevice(): boolean {
  if (typeof window === 'undefined') return false
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !('MSStream' in window)
}

function checkStandalone(): boolean {
  if (typeof window === 'undefined') return false
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    (window.navigator as { standalone?: boolean }).standalone === true ||
    window.matchMedia('(display-mode: fullscreen)').matches ||
    window.matchMedia('(display-mode: minimal-ui)').matches
  )
}

function supportsBeforeInstallPrompt(): boolean {
  return typeof window !== 'undefined' && ('BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window)
}

export function getStorage(key: string): string | null {
  try { return localStorage.getItem(key) } catch { return null }
}

function setStorage(key: string, value: string): void {
  try { localStorage.setItem(key, value) } catch {}
}

export function usePwaInstall(): UsePwaInstallReturn {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstalled, setIsInstalled] = useState(false)
  const [showIOSGuide, setShowIOSGuide] = useState(false)
  const mountedRef = useRef(true)

  useEffect(() => {
    return () => { mountedRef.current = false }
  }, [])

  useEffect(() => {
    const standalone = checkStandalone()
    const stored = getStorage('pwa_installed') === 'true'

    if (standalone || stored) {
      setIsInstalled(true)
    }

    if (standalone) {
      setStorage('pwa_installed', 'true')
    }

    const onBeforeInstall = (e: Event) => {
      e.preventDefault()
      if (mountedRef.current) {
        setDeferredPrompt(e as BeforeInstallPromptEvent)
      }
    }

    const onAppInstalled = () => {
      if (!mountedRef.current) return
      setIsInstalled(true)
      setDeferredPrompt(null)
      setStorage('pwa_installed', 'true')
    }

    const onDisplayModeChange = () => {
      if (!mountedRef.current) return
      if (checkStandalone()) {
        setIsInstalled(true)
        setStorage('pwa_installed', 'true')
      }
    }

    window.addEventListener('beforeinstallprompt', onBeforeInstall)
    window.addEventListener('appinstalled', onAppInstalled)

    const mqlStandalone = window.matchMedia('(display-mode: standalone)')
    const mqlFullscreen = window.matchMedia('(display-mode: fullscreen)')
    mqlStandalone.addEventListener('change', onDisplayModeChange)
    mqlFullscreen.addEventListener('change', onDisplayModeChange)

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstall)
      window.removeEventListener('appinstalled', onAppInstalled)
      mqlStandalone.removeEventListener('change', onDisplayModeChange)
      mqlFullscreen.removeEventListener('change', onDisplayModeChange)
    }
  }, [])

  const handleAppClick = useCallback(async (): Promise<'installed' | 'ios_guide' | 'dismissed' | 'noop'> => {
    if (checkStandalone() || isInstalled) {
      return 'installed'
    }

    if (isIOSDevice()) {
      setShowIOSGuide(true)
      return 'ios_guide'
    }

    if (deferredPrompt) {
      try {
        deferredPrompt.prompt()
        const { outcome } = await deferredPrompt.userChoice
        if (mountedRef.current) {
          setDeferredPrompt(null)
        }
        if (outcome === 'accepted') {
          if (mountedRef.current) {
            setIsInstalled(true)
            setStorage('pwa_installed', 'true')
          }
          return 'installed'
        }
        return 'dismissed'
      } catch {
        if (mountedRef.current) {
          setDeferredPrompt(null)
        }
        return 'noop'
      }
    }

    return 'noop'
  }, [isInstalled, deferredPrompt])

  const closeIOSGuide = useCallback(() => {
    if (mountedRef.current) setShowIOSGuide(false)
  }, [])

  const standalone = checkStandalone()
  const canInstall = !isInstalled && !standalone && deferredPrompt !== null
  const iosDevice = isIOSDevice()
  const browserSupported = supportsBeforeInstallPrompt() || iosDevice

  return {
    isInstalled: isInstalled || standalone,
    canInstall,
    showIOSGuide,
    handleAppClick,
    closeIOSGuide,
    browserSupported,
    isIOS: iosDevice,
  }
}
