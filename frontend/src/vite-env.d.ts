/// <reference types="vite/client" />
/// <reference lib="webworker" />

declare module 'virtual:pwa-register/react' {
  export interface RegisterSWOptions {
    immediate?: boolean
    onNeedSWUpdate?: () => void
    onOfflineReady?: () => void
    onRegistered?: (registration: ServiceWorkerRegistration | undefined) => void
    onRegisterError?: (error: unknown) => void
  }

  export function useRegisterSW(options?: RegisterSWOptions): {
    readonly offlineReady: [boolean, (v: boolean) => void]
    readonly needRefresh: [boolean, (v: boolean) => void]
    readonly updateServiceWorker: (reloadPage?: boolean) => Promise<void>
  }
}
