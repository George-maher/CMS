import { useState, useEffect, useCallback, useRef } from 'react'
import {
  type BeforeInstallPromptEvent,
  type Platform,
  type DismissalType,
  PWA_STORAGE_KEYS,
  getPlatform,
  isStandalone,
  isDismissedPermanently,
  isRemindLaterActive,
  setRemindLater,
  setNeverShowAgain,
  getStorageItem,
  setStorageItem,
} from './pwa'

export interface UsePwaInstallReturn {
  show: boolean
  platform: Platform
  canInstall: boolean
  isInstallable: boolean
  deferredPrompt: BeforeInstallPromptEvent | null
  isInstalling: boolean
  handleInstall: () => Promise<void>
  handleDismiss: (type: DismissalType) => void
  handleClose: () => void
  handleRemindLater: () => void
  handleNeverShowAgain: () => void
  handleInstalledOnIOS: () => void
}

export function usePwaInstall(delayMs: number = 2000): UsePwaInstallReturn {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [isInstallable, setIsInstallable] = useState(false)
  const [isInstalling, setIsInstalling] = useState(false)
  const [show, setShow] = useState(false)
  const [blocked, setBlocked] = useState(true)
  const mountedRef = useRef(true)

  const platform = getPlatform()
  const standalone = isStandalone()

  useEffect(() => {
    return () => { mountedRef.current = false }
  }, [])

  // Early exit conditions — set blocked immediately
  useEffect(() => {
    if (standalone) {
      setBlocked(true)
      return
    }
    if (isDismissedPermanently()) {
      setBlocked(true)
      return
    }
    if (isRemindLaterActive()) {
      setBlocked(true)
      return
    }
    const iosGuideDismissed = getStorageItem(PWA_STORAGE_KEYS.IOS_GUIDE_DISMISSED)
    if (iosGuideDismissed === 'true') {
      setBlocked(true)
      return
    }
    setBlocked(false)
  }, [standalone])

  // Hook into beforeinstallprompt (Android/Desktop)
  useEffect(() => {
    if (standalone) return
    if (platform === 'ios') return

    const handler = (e: Event) => {
      e.preventDefault()
      if (!mountedRef.current) return
      const promptEvent = e as BeforeInstallPromptEvent
      setDeferredPrompt(promptEvent)
      setIsInstallable(true)
    }

    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', () => {
      if (!mountedRef.current) return
      setIsInstallable(false)
      setDeferredPrompt(null)
      setShow(false)
      setBlocked(true)
    })

    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
    }
  }, [standalone, platform])

  // Determine whether the modal should appear at all
  const canInstall = (() => {
    if (standalone) return false
    if (blocked) return false
    if (platform === 'ios') return true
    if (platform === 'android' || platform === 'desktop') return isInstallable
    return false
  })()

  // Show the modal after a short delay once conditions are met
  useEffect(() => {
    if (!canInstall) {
      setShow(false)
      return
    }
    const timer = setTimeout(() => {
      if (mountedRef.current) setShow(true)
    }, delayMs)
    return () => clearTimeout(timer)
  }, [canInstall, delayMs])

  const handleInstall = useCallback(async () => {
    if (!deferredPrompt) return
    setIsInstalling(true)
    try {
      deferredPrompt.prompt()
      const { outcome } = await deferredPrompt.userChoice
      if (outcome === 'accepted') {
        setDeferredPrompt(null)
        setIsInstallable(false)
        setShow(false)
        setBlocked(true)
      }
    } catch {
    } finally {
      if (mountedRef.current) setIsInstalling(false)
    }
  }, [deferredPrompt])

  const handleDismiss = useCallback((type: DismissalType) => {
    if (type === 'never_show') setNeverShowAgain()
    else if (type === 'remind_later') setRemindLater(3)
    else if (type === 'close' && platform === 'ios') setStorageItem(PWA_STORAGE_KEYS.IOS_GUIDE_DISMISSED, 'true')
    setShow(false)
    setBlocked(true)
  }, [platform])

  const handleClose = useCallback(() => handleDismiss('close'), [handleDismiss])
  const handleRemindLater = useCallback(() => handleDismiss('remind_later'), [handleDismiss])
  const handleNeverShowAgain = useCallback(() => handleDismiss('never_show'), [handleDismiss])
  const handleInstalledOnIOS = useCallback(() => {
    setStorageItem(PWA_STORAGE_KEYS.IOS_GUIDE_DISMISSED, 'true')
    setShow(false)
    setBlocked(true)
  }, [])

  return {
    show,
    platform,
    canInstall,
    isInstallable,
    deferredPrompt,
    isInstalling,
    handleInstall,
    handleDismiss,
    handleClose,
    handleRemindLater,
    handleNeverShowAgain,
    handleInstalledOnIOS,
  }
}
