import { useState, useEffect, useCallback, useRef } from 'react'
import {
  type BeforeInstallPromptEvent,
  type Platform as PwPlatform,
  getPlatform,
  isStandalone,
  isFirefox,
  isSafariDesktop,
  isDesktopBrowser,
} from './pwa'

export type Platform = PwPlatform

export type PwaModalView = 'install' | 'ios_guide' | 'already_installed' | 'not_installable' | null

export interface UseAppInstallReturn {
  platform: Platform
  isStandalone: boolean
  modalView: PwaModalView
  isInstalling: boolean
  hasPrompt: boolean
  handleAppClick: () => void
  handleInstall: () => Promise<void>
  handleClose: () => void
  handleInstalledOnIOS: () => void
  handleRemindLater: () => void
  handleNeverShowAgain: () => void
  handleFallbackInstall: () => void
}

export function useAppInstall(): UseAppInstallReturn {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstalling, setIsInstalling] = useState(false)
  const [modalView, setModalView] = useState<PwaModalView>(null)
  const promptFiredRef = useRef(false)
  const mountedRef = useRef(true)

  useEffect(() => {
    return () => { mountedRef.current = false }
  }, [])

  const platform = getPlatform()
  const standalone = isStandalone()
  const hasPrompt = deferredPrompt !== null
  const controlledPrompt = useRef<BeforeInstallPromptEvent | null>(null)

  useEffect(() => {
    if (standalone) return

    const handler = (e: Event) => {
      e.preventDefault()
      promptFiredRef.current = true
      const event = e as BeforeInstallPromptEvent
      setDeferredPrompt(event)
      controlledPrompt.current = event
    }

    const installedHandler = () => {
      setDeferredPrompt(null)
      controlledPrompt.current = null
      setModalView(null)
    }

    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', installedHandler)

    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
      window.removeEventListener('appinstalled', installedHandler)
    }
  }, [standalone])

  const handleInstall = useCallback(async () => {
    const prompt = deferredPrompt || controlledPrompt.current
    if (!prompt) return

    setIsInstalling(true)
    try {
      await prompt.prompt()
      const { outcome } = await prompt.userChoice
      if (outcome === 'accepted') {
        setDeferredPrompt(null)
        controlledPrompt.current = null
        setModalView(null)
        return
      }
    } catch {
    } finally {
      if (mountedRef.current) setIsInstalling(false)
    }
  }, [deferredPrompt])

  const handleFallbackInstall = useCallback(() => {
    if (platform === 'ios') {
      setModalView('ios_guide')
      return
    }
    if (isSafariDesktop()) {
      setModalView('not_installable')
      return
    }
    if (isFirefox()) {
      setModalView('not_installable')
      return
    }
    if (isDesktopBrowser()) {
      setModalView('not_installable')
      return
    }
    setModalView('not_installable')
  }, [platform])

  const handleAppClick = useCallback(() => {
    if (standalone) {
      setModalView('already_installed')
      return
    }

    if (platform === 'ios') {
      setModalView('ios_guide')
      return
    }

    if (deferredPrompt || controlledPrompt.current) {
      handleInstall()
      return
    }

    if (!promptFiredRef.current) {
      setModalView('install')
      return
    }

    handleFallbackInstall()
  }, [standalone, platform, deferredPrompt, handleInstall, handleFallbackInstall])

  const handleClose = useCallback(() => setModalView(null), [])
  const handleInstalledOnIOS = useCallback(() => setModalView(null), [])
  const handleRemindLater = useCallback(() => setModalView(null), [])
  const handleNeverShowAgain = useCallback(() => setModalView(null), [])

  return {
    platform,
    isStandalone: standalone,
    modalView,
    isInstalling,
    hasPrompt,
    handleAppClick,
    handleInstall,
    handleClose,
    handleInstalledOnIOS,
    handleRemindLater,
    handleNeverShowAgain,
    handleFallbackInstall,
  }
}