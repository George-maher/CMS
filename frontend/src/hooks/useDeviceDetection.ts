export function useDeviceDetection() {
  const ua = navigator.userAgent

  const isIOS = /iphone|ipad|ipod/i.test(ua)
  const isAndroid = /android/i.test(ua)
  const isMobile = isIOS || isAndroid || /mobile/i.test(ua)
  const isSafari = /^((?!chrome|android).)*safari/i.test(ua)
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches

  return { isIOS, isAndroid, isMobile, isSafari, isStandalone }
}
