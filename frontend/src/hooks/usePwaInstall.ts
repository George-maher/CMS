import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { PWAInstallService } from '@/lib/pwa/PWAInstallService'
import { PWAStatusService } from '@/lib/pwa/PWAStatusService'
import { useDeviceDetection } from '@/hooks/useDeviceDetection'

function devLog(...args: unknown[]) {
  if (import.meta.env.DEV) {
    console.log('[PWA:usePwaInstall]', ...args)
  }
}

export type ModalType = 'ios' | 'android' | 'not_installable' | null

let sharedModalType: ModalType = null
let sharedInstalled = false
const listeners = new Set<() => void>()

function notifyListeners() {
  listeners.forEach((fn) => fn())
}

export interface UsePwaInstallReturn {
  canInstall: boolean
  isStandalone: boolean
  isInstalled: boolean
  isInstalling: boolean
  isWaiting: boolean
  modalType: ModalType
  handleAppClick: () => Promise<void>
  handleInstall: () => Promise<void>
  handleCloseModal: () => void
  handleInstalled: () => void
}

export function usePwaInstall(): UsePwaInstallReturn {
  const [isInstalling, setIsInstalling] = useState(false)
  const [isWaiting, setIsWaiting] = useState(false)
  const [modalType, setModalTypeLocal] = useState<ModalType>(sharedModalType)
  const mountedRef = useRef(true)
  const navigate = useNavigate()

  const device = useDeviceDetection()
  const installService = PWAInstallService.getInstance()
  const statusService = PWAStatusService.getInstance()

  useEffect(() => {
    devLog('device detection:', {
      platform: device.platform,
      isIOS: device.isIOS,
      isAndroid: device.isAndroid,
      isDesktop: device.isDesktop,
      isChrome: device.isChrome,
      isEdge: device.isEdge,
      isFirefox: device.isFirefox,
      isSafariDesktop: device.isSafariDesktop,
      isSamsungBrowser: device.isSamsungBrowser,
      isStandalone: device.isStandalone,
    })
    devLog('installService:', {
      hasDeferredPrompt: installService.hasDeferredPrompt,
      isInstallable: installService.isInstallable,
      isInstalled: installService.isInstalled,
      supportsNative: installService.supportsNativeInstall(),
    })
    devLog('statusService:', {
      isStandalone: statusService.isStandalone,
      displayMode: statusService.displayMode,
      isInstalled: statusService.isInstalled,
    })

    return () => {
      mountedRef.current = false
    }
  }, [device, installService, statusService])

  useEffect(() => {
    const update = () => {
      if (mountedRef.current) {
        setModalTypeLocal(sharedModalType)
      }
    }
    listeners.add(update)
    return () => {
      listeners.delete(update)
    }
  }, [])

  const setModalType = useCallback((type: ModalType) => {
    sharedModalType = type
    notifyListeners()
  }, [])

  const canInstall = !device.isStandalone && !statusService.isInstalled
  const isStandalone = device.isStandalone
  const isInstalled = statusService.isInstalled || sharedInstalled

  const handleInstall = useCallback(async () => {
    setIsInstalling(true)
    try {
      const result = await installService.install()
      devLog('install result:', result)
      if (result === 'accepted') {
        sharedInstalled = true
        setModalType(null)
      } else if (result === 'unavailable') {
        if (device.isAndroid) {
          setModalType('android')
        } else if (device.isIOS) {
          setModalType('ios')
        } else {
          setModalType('not_installable')
        }
      }
    } finally {
      if (mountedRef.current) setIsInstalling(false)
    }
  }, [installService, device, setModalType])

  const handleAppClick = useCallback(async () => {
    devLog('handleAppClick:', {
      isStandalone: device.isStandalone,
      isInstalled: statusService.isInstalled,
      isIOS: device.isIOS,
      isAndroid: device.isAndroid,
      hasDeferredPrompt: installService.hasDeferredPrompt,
      supportsNative: installService.supportsNativeInstall(),
      platform: device.platform,
      browser: {
        chrome: device.isChrome,
        edge: device.isEdge,
        firefox: device.isFirefox,
        safari: device.isSafariDesktop,
        samsung: device.isSamsungBrowser,
      },
    })

    // Already installed → open app
    if (device.isStandalone) {
      devLog('handleAppClick: standalone mode, navigating to /')
      navigate('/')
      return
    }

    if (statusService.isInstalled || sharedInstalled) {
      devLog('handleAppClick: app installed, navigating to /')
      navigate('/')
      return
    }

    // iOS → show installation guide
    if (device.isIOS) {
      devLog('handleAppClick: iOS, showing iOS install guide')
      setModalType('ios')
      return
    }

    // Native deferred prompt is available → trigger install immediately
    if (installService.hasDeferredPrompt) {
      devLog('handleAppClick: deferred prompt exists, triggering install')
      await handleInstall()
      return
    }

    // Browser supports native install but prompt hasn't fired yet → wait briefly
    if (installService.supportsNativeInstall()) {
      devLog('handleAppClick: browser supports PWA, waiting for prompt...')
      setIsWaiting(true)
      const hasPrompt = await installService.waitForPrompt(8000)
      if (!mountedRef.current) return
      setIsWaiting(false)

      if (hasPrompt) {
        devLog('handleAppClick: prompt received, installing')
        await handleInstall()
        return
      }

      devLog('handleAppClick: prompt timed out, showing instructions')
      if (device.isAndroid) {
        setModalType('android')
      } else {
        setModalType('not_installable')
      }
      return
    }

    // Detected as Android but native check failed → show Android guide
    if (device.isAndroid) {
      devLog('handleAppClick: Android detected, showing Android guide')
      setModalType('android')
      return
    }

    // Firefox, Safari desktop → no native PWA support
    if (device.isSafariDesktop) {
      devLog('handleAppClick: Safari desktop, showing not-installable')
      setModalType('not_installable')
      return
    }

    if (device.isFirefox) {
      devLog('handleAppClick: Firefox, showing not-installable')
      setModalType('not_installable')
      return
    }

    // Fallback → show not-installable
    devLog('handleAppClick: fallback (no support detected), showing not-installable')
    setModalType('not_installable')
  }, [device, installService, statusService, handleInstall, navigate, setModalType])

  const handleCloseModal = useCallback(() => {
    setModalType(null)
  }, [setModalType])

  const handleInstalled = useCallback(() => {
    sharedInstalled = true
    setModalType(null)
  }, [setModalType])

  return {
    canInstall,
    isStandalone,
    isInstalled,
    isInstalling,
    isWaiting,
    modalType,
    handleAppClick,
    handleInstall,
    handleCloseModal,
    handleInstalled,
  }
}
