import { useMemo } from 'react'
import {
  isIOS,
  isAndroid,
  isMobile,
  isDesktopBrowser,
  isSafari,
  isChrome,
  isEdge,
  isFirefox,
  isSamsungBrowser,
  isSafariDesktop,
  getPlatform,
  getIOSVersion,
  isStandalone,
} from '@/pwa/pwa'

export interface DeviceInfo {
  platform: ReturnType<typeof getPlatform>
  isIOS: boolean
  isAndroid: boolean
  isDesktop: boolean
  isMobile: boolean
  isSafari: boolean
  isChrome: boolean
  isEdge: boolean
  isFirefox: boolean
  isSamsungBrowser: boolean
  isSafariDesktop: boolean
  iosVersion: number
  isStandalone: boolean
}

export function useDeviceDetection(): DeviceInfo {
  return useMemo(() => ({
    platform: getPlatform(),
    isIOS: isIOS(),
    isAndroid: isAndroid(),
    isDesktop: isDesktopBrowser(),
    isMobile: isMobile(),
    isSafari: isSafari(),
    isChrome: isChrome(),
    isEdge: isEdge(),
    isFirefox: isFirefox(),
    isSamsungBrowser: isSamsungBrowser(),
    isSafariDesktop: isSafariDesktop(),
    iosVersion: getIOSVersion(),
    isStandalone: isStandalone(),
  }), [])
}
