export type DisplayMode = 'standalone' | 'fullscreen' | 'minimal-ui' | 'browser'

export interface PWAStatus {
  isStandalone: boolean
  displayMode: DisplayMode
  isInstalled: boolean
}

const PWA_INSTALLED_KEY = 'pwa_app_installed'

function initModule() {
  if (typeof window === 'undefined') return
  window.addEventListener('appinstalled', () => {
    try { localStorage.setItem(PWA_INSTALLED_KEY, 'true') } catch {}
  })
}

initModule()

function wasPreviouslyInstalled(): boolean {
  try {
    return localStorage.getItem(PWA_INSTALLED_KEY) === 'true'
  } catch {
    return false
  }
}

export class PWAStatusService {
  private static instance: PWAStatusService

  static getInstance(): PWAStatusService {
    if (!PWAStatusService.instance) {
      PWAStatusService.instance = new PWAStatusService()
    }
    return PWAStatusService.instance
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

  get isInstalled(): boolean {
    return this.isStandalone || wasPreviouslyInstalled()
  }
}
