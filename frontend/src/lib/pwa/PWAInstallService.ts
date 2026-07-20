import type { BeforeInstallPromptEvent } from '@/pwa/pwa'

function devLog(...args: unknown[]) {
  if (import.meta.env.DEV) {
    console.log('[PWA:InstallService]', ...args)
  }
}

let _deferredPrompt: BeforeInstallPromptEvent | null = null
let _isInstallable = false
let _isInstalled = false
let _pendingResolve: ((value: boolean) => void) | null = null

function initModule() {
  if (typeof window === 'undefined') return
  if ((window as unknown as Record<string, unknown>).__pwaInstallListenersReady) return
  ;(window as unknown as Record<string, unknown>).__pwaInstallListenersReady = true

  devLog('Setting up module-level beforeinstallprompt listener')

  window.addEventListener('beforeinstallprompt', (e: Event) => {
    e.preventDefault()
    _deferredPrompt = e as BeforeInstallPromptEvent
    _isInstallable = true
    devLog('beforeinstallprompt captured')

    if (_pendingResolve) {
      _pendingResolve(true)
      _pendingResolve = null
    }
  })

  window.addEventListener('appinstalled', () => {
    _isInstalled = true
    _isInstallable = false
    _deferredPrompt = null
    try { localStorage.setItem('pwa_app_installed', 'true') } catch {}
    devLog('appinstalled event fired')
  })
}

initModule()

let _isChromium: boolean | null = null

function isChromiumBased(): boolean {
  if (_isChromium !== null) return _isChromium
  try {
    const ua = navigator.userAgent.toLowerCase()
    _isChromium =
      ua.includes('chrome') ||
      ua.includes('chromium') ||
      ua.includes('edg') ||
      ua.includes('samsung') ||
      ua.includes('brave')
  } catch {
    _isChromium = false
  }
  return _isChromium
}

export class PWAInstallService {
  private static instance: PWAInstallService

  static getInstance(): PWAInstallService {
    if (!PWAInstallService.instance) {
      PWAInstallService.instance = new PWAInstallService()
      devLog('Instance created')
    }
    return PWAInstallService.instance
  }

  get isInstallable(): boolean {
    return _isInstallable && _deferredPrompt !== null
  }

  get isInstalled(): boolean {
    return _isInstalled
  }

  get hasDeferredPrompt(): boolean {
    return _deferredPrompt !== null
  }

  supportsNativeInstall(): boolean {
    if (typeof window === 'undefined') return false

    if ('onbeforeinstallprompt' in window) return true
    if ('BeforeInstallPromptEvent' in window) return true

    if (isChromiumBased()) return true

    return false
  }

  async waitForPrompt(timeoutMs = 8000): Promise<boolean> {
    if (_deferredPrompt) {
      devLog('waitForPrompt: prompt already available')
      return true
    }

    devLog('waitForPrompt: waiting up to', timeoutMs, 'ms')

    return new Promise((resolve) => {
      const timer = setTimeout(() => {
        _pendingResolve = null
        devLog('waitForPrompt: timed out after', timeoutMs, 'ms')
        resolve(false)
      }, timeoutMs)

      _pendingResolve = (value: boolean) => {
        clearTimeout(timer)
        devLog('waitForPrompt: resolved with', value)
        resolve(value)
      }
    })
  }

  async install(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
    const prompt = _deferredPrompt
    if (!prompt) {
      devLog('install: no deferred prompt available')
      return 'unavailable'
    }

    try {
      prompt.prompt()
      devLog('install: native prompt shown')
      const { outcome } = await prompt.userChoice
      devLog('install: userChoice outcome:', outcome)
      if (outcome === 'accepted') {
        _isInstalled = true
        _isInstallable = false
      }
      _deferredPrompt = null
      return outcome
    } catch (err) {
      devLog('install: error during prompt:', err)
      _deferredPrompt = null
      return 'unavailable'
    }
  }
}
