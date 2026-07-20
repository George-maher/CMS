import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { PWAInstallService } from '@/lib/pwa/PWAInstallService'
import { PWAStatusService } from '@/lib/pwa/PWAStatusService'
import { useDeviceDetection } from '@/hooks/useDeviceDetection'

let sharedShowIOSModal = false
let sharedIOSDismissed = false
const listeners = new Set<() => void>()

function notifyListeners() {
  listeners.forEach((fn) => fn())
}

export interface UsePwaInstallReturn {
  canInstall: boolean
  isStandalone: boolean
  isInstalling: boolean
  showIOSModal: boolean
  handleAppClick: () => void
  handleInstall: () => Promise<void>
  handleCloseIOSModal: () => void
  handleInstalledOnIOS: () => void
}

export function usePwaInstall(): UsePwaInstallReturn {
  const [isInstalling, setIsInstalling] = useState(false)
  const [showIOSModal, setShowIOSModalLocal] = useState(sharedShowIOSModal)
  const mountedRef = useRef(true)
  const navigate = useNavigate()

  const device = useDeviceDetection()
  const installService = PWAInstallService.getInstance()
  const statusService = PWAStatusService.getInstance()

  useEffect(() => {
    installService.init()
    statusService.init()
    return () => {
      mountedRef.current = false
    }
  }, [installService, statusService])

  useEffect(() => {
    const update = () => {
      if (mountedRef.current) setShowIOSModalLocal(sharedShowIOSModal)
    }
    listeners.add(update)
    return () => {
      listeners.delete(update)
    }
  }, [])

  const setShowIOSModal = useCallback((val: boolean) => {
    sharedShowIOSModal = val
    notifyListeners()
  }, [])

  const canInstall = !device.isStandalone && !sharedIOSDismissed
  const isStandalone = device.isStandalone

  const handleInstall = useCallback(async () => {
    setIsInstalling(true)
    try {
      await installService.install()
    } finally {
      if (mountedRef.current) setIsInstalling(false)
    }
  }, [installService])

  const handleAppClick = useCallback(() => {
    if (device.isStandalone) {
      navigate('/')
      return
    }

    if (device.isIOS) {
      setShowIOSModal(true)
      return
    }

    if (installService.hasDeferredPrompt) {
      handleInstall()
      return
    }
  }, [device.isStandalone, device.isIOS, installService, handleInstall, navigate, setShowIOSModal])

  const handleCloseIOSModal = useCallback(() => {
    setShowIOSModal(false)
  }, [setShowIOSModal])

  const handleInstalledOnIOS = useCallback(() => {
    sharedIOSDismissed = true
    setShowIOSModal(false)
  }, [setShowIOSModal])

  return {
    canInstall,
    isStandalone,
    isInstalling,
    showIOSModal,
    handleAppClick,
    handleInstall,
    handleCloseIOSModal,
    handleInstalledOnIOS,
  }
}
