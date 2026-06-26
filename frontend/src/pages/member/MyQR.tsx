import { useEffect, useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { regenerateOwnQrToken } from '@/api/users'
import QRCodeLib from 'qrcode'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import { Download, RefreshCw } from 'lucide-react'

export default function MemberMyQR() {
  const { t } = useTranslation()
  const { user, refreshUser } = useAuth()
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [copied, setCopied] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [regenerating, setRegenerating] = useState(false)
  const canvasRef = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    if (user?.attendance_qr_token) {
      QRCodeLib.toDataURL(user.attendance_qr_token, { width: 400, margin: 2, color: { dark: '#1e1e2e', light: '#ffffff' } })
        .then(setQrDataUrl).catch(() => {}).finally(() => setLoading(false))
    } else { setLoading(false) }
  }, [user])

  const handleCopy = () => {
    if (user?.attendance_qr_token) {
      navigator.clipboard.writeText(user.attendance_qr_token)
      setCopied(true); setTimeout(() => setCopied(false), 2000)
      toast.success(t('common.copied'))
    }
  }

  const handleRegenerate = async () => {
    if (!window.confirm(t('qr.regenerateConfirm'))) return
    setRegenerating(true)
    try {
      await regenerateOwnQrToken()
      await refreshUser()
      setQrDataUrl(null)
      toast.success(t('qr.regenerateToken'))
    } catch { toast.error(t('qr.failedRegenerate')) }
    finally { setRegenerating(false) }
  }

  const handleDownload = async () => {
    if (!qrDataUrl) return
    setDownloading(true)
    try {
      const link = document.createElement('a')
      link.download = `attendance-qr-${user?.name?.replace(/\s+/g, '-').toLowerCase() || 'member'}.png`
      link.href = qrDataUrl
      link.click()
    } finally { setDownloading(false) }
  }

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="mx-auto max-w-lg space-y-6">
      <div className="card p-5">
        <h2 className="text-lg font-semibold">{t('qr.myQRCode')}</h2>
        <p className="mt-1 text-sm text-secondary">{t('qr.qrDescriptionLong')}</p>
      </div>

      {user?.attendance_qr_token ? (
        <>
          <div className="card p-5 flex flex-col items-center py-8">
            {qrDataUrl ? (
              <>
                <canvas ref={canvasRef} className="hidden" />
                <img src={qrDataUrl} alt="QR Code" className="h-64 w-64 max-w-full" />
                <p className="mt-4 text-xs text-muted">{t('qr.scanWithServant')}</p>
              </>
            ) : (
              <div className="flex h-64 w-64 items-center justify-center rounded-lg bg-surface-secondary">
                <p className="text-sm text-muted">{t('qr.failedGenerate')}</p>
              </div>
            )}
          </div>

          <div className="card p-5">
            <h3 className="mb-3 text-sm font-semibold text-secondary">{t('qr.shareToken')}</h3>
            <div className="flex items-center gap-2">
              <code className="flex-1 truncate rounded-lg bg-surface-secondary px-3 py-2.5 font-mono text-sm text-secondary">
                {user.attendance_qr_token}
              </code>
              <button onClick={handleCopy}
                className="btn-primary btn-md">
                {copied ? t('common.copied') : t('common.copy')}
              </button>
            </div>
          </div>

          <div className="flex gap-3">
            <button onClick={handleDownload} disabled={downloading || !qrDataUrl}
              className="btn-secondary btn-md flex-1">
              <Download className="h-4 w-4" />
              {downloading ? t('common.loading') : t('qr.downloadQR')}
            </button>
            <button onClick={handleRegenerate} disabled={regenerating}
              className="btn-danger btn-md flex-1">
              <RefreshCw className={`h-4 w-4 ${regenerating ? 'animate-spin' : ''}`} />
              {regenerating ? t('common.loading') : t('qr.regenerateToken')}
            </button>
          </div>
        </>
      ) : (
        <div className="card p-5 py-12 text-center">
          <p className="text-muted mb-4">{t('qr.noToken')}</p>
          <button onClick={handleRegenerate} disabled={regenerating}
            className="btn-primary btn-md">
            {regenerating ? t('common.loading') : t('qr.generateToken')}
          </button>
        </div>
      )}
    </div>
  )
}
