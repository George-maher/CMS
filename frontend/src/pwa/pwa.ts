export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export type Platform = 'ios' | 'android' | 'desktop' | 'unknown'

export type DismissalType = 'close' | 'remind_later' | 'never_show'

export interface PWAStatus {
  isStandalone: boolean
  isInstallable: boolean
  swRegistered: boolean
  swControlling: boolean
}

export const PWA_STORAGE_KEYS = {
  NEVER_SHOW: 'pwa_never_show',
  REMIND_LATER: 'pwa_remind_later',
  IOS_GUIDE_DISMISSED: 'pwa_ios_guide_dismissed',
} as const

export function isIOS(): boolean {
  if (typeof window === 'undefined') return false
  const ua = navigator.userAgent
  return /iPad|iPhone|iPod/.test(ua) && !('MSStream' in window)
}

export function isSafari(): boolean {
  if (typeof window === 'undefined') return false
  const ua = navigator.userAgent.toLowerCase()
  return ua.includes('safari') && !ua.includes('chrome') && !ua.includes('chromium')
}

export function isAndroid(): boolean {
  if (typeof window === 'undefined') return false
  return /android/i.test(navigator.userAgent)
}

export function isMobile(): boolean {
  if (typeof window === 'undefined') return false
  return isIOS() || isAndroid() || /Mobi|Mobile|Opera Mini/i.test(navigator.userAgent)
}

export function isDesktopBrowser(): boolean {
  if (typeof window === 'undefined') return false
  return !isMobile()
}

export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    (window.navigator as { standalone?: boolean }).standalone === true ||
    window.matchMedia('(display-mode: fullscreen)').matches ||
    window.matchMedia('(display-mode: minimal-ui)').matches
  )
}

export function getPlatform(): Platform {
  if (isIOS()) return 'ios'
  if (isAndroid()) return 'android'
  if (isDesktopBrowser()) return 'desktop'
  return 'unknown'
}

export function getIOSVersion(): number {
  if (!isIOS()) return 0
  const match = navigator.userAgent.match(/OS (\d+)_/)
  return match?.[1] ? parseInt(match[1], 10) : 0
}

export function supportsBeforeInstallPrompt(): boolean {
  return 'BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window
}

export function isSafariDesktop(): boolean {
  return isSafari() && !isIOS() && !isAndroid()
}

export function isFirefox(): boolean {
  if (typeof window === 'undefined') return false
  return /firefox|fxios/i.test(navigator.userAgent)
}

export function isChrome(): boolean {
  if (typeof window === 'undefined') return false
  const ua = navigator.userAgent.toLowerCase()
  return (ua.includes('chrome') || ua.includes('chromium')) && !ua.includes('edge') && !ua.includes('opr')
}

export function isEdge(): boolean {
  if (typeof window === 'undefined') return false
  return /edg/i.test(navigator.userAgent)
}

export function isSamsungBrowser(): boolean {
  if (typeof window === 'undefined') return false
  return /samsung/i.test(navigator.userAgent)
}

export function getPWAStatus(): PWAStatus {
  const status: PWAStatus = {
    isStandalone: isStandalone(),
    isInstallable: false,
    swRegistered: false,
    swControlling: false,
  }
  if ('serviceWorker' in navigator) {
    status.swControlling = navigator.serviceWorker.controller !== null
  }
  return status
}

export function checkSWRegistration(): Promise<boolean> {
  if (!('serviceWorker' in navigator)) return Promise.resolve(false)
  return navigator.serviceWorker.getRegistrations().then(regs => {
    return regs.length > 0
  }).catch(() => false)
}

export function registerFallbackSW(): Promise<boolean> {
  if (!('serviceWorker' in navigator)) return Promise.resolve(false)
  return navigator.serviceWorker.register('/sw.js').then(() => true).catch(() => false)
}

export function getBrowserInfo(): string {
  if (typeof window === 'undefined') return 'ssr'
  const ua = navigator.userAgent
  if (isIOS()) return 'ios'
  if (isSafariDesktop()) return 'safari-desktop'
  if (isFirefox()) return 'firefox'
  if (isEdge()) return 'edge'
  if (isSamsungBrowser()) return 'samsung'
  if (isChrome() || isAndroid()) return 'chrome'
  return ua
}

// ── localStorage helpers ──

export function getStorageItem(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

export function setStorageItem(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {}
}

export function isDismissedPermanently(): boolean {
  return getStorageItem(PWA_STORAGE_KEYS.NEVER_SHOW) === 'true'
}

export function isRemindLaterActive(): boolean {
  const val = getStorageItem(PWA_STORAGE_KEYS.REMIND_LATER)
  if (!val) return false
  const timestamp = parseInt(val, 10)
  if (isNaN(timestamp)) return false
  return Date.now() < timestamp
}

export function setRemindLater(days: number = 3): void {
  const future = Date.now() + days * 24 * 60 * 60 * 1000
  setStorageItem(PWA_STORAGE_KEYS.REMIND_LATER, String(future))
}

export function setNeverShowAgain(): void {
  setStorageItem(PWA_STORAGE_KEYS.NEVER_SHOW, 'true')
}
