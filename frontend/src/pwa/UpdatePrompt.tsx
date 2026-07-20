import { useTranslation } from 'react-i18next'
import { RefreshCw, X } from 'lucide-react'
import { useRegisterSW } from 'virtual:pwa-register/react'

export default function UpdatePrompt() {
  const { t } = useTranslation()
  const {
    needRefresh: [needRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegistered(r) {
      console.log('[SW] Registered:', r)
    },
    onRegisterError(error) {
      console.error('[SW] Registration error:', error)
    },
  })

  if (!needRefresh) return null

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 p-4 animate-slide-up" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom, 0px))' }}>
      <div className="mx-auto max-w-md rounded-2xl border bg-surface p-4 shadow-2xl shadow-black/20">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-info/10">
              <RefreshCw className="h-5 w-5 text-info" />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-text-primary">
                {t('pwa.updateAvailable')}
              </p>
              <p className="mt-1 text-xs text-text-secondary">
                {t('pwa.updateDescription')}
              </p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <button
              onClick={() => updateServiceWorker(true)}
              className="btn-primary btn-sm whitespace-nowrap"
            >
              <RefreshCw className="h-3.5 w-3.5" />
              {t('pwa.updateButton')}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
