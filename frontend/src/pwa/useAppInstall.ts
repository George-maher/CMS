import { useState, useEffect, useCallback } from 'react'
import {
  type BeforeInstallPromptEvent,
  type Platform as PwPlatform,
  getPlatform,
  isStandalone,
} from './pwa'

export type Platform = PwPlatform

export interface UseAppInstallReturn {
  platform: Platform
  isStandalone: boolean
  showModal: boolean
  isInstalling: boolean
  hasPrompt: boolean
  handleAppClick: () => void
  handleInstall: () => Promise<void>
  handleClose: () => void
  handleInstalledOnIOS: () => void
  handleRemindLater: () => void
  handleNeverShowAgain: () => void
}

export function useAppInstall(): UseAppInstallReturn {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstalling, setIsInstalling] = useState(false)
  const [showModal, setShowModal] = useState(false)

  const platform = getPlatform()
  const standalone = isStandalone()
  const hasPrompt = deferredPrompt !== null

  useEffect(() => {
    if (standalone) return

    const handler = (e: Event) => {
      e.preventDefault()
      setDeferredPrompt(e as BeforeInstallPromptEvent)
    }

    const installedHandler = () => {
      setDeferredPrompt(null)
      setShowModal(false)
    }

    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', installedHandler)

    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
      window.removeEventListener('appinstalled', installedHandler)
    }
  }, [standalone])

  const handleInstall = useCallback(async () => {
    if (!deferredPrompt) return
    setIsInstalling(true)
    try {
      deferredPrompt.prompt()
      const { outcome } = await deferredPrompt.userChoice
      if (outcome === 'accepted') {
        setDeferredPrompt(null)
        setShowModal(false)
        return
      }
    } catch {
    }
    setShowModal(true)
    setIsInstalling(false)
  }, [deferredPrompt])

  const handleAppClick = useCallback(() => {
    if (standalone) {
      window.location.href = '/'
      return
    }

    if (platform === 'ios') {
      setShowModal(true)
      return
    }

    if (deferredPrompt) {
      handleInstall()
      return
    }

    setShowModal(true)
  }, [standalone, platform, deferredPrompt, handleInstall])

  const handleClose = useCallback(() => setShowModal(false), [])
  const handleInstalledOnIOS = useCallback(() => setShowModal(false), [])
  const handleRemindLater = useCallback(() => setShowModal(false), [])
  const handleNeverShowAgain = useCallback(() => setShowModal(false), [])

  return {
    platform,
    isStandalone: standalone,
    showModal,
    isInstalling,
    hasPrompt,
    handleAppClick,
    handleInstall,
    handleClose,
    handleInstalledOnIOS,
    handleRemindLater,
    handleNeverShowAgain,
  }
}
