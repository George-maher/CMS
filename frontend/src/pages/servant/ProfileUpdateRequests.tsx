import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Clock, FileText, AlertCircle } from 'lucide-react'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import type { ProfileUpdateRequest } from '@/types'
import {
  listProfileUpdateRequests,
  approveProfileUpdateRequest,
  rejectProfileUpdateRequest,
} from '@/api/profileUpdateRequests'
import { logCatch } from '@/lib/debug'
import toast from 'react-hot-toast'

export default function ProfileUpdateRequests() {
  const { t } = useTranslation()
  const [requests, setRequests] = useState<ProfileUpdateRequest[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)

  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<ProfileUpdateRequest | null>(null)

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectId, setRejectId] = useState<number | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [rejecting, setRejecting] = useState(false)

  const [approveOpen, setApproveOpen] = useState(false)
  const [approveId, setApproveId] = useState<number | null>(null)
  const [approving, setApproving] = useState(false)

  const fetchData = async (p = 1) => {
    setLoading(true)
    const params: Record<string, string | number> = { page: p, per_page: 15 }
    if (statusFilter) params.status = statusFilter
    try {
      const res = await listProfileUpdateRequests(params)
      setRequests(res.data)
      setMeta(res.meta)
    } catch (e) {
      logCatch('ProfileUpdateRequests.fetch', e)
      setRequests([])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    Promise.resolve().then(() => setPage(1))
    const params: Record<string, string | number> = { page: 1, per_page: 15 }
    if (statusFilter) params.status = statusFilter
    listProfileUpdateRequests(params).then(res => { setRequests(res.data); setMeta(res.meta) }).catch(() => setRequests([])).finally(() => setLoading(false))
  }, [statusFilter])

  const handlePageChange = (newPage: number) => {
    setPage(newPage)
    fetchData(newPage)
  }

  const openDetail = (req: ProfileUpdateRequest) => {
    setDetail(req)
    setDetailOpen(true)
  }

  const handleApprove = async () => {
    if (!approveId) return
    setApproving(true)
    try {
      await approveProfileUpdateRequest(approveId)
      toast.success(t('profileUpdateRequests.approved'))
      setApproveOpen(false)
      setApproveId(null)
      fetchData(page)
    } catch (e) {
      logCatch('ProfileUpdateRequests.approve', e)
      toast.error(t('profileUpdateRequests.approveFailed'))
    } finally {
      setApproving(false)
    }
  }

  const handleReject = async () => {
    if (!rejectId || !rejectReason.trim()) return
    setRejecting(true)
    try {
      await rejectProfileUpdateRequest(rejectId, rejectReason.trim())
      toast.success(t('profileUpdateRequests.rejected'))
      setRejectOpen(false)
      setRejectId(null)
      setRejectReason('')
      fetchData(page)
    } catch (e) {
      logCatch('ProfileUpdateRequests.reject', e)
      toast.error(t('profileUpdateRequests.rejectFailed'))
    } finally {
      setRejecting(false)
    }
  }

  const statusBadge = (status: string) => {
    switch (status) {
      case 'pending': return <Badge variant="warning"><Clock className="h-3 w-3 mr-1 inline" />{t('common.pending')}</Badge>
      case 'approved': return <Badge variant="success"><CheckCircle className="h-3 w-3 mr-1 inline" />{t('common.approved')}</Badge>
      case 'rejected': return <Badge variant="danger"><XCircle className="h-3 w-3 mr-1 inline" />{t('common.rejected')}</Badge>
      default: return <Badge>{status}</Badge>
    }
  }

  const fieldLabel = (field: string) => {
    const map: Record<string, string> = {
      name: t('auth.name'),
      phone: t('auth.phone'),
      email: t('auth.email'),
      address: t('common.address'),
    }
    return map[field] || field
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('profileUpdateRequests.title')}</h1>
          <p className="text-sm text-muted">{t('profileUpdateRequests.description')}</p>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-2">
        {['', 'pending', 'approved', 'rejected'].map((s) => (
          <button
            key={s}
            onClick={() => setStatusFilter(s)}
            className={`btn-sm ${statusFilter === s ? 'btn-primary' : 'btn-ghost border'}`}
          >
            {s ? t(`common.${s}`) : t('common.all')}
          </button>
        ))}
      </div>

      {/* Table / Cards */}
      {loading ? (
        <LoadingSpinner />
      ) : requests.length === 0 ? (
        <div className="card p-12 text-center">
          <FileText className="h-12 w-12 text-muted mx-auto mb-3" />
          <p className="text-muted">{t('common.noData')}</p>
        </div>
      ) : (
        <>
          {/* Desktop Table */}
          <div className="hidden sm:block card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="table">
                <thead>
                  <tr>
                    <th>{t('profileUpdateRequests.member')}</th>
                    <th>{t('profileUpdateRequests.class')}</th>
                    <th>{t('common.status')}</th>
                    <th>{t('profileUpdateRequests.submittedAt')}</th>
                    <th>{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {requests.map((req) => (
                    <tr key={req.id}>
                      <td>
                        <div className="flex items-center gap-2">
                          <div className="h-8 w-8 rounded-full gold-gradient flex items-center justify-center text-sm font-bold text-navy-900">
                            {req.user?.name?.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <p className="font-medium text-sm">{req.user?.name}</p>
                            <p className="text-xs text-muted">{req.user?.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="text-sm">{req.user?.classe?.name || '-'}</td>
                      <td>{statusBadge(req.status)}</td>
                      <td className="text-sm text-muted">{new Date(req.created_at).toLocaleDateString()}</td>
                      <td>
                        <button onClick={() => openDetail(req)} className="btn-ghost btn-sm text-primary-600">
                          {t('common.view')}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Mobile Cards */}
          <div className="sm:hidden space-y-3">
            {requests.map((req) => (
              <div
                key={req.id}
                onClick={() => openDetail(req)}
                className="card p-4 cursor-pointer hover:bg-surface-hover transition-colors"
              >
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-2">
                    <div className="h-8 w-8 rounded-full gold-gradient flex items-center justify-center text-sm font-bold text-navy-900">
                      {req.user?.name?.charAt(0).toUpperCase()}
                    </div>
                    <span className="font-medium text-sm">{req.user?.name}</span>
                  </div>
                  {statusBadge(req.status)}
                </div>
                <p className="text-xs text-muted">{req.user?.classe?.name} · {new Date(req.created_at).toLocaleDateString()}</p>
              </div>
            ))}
          </div>

          {/* Pagination */}
          {meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2">
              <button
                onClick={() => handlePageChange(page - 1)}
                disabled={page <= 1}
                className="btn-ghost btn-sm"
              >
                {t('common.prev')}
              </button>
              <span className="text-sm text-muted">{t('common.page')} {page} {t('common.of')} {meta.last_page}</span>
              <button
                onClick={() => handlePageChange(page + 1)}
                disabled={page >= meta.last_page}
                className="btn-ghost btn-sm"
              >
                {t('common.next')}
              </button>
            </div>
          )}
        </>
      )}

      {/* Detail Modal */}
      <Modal isOpen={detailOpen} onClose={() => setDetailOpen(false)} title={t('profileUpdateRequests.requestDetail')}>
        {detail && (
          <div className="space-y-4">
            {/* Member info */}
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full gold-gradient flex items-center justify-center text-sm font-bold text-navy-900">
                {detail.user?.name?.charAt(0).toUpperCase()}
              </div>
              <div>
                <p className="font-semibold">{detail.user?.name}</p>
                <p className="text-sm text-muted">{detail.user?.classe?.name} {detail.user?.classe?.stage ? `· ${detail.user.classe.stage.name}` : ''}</p>
              </div>
            </div>

            <div className="flex items-center gap-2">
              {statusBadge(detail.status)}
              <span className="text-sm text-muted">{new Date(detail.created_at).toLocaleString()}</span>
            </div>

            {/* Changes */}
            <div className="space-y-3">
              <h3 className="text-sm font-semibold">{t('profileUpdateRequests.requestedChanges')}</h3>
              {Object.entries(detail.changes || {}).map(([field, change]) => (
                <div key={field} className="p-3 rounded-lg bg-surface border border-border">
                  <p className="text-sm font-medium mb-1">{fieldLabel(field)}</p>
                  <div className="grid grid-cols-2 gap-2 text-sm">
                    <div>
                      <span className="text-muted">{t('profileUpdateRequests.current')}: </span>
                      <span className="line-through text-muted">{change.old || '-'}</span>
                    </div>
                    <div>
                      <span className="text-muted">{t('profileUpdateRequests.new')}: </span>
                      <span className="font-medium text-success-600">{change.new || '-'}</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            {detail.rejection_reason && (
              <div className="p-3 rounded-lg bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800">
                <p className="text-sm font-medium text-danger-700 dark:text-danger-300">{t('profileUpdateRequests.rejectionReason')}:</p>
                <p className="text-sm text-danger-600 dark:text-danger-400 mt-1">{detail.rejection_reason}</p>
              </div>
            )}

            {/* Actions */}
            {detail.status === 'pending' && (
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => { setApproveOpen(true); setApproveId(detail.id); setDetailOpen(false) }}
                  className="flex-1 btn-primary bg-success hover:bg-success/90"
                >
                  <CheckCircle className="h-4 w-4 mr-2" />{t('common.approve')}
                </button>
                <button
                  onClick={() => { setRejectOpen(true); setRejectId(detail.id); setDetailOpen(false) }}
                  className="flex-1 btn-primary bg-danger hover:bg-danger/90"
                >
                  <XCircle className="h-4 w-4 mr-2" />{t('common.reject')}
                </button>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Approve Confirmation Modal */}
      <Modal isOpen={approveOpen} onClose={() => { setApproveOpen(false); setApproveId(null) }} title={t('profileUpdateRequests.confirmApprove')}>
        <div className="space-y-4">
          <div className="flex items-center gap-2 p-3 rounded-lg bg-success-50 dark:bg-success-900/20 border border-success-200 dark:border-success-800">
            <AlertCircle className="h-5 w-5 text-success-600 dark:text-success-400" />
            <p className="text-sm text-success-700 dark:text-success-300">{t('profileUpdateRequests.approveWarning')}</p>
          </div>
          <div className="flex gap-2">
            <button onClick={() => { setApproveOpen(false); setApproveId(null) }} className="flex-1 btn-ghost border">{t('common.cancel')}</button>
            <button onClick={handleApprove} disabled={approving} className="flex-1 btn-primary bg-success hover:bg-success/90">
              {approving ? t('common.loading') : t('common.confirm')}
            </button>
          </div>
        </div>
      </Modal>

      {/* Reject Modal */}
      <Modal isOpen={rejectOpen} onClose={() => { setRejectOpen(false); setRejectId(null); setRejectReason('') }} title={t('profileUpdateRequests.confirmReject')}>
        <div className="space-y-4">
          <div>
            <label className="label">{t('profileUpdateRequests.rejectionReason')} *</label>
            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              className="input min-h-[100px]"
              placeholder={t('profileUpdateRequests.rejectionReasonPlaceholder')}
              rows={4}
            />
          </div>
          <div className="flex gap-2">
            <button onClick={() => { setRejectOpen(false); setRejectId(null); setRejectReason('') }} className="flex-1 btn-ghost border">{t('common.cancel')}</button>
            <button onClick={handleReject} disabled={rejecting || !rejectReason.trim()} className="flex-1 btn-primary bg-danger hover:bg-danger/90">
              {rejecting ? t('common.loading') : t('common.reject')}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
