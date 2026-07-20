export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

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

export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    (window.navigator as { standalone?: boolean }).standalone === true
  )
}

export function isInstalled(): boolean {
  return isStandalone()
}

export function isOnline(): boolean {
  return typeof navigator !== 'undefined' ? navigator.onLine : true
}

export function listenToOnlineStatus(onOnline: () => void, onOffline: () => void) {
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  return () => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  }
}

export function getIOSVersion(): number {
  if (!isIOS()) return 0
  const match = navigator.userAgent.match(/OS (\d+)_/)
  return match?.[1] ? parseInt(match[1], 10) : 0
}

export function canInstallOnDesktop(): boolean {
  return 'BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window
}

export const PWA_STORAGE_KEYS = {
  IOS_PROMPT_DISMISSED: 'pwa_ios_prompt_dismissed',
  INSTALL_PROMPT_DISMISSED: 'pwa_install_prompt_dismissed',
  INSTALL_PROMPT_DELAYED: 'pwa_install_prompt_delayed',
} as const
