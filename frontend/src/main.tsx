import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './i18n'
import './index.css'
import App from './App'
import { OfflineProvider } from '@/contexts/OfflineContext'
import { SyncProvider } from '@/contexts/SyncContext'
import { registerSW } from 'virtual:pwa-register'

if (import.meta.env.DEV) {
  window.onerror = (message, source, lineno, colno, error) => {
    console.group('[GLOBAL] window.onerror')
    console.error('Message:', message)
    console.error('Source:', source)
    console.error('Line:', lineno, 'Col:', colno)
    if (error) {
      console.error('Error:', error)
      console.error('Stack:', error.stack)
    }
    console.groupEnd()
  }

  window.onunhandledrejection = (event) => {
    const reason = event.reason
    console.group('[GLOBAL] Unhandled Promise Rejection')
    if (reason instanceof Error) {
      console.error('Message:', reason.message)
      console.error('Stack:', reason.stack)
    } else {
      console.error('Reason:', reason)
    }
    if (reason?.response) {
      console.error('Response:', reason.response.data)
      console.error('Status:', reason.response.status)
    }
    if (reason?.config) {
      console.error('URL:', reason.config.url)
      console.error('Method:', reason.config.method)
    }
    console.groupEnd()
  }

  console.log('[DEBUG] Global error handlers installed')
}

const updateSW = registerSW({
  onNeedRefresh() {
    if (confirm('A new version is available. Update now?')) {
      updateSW(true)
    }
  },
  onOfflineReady() {
    console.log('[PWA] App ready for offline use')
  },
  onRegisterError(error) {
    console.error('[PWA] Service Worker registration failed:', error)
  },
})

if (!('serviceWorker' in navigator)) {
  console.warn('[PWA] Service workers not supported')
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <OfflineProvider>
      <SyncProvider>
        <App />
      </SyncProvider>
    </OfflineProvider>
  </StrictMode>,
)
