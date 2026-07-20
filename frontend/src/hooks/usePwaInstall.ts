import { useState, useEffect, useCallback, useRef } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export interface UsePwaInstallReturn {
  isInstalled: boolean
  canInstall: boolean
  showIOSGuide: boolean
  handleAppClick: () => Promise<void>
  closeIOSGuide: () => void
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

function getStorage(key: string): string | null {
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

  const handleAppClick = useCallback(async () => {
    if (checkStandalone()) {
      return
    }

    if (isInstalled) {
      return
    }

    if (isIOSDevice()) {
      setShowIOSGuide(true)
      return
    }

    if (deferredPrompt) {
      try {
        deferredPrompt.prompt()
        const { outcome } = await deferredPrompt.userChoice
        if (outcome === 'accepted') {
          if (mountedRef.current) {
            setIsInstalled(true)
            setStorage('pwa_installed', 'true')
          }
        }
        if (mountedRef.current) {
          setDeferredPrompt(null)
        }
      } catch {
        if (mountedRef.current) {
          setDeferredPrompt(null)
        }
      }
    }
  }, [isInstalled, deferredPrompt])

  const closeIOSGuide = useCallback(() => {
    if (mountedRef.current) setShowIOSGuide(false)
  }, [])

  const canInstall = !isInstalled && !checkStandalone() && deferredPrompt !== null

  return { isInstalled: isInstalled || checkStandalone(), canInstall, showIOSGuide, handleAppClick, closeIOSGuide }
}
