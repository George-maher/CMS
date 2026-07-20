export interface PWAOptions {
  onInstalled?: () => void
}

export function setupPWAListener(options: PWAOptions = {}) {
  if (!('serviceWorker' in navigator)) return

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    window.location.reload()
  })

  navigator.serviceWorker.ready.then((registration) => {
    if (registration.active) {
      registration.active.addEventListener('statechange', () => {
        if (registration.active?.state === 'activated') {
          options.onInstalled?.()
        }
      })
    }
  })
}

export function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches
    || (window.navigator as { standalone?: boolean }).standalone === true
}

export function isOnline(): boolean {
  return navigator.onLine
}

export function listenToOnlineStatus(onOnline: () => void, onOffline: () => void) {
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  return () => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  }
}
