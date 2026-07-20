export type DisplayMode = 'standalone' | 'fullscreen' | 'minimal-ui' | 'browser'

type StatusChangeCallback = (status: PWAStatus) => void

export interface PWAStatus {
  isStandalone: boolean
  displayMode: DisplayMode
  isInstalled: boolean
}

const PWA_INSTALLED_KEY = 'pwa_app_installed'

export class PWAStatusService {
  private static instance: PWAStatusService
  private initialized = false
  private listeners: Set<StatusChangeCallback> = new Set()
  private mediaQuery: MediaQueryList | null = null

  static getInstance(): PWAStatusService {
    if (!PWAStatusService.instance) {
      PWAStatusService.instance = new PWAStatusService()
    }
    return PWAStatusService.instance
  }

  init(): void {
    if (this.initialized || typeof window === 'undefined') return
    this.initialized = true

    const query = '(display-mode: standalone)'
    this.mediaQuery = window.matchMedia(query)
    const handler = () => this.notify()
    this.mediaQuery.addEventListener('change', handler)

    window.addEventListener('appinstalled', () => {
      this.markInstalled()
    })

    window.addEventListener('beforeinstallprompt', () => {
    })
  }

  private markInstalled(): void {
    try {
      localStorage.setItem(PWA_INSTALLED_KEY, 'true')
    } catch {}
    this.notify()
  }

  private wasPreviouslyInstalled(): boolean {
    try {
      return localStorage.getItem(PWA_INSTALLED_KEY) === 'true'
    } catch {
      return false
    }
  }

  getStatus(): PWAStatus {
    return {
      isStandalone: this.isStandalone,
      displayMode: this.displayMode,
      isInstalled: this.isStandalone || this.wasPreviouslyInstalled(),
    }
  }

  get isStandalone(): boolean {
    if (typeof window === 'undefined') return false
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      (window.navigator as { standalone?: boolean }).standalone === true ||
      window.matchMedia('(display-mode: fullscreen)').matches ||
      window.matchMedia('(display-mode: minimal-ui)').matches
    )
  }

  get displayMode(): DisplayMode {
    if (typeof window === 'undefined') return 'browser'
    if (window.matchMedia('(display-mode: standalone)').matches) return 'standalone'
    if (window.matchMedia('(display-mode: fullscreen)').matches) return 'fullscreen'
    if (window.matchMedia('(display-mode: minimal-ui)').matches) return 'minimal-ui'
    return 'browser'
  }

  subscribe(callback: StatusChangeCallback): () => void {
    this.listeners.add(callback)
    return () => {
      this.listeners.delete(callback)
    }
  }

  private notify(): void {
    const status = this.getStatus()
    this.listeners.forEach((cb) => cb(status))
  }
}
