import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ArrowLeft, CheckCircle, XCircle, ExternalLink, Download, ZoomIn, X, FileText } from 'lucide-react'
import Badge from '@/components/common/Badge'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import { getApplication, approveApplication, rejectApplication } from '@/api/churchApplications'
import type { ChurchApplication } from '@/types'

const statusBadge: Record<string, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

export default function PlatformApplicationDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [app, setApp] = useState<ChurchApplication | null>(null)
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState(false)
  const [showRejectModal, setShowRejectModal] = useState(false)
  const [rejectReason, setRejectReason] = useState('')
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [previewLabel, setPreviewLabel] = useState('')

  useEffect(() => {
    if (!id) return
    setLoading(true)
    getApplication(Number(id)).then(setApp).finally(() => setLoading(false))
  }, [id])

  const handleApprove = async () => {
    if (!window.confirm(t('platform.approveConfirm'))) return
    setActionLoading(true)
    try {
      await approveApplication(Number(id))
      toast.success(t('platform.approveSuccess'))
      navigate('/platform')
    } catch { toast.error(t('common.saving')) }
    finally { setActionLoading(false) }
  }

  const handleReject = async () => {
    if (!rejectReason.trim()) { toast.error(t('platform.rejectionRequired')); return }
    setActionLoading(true)
    try {
      await rejectApplication(Number(id), rejectReason)
      toast.success(t('platform.rejectSuccess'))
      setShowRejectModal(false)
      navigate('/platform')
    } catch { toast.error(t('common.saving')) }
    finally { setActionLoading(false) }
  }

  const isImage = (url: string) => /\.(jpg|jpeg|png|gif|webp|bmp)$/i.test(url) || url.startsWith('data:image')

  const openPreview = useCallback((url: string, label: string) => {
    setPreviewUrl(url)
    setPreviewLabel(label)
  }, [])

  const closePreview = useCallback(() => {
    setPreviewUrl(null)
    setPreviewLabel('')
  }, [])

  const handleDownload = useCallback((url: string, filename: string) => {
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.target = '_blank'
    a.rel = 'noopener noreferrer'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
  }, [])

  if (loading) return <LoadingSpinner className="py-20" />
  if (!app) return <p className="py-12 text-center text-muted">{t('platform.notFound')}</p>

  const filePreview = (url: string | null, label: string, filename: string) => {
    if (!url) return (
      <div className="flex h-40 items-center justify-center rounded-lg bg-surface-secondary text-sm text-muted">{t('platform.noImage')}</div>
    )

    const isImg = isImage(url)

    return (
      <div className="group relative overflow-hidden rounded-lg border border-border">
        {isImg ? (
          <ImageWithFallback
            src={url}
            alt={label}
            className="max-h-64 w-full cursor-pointer object-contain transition-transform hover:scale-105"
            onClick={() => openPreview(url, label)}
          />
        ) : (
          <div
            className="flex h-40 cursor-pointer flex-col items-center justify-center gap-2 bg-surface-secondary hover:bg-surface transition-colors"
            onClick={() => window.open(url, '_blank')}
          >
            <FileText className="h-10 w-10 text-muted" />
            <p className="max-w-[200px] truncate text-sm text-muted">{filename}</p>
          </div>
        )}
        <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/0 transition-colors group-hover:bg-black/40">
          {isImg ? (
            <button onClick={() => openPreview(url, label)} className="rounded-full bg-white/90 p-2 text-secondary opacity-0 shadow-lg transition-all hover:bg-white group-hover:opacity-100" title={t('platform.zoom')}>
              <ZoomIn className="h-5 w-5" />
            </button>
          ) : null}
          <button onClick={() => handleDownload(url, filename)} className="rounded-full bg-white/90 p-2 text-secondary opacity-0 shadow-lg transition-all hover:bg-white group-hover:opacity-100" title={t('common.download')}>
            <Download className="h-5 w-5" />
          </button>
          <a href={url} target="_blank" rel="noopener noreferrer" className="rounded-full bg-white/90 p-2 text-secondary opacity-0 shadow-lg transition-all hover:bg-white group-hover:opacity-100" title={t('platform.openInNewTab')}>
            <ExternalLink className="h-5 w-5" />
          </a>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <button onClick={() => navigate('/platform')} className="btn-icon btn-ghost">
        <ArrowLeft className="h-4 w-4" /> {t('platform.backToList')}
      </button>

      <div className="card">
        <div className="flex items-start justify-between border-b border-border px-6 py-4">
          <div>
            <h1 className="text-xl font-bold">{app.church_name}</h1>
          </div>
          <Badge variant={statusBadge[app.status]}>{app.status}</Badge>
        </div>

        <div className="space-y-4 p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('platform.priestName')}</p>
              <p className="mt-1 font-medium">{app.priest_name}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('join.mainServantName')}</p>
              <p className="mt-1 font-medium">{app.main_servant_name || '-'}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('join.phone')}</p>
              <p className="mt-1 font-medium">{app.phone || app.priest_phone}</p>
            </div>
            {app.contact_email && (
              <div>
                <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('auth.email')}</p>
                <p className="mt-1">{app.contact_email}</p>
              </div>
            )}
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('platform.date')}</p>
              <p className="mt-1">{app.created_at ? new Date(app.created_at).toLocaleDateString() : '-'}</p>
            </div>
          </div>

          {app.address && (
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('join.churchAddress')}</p>
              <p className="mt-1 text-sm">{app.address}</p>
            </div>
          )}
        </div>
      </div>

      {/* ID Images */}
      {app.front_id_url || app.back_id_url || app.church_permission_doc_url ? (
        <div className="card">
          <h2 className="border-b border-border px-6 py-4 font-semibold">{t('platform.idVerification')}</h2>
          <div className="grid gap-6 p-6 sm:grid-cols-2">
            {app.front_id_url && (
              <div>
                <p className="mb-2 text-sm font-medium">{t('platform.frontId')}</p>
                {filePreview(app.front_id_url, t('platform.frontId'), 'front-id.' + (app.front_id_url.split('.').pop() || 'jpg'))}
              </div>
            )}
            {app.back_id_url && (
              <div>
                <p className="mb-2 text-sm font-medium">{t('platform.backId')}</p>
                {filePreview(app.back_id_url, t('platform.backId'), 'back-id.' + (app.back_id_url.split('.').pop() || 'jpg'))}
              </div>
            )}
            {app.church_permission_doc_url && (
              <div className={app.front_id_url && app.back_id_url ? 'sm:col-span-2' : ''}>
                <p className="mb-2 text-sm font-medium">{t('join.churchPermissionDoc')}</p>
                {filePreview(app.church_permission_doc_url, t('join.churchPermissionDoc'), 'permission-document.' + (app.church_permission_doc_url.split('.').pop() || 'pdf'))}
              </div>
            )}
          </div>
        </div>
      ) : null}

      {/* Rejection Reason */}
      {app.status === 'rejected' && app.rejection_reason && (
        <div className="card border border-danger/20">
          <div className="p-6">
            <div className="flex items-center gap-2 mb-3">
              <XCircle className="h-5 w-5 text-danger" />
              <h3 className="font-semibold text-danger">{t('platform.rejectionReason')}</h3>
            </div>
            <div className="rounded-lg bg-danger-light p-4">
              <p className="text-sm">{app.rejection_reason}</p>
            </div>
            {app.reviewed_by && (
              <p className="mt-3 text-xs text-muted">
                  {t('platform.reviewedBy')}: {app.reviewed_by?.name} &middot; {app.reviewed_at ? new Date(app.reviewed_at).toLocaleString() : ''}
              </p>
            )}
          </div>
        </div>
      )}

      {/* Admin Notes */}
      {app.admin_notes && (
        <div className="card p-6">
          <p className="text-xs font-medium uppercase tracking-wider text-muted">{t('platform.adminNotes')}</p>
          <p className="mt-2 text-sm">{app.admin_notes}</p>
        </div>
      )}

      {/* Actions */}
      {app.status === 'pending' && (
        <div className="flex gap-3">
          <button onClick={handleApprove} disabled={actionLoading} className="flex-1 btn-success btn-md">
            <CheckCircle className="h-4 w-4" /> {t('platform.approve')}
          </button>
          <button onClick={() => setShowRejectModal(true)} disabled={actionLoading} className="flex-1 btn-danger btn-md">
            <XCircle className="h-4 w-4" /> {t('platform.reject')}
          </button>
        </div>
      )}

      <Modal isOpen={showRejectModal} onClose={() => setShowRejectModal(false)} title={t('platform.reject')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => { setShowRejectModal(false); setRejectReason('') }} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleReject} disabled={actionLoading || !rejectReason.trim()} className="flex-1 btn-danger btn-md">
              {t('platform.reject')}
            </button>
          </div>
        }>
        <div className="space-y-3">
          <p className="text-sm text-secondary">{t('platform.rejectConfirm')}</p>
          <div>
            <label className="text-xs font-medium uppercase tracking-wider text-muted">{t('platform.rejectionDescription')} <span className="text-danger">*</span></label>
            <textarea value={rejectReason} onChange={(e) => setRejectReason(e.target.value)}
              placeholder={t('platform.rejectionDescriptionPlaceholder')} className="input-field" rows={3} autoFocus />
            {rejectReason.length > 0 && rejectReason.length < 5 && (
              <p className="mt-1 text-xs text-danger">{t('platform.rejectionMinLength')}</p>
            )}
          </div>
        </div>
      </Modal>

      {/* Zoom Preview Modal */}
      <Modal isOpen={!!previewUrl} onClose={closePreview} title={previewLabel} size="lg">
        {previewUrl && (
          <div className="flex flex-col items-center">
            {isImage(previewUrl) ? (
              <ImageWithFallback src={previewUrl} alt={previewLabel} className="max-h-[70vh] w-full cursor-zoom-out object-contain" onClick={closePreview} />
            ) : (
              <iframe src={previewUrl} className="h-[70vh] w-full rounded-lg border" title={previewLabel} />
            )}
            <div className="mt-4 flex gap-3">
              <a href={previewUrl} target="_blank" rel="noopener noreferrer" className="btn-secondary btn-sm">
                <ExternalLink className="h-4 w-4" /> {t('platform.openInNewTab')}
              </a>
              <button onClick={() => handleDownload(previewUrl, previewLabel.replace(/\s+/g, '-').toLowerCase() + '.' + (previewUrl.split('.').pop() || 'jpg'))} className="btn-primary btn-sm">
                <Download className="h-4 w-4" /> {t('common.download')}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
