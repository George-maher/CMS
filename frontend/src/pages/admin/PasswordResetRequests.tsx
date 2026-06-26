import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Clock, Search, FileText, Phone, MessageSquare } from 'lucide-react'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import type { PasswordResetRequest } from '@/types'
import {
  listPasswordResetRequests,
  approvePasswordResetRequest,
  rejectPasswordResetRequest,
} from '@/api/passwordResetRequests'
import toast from 'react-hot-toast'

export default function AdminPasswordResetRequests() {
  const { t } = useTranslation()
  const [requests, setRequests] = useState<PasswordResetRequest[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)

  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<PasswordResetRequest | null>(null)

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectId, setRejectId] = useState<number | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [rejecting, setRejecting] = useState(false)

  const [approveId, setApproveId] = useState<number | null>(null)
  const [approveOpen, setApproveOpen] = useState(false)
  const [approving, setApproving] = useState(false)

  const fetch = async (p = 1) => {
    setLoading(true)
    const params: Record<string, string | number> = { page: p, per_page: 15 }
    if (statusFilter) params.status = statusFilter
    try {
      const res = await listPasswordResetRequests(params)
      setRequests(res.data)
      setMeta(res.meta)
    } catch {
      setRequests([])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    setPage(1)
    fetch(1)
  }, [statusFilter])

  const handlePageChange = (newPage: number) => {
    setPage(newPage)
    fetch(newPage)
  }

  const openDetail = (req: PasswordResetRequest) => {
    setDetail(req)
    setDetailOpen(true)
  }

  const handleApprove = async () => {
    if (!approveId) return
    setApproving(true)
    try {
      await approvePasswordResetRequest(approveId)
      toast.success(t('passwordResetRequests.approved'))
      setApproveOpen(false)
      setApproveId(null)
      fetch(page)
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setApproving(false)
    }
  }

  const handleReject = async () => {
    if (!rejectId || !rejectReason.trim()) return
    setRejecting(true)
    try {
      await rejectPasswordResetRequest(rejectId, rejectReason)
      toast.success(t('passwordResetRequests.rejected'))
      setRejectOpen(false)
      setRejectId(null)
      setRejectReason('')
      fetch(page)
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setRejecting(false)
    }
  }

  const statusBadge = (status: string) => {
    switch (status) {
      case 'pending':
        return <Badge variant="warning">{t('passwordResetRequests.pending')}</Badge>
      case 'approved':
        return <Badge variant="success">{t('passwordResetRequests.approved')}</Badge>
      case 'rejected':
        return <Badge variant="danger">{t('passwordResetRequests.rejected')}</Badge>
      default:
        return <Badge>{status}</Badge>
    }
  }

  const formatDate = (d: string) => {
    return new Date(d).toLocaleDateString(undefined, {
      month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p className="text-sm text-secondary">{meta.total} {t('passwordResetRequests.total')}</p>
        <div className="flex flex-wrap gap-1.5">
          {['', 'pending', 'approved', 'rejected'].map((s) => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className={`btn-sm text-xs sm:text-sm ${statusFilter === s ? 'btn-primary' : 'btn-ghost'}`}
            >
              {s ? t(`passwordResetRequests.${s}`) : t('common.all')}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <LoadingSpinner className="py-20" />
      ) : requests.length === 0 ? (
        <div className="card py-12 text-center">
          <FileText className="h-12 w-12 mx-auto text-muted mb-3" />
          <p className="text-muted">{t('passwordResetRequests.noRequests')}</p>
        </div>
      ) : (
        <div className="space-y-3">
          {requests.map((req) => (
            <div key={req.id} className="card p-4 flex items-center justify-between gap-4">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <h3 className="font-semibold">{req.user?.name || req.email}</h3>
                  {statusBadge(req.status)}
                </div>
                <p className="text-sm text-secondary mt-0.5">{req.email}</p>
                <div className="flex items-center gap-3 text-xs text-muted mt-1 flex-wrap">
                  <span>{req.user?.role_label}</span>
                  {req.user?.phone && (
                    <span className="inline-flex items-center gap-1">
                      <Phone className="h-3 w-3" />
                      {req.user.phone}
                    </span>
                  )}
                  {req.user?.classe?.name && (
                    <span>{req.user.classe.name}</span>
                  )}
                  {req.user?.classe?.stage?.name && (
                    <span>{req.user.classe.stage.name}</span>
                  )}
                </div>
                <p className="text-xs text-muted mt-1">
                  <Clock className="inline h-3 w-3 mr-0.5" />
                  {formatDate(req.created_at)}
                </p>
                {req.notes && (
                  <p className="text-xs text-muted mt-1 italic line-clamp-1">
                    &quot;{req.notes}&quot;
                  </p>
                )}
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <button
                  onClick={() => openDetail(req)}
                  className="btn-icon btn-ghost"
                  title={t('common.view')}
                >
                  <Search className="h-4 w-4" />
                </button>
                {req.status === 'pending' && (
                  <>
                    <button
                      onClick={() => { setApproveId(req.id); setApproveOpen(true) }}
                      className="btn-icon btn-ghost text-green-600"
                      title={t('passwordResetRequests.approve')}
                    >
                      <CheckCircle className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => { setRejectId(req.id); setRejectOpen(true) }}
                      className="btn-icon btn-ghost text-red-600"
                      title={t('passwordResetRequests.reject')}
                    >
                      <XCircle className="h-4 w-4" />
                    </button>
                  </>
                )}
              </div>
            </div>
          ))}

          {meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2 pt-4">
              <button
                disabled={page <= 1}
                onClick={() => handlePageChange(page - 1)}
                className="btn-ghost btn-sm"
              >
                {t('common.prev')}
              </button>
              <span className="text-sm text-muted">
                {t('common.page')} {page} {t('common.of')} {meta.last_page}
              </span>
              <button
                disabled={page >= meta.last_page}
                onClick={() => handlePageChange(page + 1)}
                className="btn-ghost btn-sm"
              >
                {t('common.next')}
              </button>
            </div>
          )}
        </div>
      )}

      <Modal
        isOpen={detailOpen}
        onClose={() => setDetailOpen(false)}
        title={detail?.user?.name ?? detail?.email ?? ''}
      >
        {detail && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <p className="text-xs text-muted">{t('auth.email')}</p>
                <p className="text-sm font-medium">{detail.email}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('users.role')}</p>
                <p className="text-sm font-medium">{detail.user?.role_label || '-'}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('users.phone')}</p>
                <p className="text-sm font-medium">{detail.user?.phone || '-'}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('users.class')}</p>
                <p className="text-sm font-medium">{detail.user?.classe?.name || '-'}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('users.stage')}</p>
                <p className="text-sm font-medium">{detail.user?.classe?.stage?.name || '-'}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('passwordResetRequests.status')}</p>
                <div className="mt-0.5">{statusBadge(detail.status)}</div>
              </div>
              <div>
                <p className="text-xs text-muted">{t('common.createdAt')}</p>
                <p className="text-sm font-medium">{formatDate(detail.created_at)}</p>
              </div>
              <div>
                <p className="text-xs text-muted">{t('users.memberId')}</p>
                <p className="text-sm font-medium">{detail.user?.member_id || '-'}</p>
              </div>
            </div>

            {detail.notes && (
              <div>
                <p className="text-xs text-muted flex items-center gap-1">
                  <MessageSquare className="h-3 w-3" />
                  {t('passwordResetRequests.notes')}
                </p>
                <p className="text-sm whitespace-pre-wrap mt-1">{detail.notes}</p>
              </div>
            )}

            {detail.reviewer && (
              <div>
                <p className="text-xs text-muted">{t('passwordResetRequests.reviewedBy')}</p>
                <p className="text-sm font-medium">{detail.reviewer.name}</p>
              </div>
            )}

            {detail.rejection_reason && (
              <div>
                <p className="text-xs text-muted">{t('passwordResetRequests.rejectionReason')}</p>
                <p className="text-sm text-danger-dark whitespace-pre-wrap">{detail.rejection_reason}</p>
              </div>
            )}

            {detail.user?.avatar && (
              <div>
                <p className="text-xs text-muted mb-1">{t('users.avatar')}</p>
                <img src={detail.user.avatar} alt="" className="h-20 w-20 rounded-lg object-cover border" />
              </div>
            )}

            {detail.status === 'pending' && (
              <div className="flex gap-3 pt-4 border-t">
                <button
                  onClick={() => { setDetailOpen(false); setApproveId(detail.id); setApproveOpen(true) }}
                  className="flex-1 btn-primary btn-md"
                >
                  <CheckCircle className="h-4 w-4" />
                  {t('passwordResetRequests.approve')}
                </button>
                <button
                  onClick={() => { setDetailOpen(false); setRejectId(detail.id); setRejectOpen(true) }}
                  className="flex-1 btn-secondary btn-md text-danger"
                >
                  <XCircle className="h-4 w-4" />
                  {t('passwordResetRequests.reject')}
                </button>
              </div>
            )}
          </div>
        )}
      </Modal>

      <Modal
        isOpen={approveOpen}
        onClose={() => { setApproveOpen(false); setApproveId(null) }}
        title={t('passwordResetRequests.confirmApprove')}
        footer={
          <div className="flex gap-3 w-full">
            <button
              onClick={() => { setApproveOpen(false); setApproveId(null) }}
              className="flex-1 btn-secondary btn-md"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleApprove}
              disabled={approving}
              className="flex-1 btn-primary btn-md"
            >
              {approving ? t('common.saving') : t('passwordResetRequests.approve')}
            </button>
          </div>
        }
      >
        <p className="text-secondary">{t('passwordResetRequests.approveConfirm')}</p>
      </Modal>

      <Modal
        isOpen={rejectOpen}
        onClose={() => { setRejectOpen(false); setRejectId(null); setRejectReason('') }}
        title={t('passwordResetRequests.confirmReject')}
        footer={
          <div className="flex gap-3 w-full">
            <button
              onClick={() => { setRejectOpen(false); setRejectId(null); setRejectReason('') }}
              className="flex-1 btn-secondary btn-md"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleReject}
              disabled={rejecting || !rejectReason.trim()}
              className="flex-1 btn-danger btn-md"
            >
              {rejecting ? t('common.saving') : t('passwordResetRequests.reject')}
            </button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-secondary">{t('passwordResetRequests.rejectConfirm')}</p>
          <textarea
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            className="input-field"
            rows={3}
            placeholder={t('passwordResetRequests.rejectionReasonPlaceholder')}
            required
          />
        </div>
      </Modal>
    </div>
  )
}
