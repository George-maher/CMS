import { useState, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/hooks/useTheme'
import { RefreshCw, Sparkles } from 'lucide-react'
import { useRegisterSW } from 'virtual:pwa-register/react'

export default function UpdatePrompt() {
  const { t } = useTranslation()
  const { theme } = useTheme()
  const isDark = theme === 'dark'
  const [visible, setVisible] = useState(false)
  const [animatingOut, setAnimatingOut] = useState(false)
  const mountedRef = useRef(true)

  useEffect(() => {
    return () => { mountedRef.current = false }
  }, [])

  const {
    needRefresh: [needRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegistered(r) {
      if (r) console.log('[SW] Registered:', r)
    },
    onRegisterError(error) {
      console.error('[SW] Registration error:', error)
    },
    onOfflineReady() {
      console.log('[SW] Offline ready')
    },
  })

  useEffect(() => {
    if (needRefresh) {
      const timer = setTimeout(() => {
        if (mountedRef.current) setVisible(true)
      }, 1000)
      return () => clearTimeout(timer)
    } else {
      setVisible(false)
      setAnimatingOut(false)
    }
  }, [needRefresh])

  const handleUpdate = () => {
    setAnimatingOut(true)
    setTimeout(() => {
      if (mountedRef.current) {
        setVisible(false)
        updateServiceWorker(true)
      }
    }, 300)
  }

  const handleDismiss = () => {
    setAnimatingOut(true)
    setTimeout(() => {
      if (mountedRef.current) setVisible(false)
    }, 300)
  }

  if (!visible) return null

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 p-4" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom, 0px))' }}>
      <div
        className={`
          mx-auto max-w-md rounded-2xl border shadow-2xl overflow-hidden
          transition-all duration-300
          ${animatingOut ? 'translate-y-8 opacity-0' : 'translate-y-0 opacity-100'}
          ${isDark ? 'bg-[#1e293b] border-[#334155] shadow-black/30' : 'bg-white border-[#e8e5d9] shadow-black/10'}
        `}
      >
        <div className={`p-4 ${isDark ? 'bg-gradient-to-r from-[#3b82f6]/10 to-transparent' : 'bg-gradient-to-r from-[#3b82f6]/5 to-transparent'}`}>
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3 min-w-0 flex-1">
              <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                isDark ? 'bg-[#3b82f6]/15' : 'bg-[#3b82f6]/10'
              }`}>
                <Sparkles className={`h-5 w-5 ${isDark ? 'text-[#60a5fa]' : 'text-[#3b82f6]'}`} />
              </div>
              <div className="min-w-0 flex-1">
                <p className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-[#1a1a2e]'}`}>
                  {t('pwa.updateAvailable')}
                </p>
                <p className={`mt-0.5 text-xs ${isDark ? 'text-[#94a3b8]' : 'text-[#5b5b6e]'}`}>
                  {t('pwa.updateDescription')}
                </p>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <button
                onClick={handleUpdate}
                className={`
                  flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all duration-200
                  ${isDark
                    ? 'bg-gradient-to-r from-[#3b82f6] to-[#2563eb] text-white hover:shadow-lg hover:shadow-[#3b82f6]/25'
                    : 'bg-gradient-to-r from-[#3b82f6] to-[#2563eb] text-white hover:shadow-lg hover:shadow-[#3b82f6]/25'
                  }
                  active:scale-95
                `}
              >
                <RefreshCw className="h-3.5 w-3.5" />
                {t('pwa.updateButton')}
              </button>
              <button
                onClick={handleDismiss}
                className={`flex h-7 w-7 items-center justify-center rounded-lg transition-colors ${
                  isDark ? 'text-[#64748b] hover:bg-[#334155] hover:text-white' : 'text-[#6b7280] hover:bg-[#f3f1ea] hover:text-[#1a1a2e]'
                }`}
                aria-label={t('common.close')}
              >
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
