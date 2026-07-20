import type { BeforeInstallPromptEvent } from '@/pwa/pwa'

export class PWAInstallService {
  private static instance: PWAInstallService
  private deferredPrompt: BeforeInstallPromptEvent | null = null
  private _isInstallable = false
  private _isInstalled = false
  private initialized = false

  static getInstance(): PWAInstallService {
    if (!PWAInstallService.instance) {
      PWAInstallService.instance = new PWAInstallService()
    }
    return PWAInstallService.instance
  }

  init(): void {
    if (this.initialized || typeof window === 'undefined') return
    this.initialized = true

    window.addEventListener('beforeinstallprompt', (e: Event) => {
      e.preventDefault()
      this.deferredPrompt = e as BeforeInstallPromptEvent
      this._isInstallable = true
    })

    window.addEventListener('appinstalled', () => {
      this._isInstalled = true
      this._isInstallable = false
      this.deferredPrompt = null
    })
  }

  get isInstallable(): boolean {
    return this._isInstallable && this.deferredPrompt !== null
  }

  get isInstalled(): boolean {
    return this._isInstalled
  }

  get hasDeferredPrompt(): boolean {
    return this.deferredPrompt !== null
  }

  async install(): Promise<'accepted' | 'dismissed' | null> {
    if (!this.deferredPrompt) return null

    try {
      this.deferredPrompt.prompt()
      const { outcome } = await this.deferredPrompt.userChoice
      if (outcome === 'accepted') {
        this._isInstalled = true
        this._isInstallable = false
      }
      this.deferredPrompt = null
      return outcome
    } catch {
      this.deferredPrompt = null
      return null
    }
  }

  reset(): void {
    this.deferredPrompt = null
    this._isInstallable = false
  }
}
