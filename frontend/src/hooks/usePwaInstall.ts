import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export interface UsePwaInstallReturn {
  isInstalled: boolean
  showIOSGuide: boolean
  handleAppClick: () => Promise<void>
  closeIOSGuide: () => void
}

function isIOSDevice(): boolean {
  if (typeof window === 'undefined') return false
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !('MSStream' in window)
}

export function usePwaInstall(): UsePwaInstallReturn {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstalled, setIsInstalled] = useState(false)
  const [showIOSGuide, setShowIOSGuide] = useState(false)
  const navigate = useNavigate()

  useEffect(() => {
    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      (window.navigator as { standalone?: boolean }).standalone === true ||
      window.matchMedia('(display-mode: fullscreen)').matches ||
      window.matchMedia('(display-mode: minimal-ui)').matches

    const previouslyInstalled = (() => {
      try { return localStorage.getItem('pwa_installed') === 'true' } catch { return false }
    })()

    if (isStandalone || previouslyInstalled) {
      setIsInstalled(true)
    }

    const onBeforeInstall = (e: Event) => {
      e.preventDefault()
      setDeferredPrompt(e as BeforeInstallPromptEvent)
    }

    const onAppInstalled = () => {
      setIsInstalled(true)
      setDeferredPrompt(null)
      try { localStorage.setItem('pwa_installed', 'true') } catch {}
    }

    window.addEventListener('beforeinstallprompt', onBeforeInstall)
    window.addEventListener('appinstalled', onAppInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstall)
      window.removeEventListener('appinstalled', onAppInstalled)
    }
  }, [])

  const handleAppClick = useCallback(async () => {
    if (isInstalled) {
      navigate('/')
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
          setIsInstalled(true)
          try { localStorage.setItem('pwa_installed', 'true') } catch {}
        }
        setDeferredPrompt(null)
      } catch {
        setDeferredPrompt(null)
      }
    }
  }, [isInstalled, deferredPrompt, navigate])

  const closeIOSGuide = useCallback(() => {
    setShowIOSGuide(false)
  }, [])

  return { isInstalled, showIOSGuide, handleAppClick, closeIOSGuide }
}
