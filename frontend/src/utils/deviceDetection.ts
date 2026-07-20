export type Platform = 'ios' | 'android' | 'desktop' | 'unknown'

export function isIOS(): boolean {
  if (typeof window === 'undefined') return false
  return /iPad|iPhone|iPod/.test(navigator.userAgent) && !('MSStream' in window)
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
