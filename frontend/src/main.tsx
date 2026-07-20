import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import toast from 'react-hot-toast'
import './i18n'
import './index.css'
import '@/lib/pwa/PWAInstallService'
import '@/lib/pwa/PWAStatusService'
import App from './App'

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

// Online / Offline connectivity notifications
window.addEventListener('online', () => {
  toast.success('You are back online!', { id: 'connection-status' })
})

window.addEventListener('offline', () => {
  toast.error('You are offline. Some features may be unavailable.', {
    id: 'connection-status',
    duration: 6000,
  })
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
