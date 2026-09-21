import { fmtDate, fmtDateTime } from '@/lib/dates'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, Clock, FileText, AlertCircle, UserCheck, Eye, User, Phone, Mail, MapPin, ArrowRight } from 'lucide-react'
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

const statusTabs = [
  { value: '', labelKey: 'common.all' },
  { value: 'pending', labelKey: 'common.pending' },
  { value: 'approved', labelKey: 'common.approved' },
  { value: 'rejected', labelKey: 'common.rejected' },
] as const

const fieldIcons: Record<string, typeof User> = {
  name: User,
  phone: Phone,
  email: Mail,
  address: MapPin,
}

export default function ProfileUpdateRequests() {
  const { t } = useTranslation()
  const [requests, setRequests] = useState<ProfileUpdateRequest[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)
  const hasLoadedRef = useRef(false)

  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<ProfileUpdateRequest | null>(null)

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectId, setRejectId] = useState<number | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [rejecting, setRejecting] = useState(false)

  const [approveOpen, setApproveOpen] = useState(false)
  const [approveId, setApproveId] = useState<number | null>(null)
  const [approving, setApproving] = useState(false)

  const fetchData = useCallback(async (p: number, filter: string, showSpinner: boolean) => {
    if (showSpinner) setLoading(true)
    const params: Record<string, string | number> = { page: p, per_page: 15 }
    if (filter) params.status = filter
    try {
      const res = await listProfileUpdateRequests(params)
      setRequests(res.data)
      setMeta(res.meta)
    } catch (e) {
      logCatch('ProfileUpdateRequests.fetch', e)
      setRequests([])
    } finally {
      setLoading(false)
      hasLoadedRef.current = true
    }
  }, [])

  useEffect(() => {
    fetchData(1, statusFilter, !hasLoadedRef.current)
  }, [statusFilter, fetchData])

  const handlePageChange = (newPage: number) => {
    setPage(newPage)
    fetchData(newPage, statusFilter, false)
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
      fetchData(page, statusFilter, false)
    } catch (e: unknown) {
      logCatch('ProfileUpdateRequests.approve', e)
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
        || t('profileUpdateRequests.approveFailed')
      toast.error(msg)
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
      fetchData(page, statusFilter, false)
    } catch (e: unknown) {
      logCatch('ProfileUpdateRequests.reject', e)
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
        || t('profileUpdateRequests.rejectFailed')
      toast.error(msg)
    } finally {
      setRejecting(false)
    }
  }

  const statusBadge = (status: string) => {
    switch (status) {
      case 'pending':
        return (
          <Badge variant="warning">
            <Clock className="h-3 w-3 me-1 inline" />
            {t('common.pending')}
          </Badge>
        )
      case 'approved':
        return (
          <Badge variant="success">
            <CheckCircle className="h-3 w-3 me-1 inline" />
            {t('common.approved')}
          </Badge>
        )
      case 'rejected':
        return (
          <Badge variant="danger">
            <XCircle className="h-3 w-3 me-1 inline" />
            {t('common.rejected')}
          </Badge>
        )
      default:
        return <Badge>{status}</Badge>
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

  const pendingCount = requests.filter((r) => r.status === 'pending').length

  return (
    <div className="space-y-5 sm:space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 sm:h-12 sm:w-12 items-center justify-center rounded-xl gold-gradient shadow-md">
            <UserCheck className="h-5 w-5 sm:h-6 sm:w-6 text-navy-900" />
          </div>
          <div>
            <h1 className="text-xl font-bold sm:text-2xl">{t('profileUpdateRequests.title')}</h1>
            <p className="text-sm text-muted">{t('profileUpdateRequests.description')}</p>
          </div>
        </div>
        {pendingCount > 0 && (
          <div className="flex items-center gap-2 rounded-xl bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 px-3 py-2.5 shadow-sm">
            <AlertCircle className="h-4 w-4 text-warning-600 dark:text-warning-400 shrink-0" />
            <span className="text-sm font-medium text-warning-700 dark:text-warning-300">
              {pendingCount} {t('common.pending')}
            </span>
          </div>
        )}
      </div>

      {/* Status Filter Tabs — uses btn-primary / btn-ghost (CSS classes, not broken bg-primary utility) */}
      <div className="flex flex-wrap gap-1.5 sm:gap-2">
        {statusTabs.map(({ value, labelKey }) => (
          <button
            key={value}
            onClick={() => setStatusFilter(value)}
            className={`btn-sm text-xs sm:text-sm ${statusFilter === value ? 'btn-primary' : 'btn-ghost border border-border'}`}
          >
            {t(labelKey)}
          </button>
        ))}
      </div>

      {/* Content */}
      {loading ? (
        <LoadingSpinner className="py-16" />
      ) : requests.length === 0 ? (
        <div className="card p-12 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-surface-secondary mb-4">
            <FileText className="h-8 w-8 text-muted" />
          </div>
          <p className="text-lg font-medium text-secondary">{t('common.noData')}</p>
          <p className="mt-1 text-sm text-muted">
            {statusFilter
              ? t('profileUpdateRequests.noRequests')
              : t('profileUpdateRequests.noRequestsYet')}
          </p>
        </div>
      ) : (
        <>
          {/* Request Cards */}
          <div className="space-y-3">
            {requests.map((req) => (
              <div key={req.id} className="card overflow-hidden">
                {/* Card Header */}
                <div className="flex items-start justify-between gap-3 p-4 sm:p-5">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-full gold-gradient text-sm font-bold text-navy-900 ring-2 ring-gold-400/20 shadow-sm">
                      {req.user?.name?.charAt(0).toUpperCase()}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-semibold text-sm sm:text-base truncate">{req.user?.name}</p>
                        {statusBadge(req.status)}
                      </div>
                      <p className="text-xs sm:text-sm text-muted truncate">{req.user?.email}</p>
                      <div className="flex items-center gap-2 text-xs text-muted mt-0.5 flex-wrap">
                        {req.user?.classe?.name && <span>{req.user.classe.name}</span>}
                        {req.user?.classe?.stage?.name && (
                          <>
                            <span className="text-border">·</span>
                            <span>{req.user.classe.stage.name}</span>
                          </>
                        )}
                        <span className="text-border">·</span>
                        <span className="inline-flex items-center gap-1">
                          <Clock className="h-3 w-3" />
                          {fmtDate(new Date(req.created_at))}
                        </span>
                      </div>
                    </div>
                  </div>
                  <button
                    onClick={() => openDetail(req)}
                    className="shrink-0 btn-icon btn-ghost"
                    title={t('common.view')}
                  >
                    <Eye className="h-4 w-4" />
                  </button>
                </div>

                {/* Changed Fields Summary */}
                {req.changes && Object.keys(req.changes).length > 0 && (
                  <div className="border-t border-border px-4 sm:px-5 py-3 bg-surface-secondary/50">
                    <p className="text-xs font-semibold text-muted uppercase tracking-wide mb-2">
                      {t('profileUpdateRequests.requestedChanges')}
                    </p>
                    <div className="space-y-1.5">
                      {Object.entries(req.changes).map(([field, change]) => {
                        const Icon = fieldIcons[field] || FileText
                        return (
                          <div key={field} className="flex items-center gap-2 text-sm">
                            <Icon className="h-3.5 w-3.5 text-muted shrink-0" />
                            <span className="font-medium text-secondary shrink-0">{fieldLabel(field)}:</span>
                            <span className="text-muted line-through text-xs sm:text-sm truncate">{change.old || '-'}</span>
                            <ArrowRight className="h-3 w-3 text-muted shrink-0 rtl-flip" />
                            <span className="font-medium text-success-700 dark:text-success-400 text-xs sm:text-sm truncate">{change.new || '-'}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                )}

                {/* Card Actions — only for pending */}
                {req.status === 'pending' && (
                  <div className="border-t border-border px-4 sm:px-5 py-3 flex items-center justify-end gap-2">
                    <button
                      onClick={() => {
                        setRejectOpen(true)
                        setRejectId(req.id)
                      }}
                      className="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold text-danger hover:bg-danger-50 dark:hover:bg-danger-900/20 border border-danger-200 dark:border-danger-800 transition-colors"
                    >
                      <XCircle className="h-4 w-4" />
                      {t('common.reject')}
                    </button>
                    <button
                      onClick={() => {
                        setApproveOpen(true)
                        setApproveId(req.id)
                      }}
                      className="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold text-white bg-success hover:bg-success/90 shadow-sm shadow-success/25 transition-colors"
                    >
                      <CheckCircle className="h-4 w-4" />
                      {t('common.approve')}
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Pagination */}
          {meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2 pt-2">
              <button
                onClick={() => handlePageChange(page - 1)}
                disabled={page <= 1}
                className="btn-ghost btn-sm border"
              >
                {t('common.prev')}
              </button>
              <span className="px-4 text-sm font-medium text-muted">
                {t('common.page')} {page} / {meta.last_page}
              </span>
              <button
                onClick={() => handlePageChange(page + 1)}
                disabled={page >= meta.last_page}
                className="btn-ghost btn-sm border"
              >
                {t('common.next')}
              </button>
            </div>
          )}
        </>
      )}

      {/* Detail Modal */}
      <Modal
        isOpen={detailOpen}
        onClose={() => setDetailOpen(false)}
        title={t('profileUpdateRequests.requestDetail')}
      >
        {detail && (
          <div className="space-y-5">
            {/* Member Info Header */}
            <div className="flex items-center gap-4 rounded-xl bg-surface-secondary/50 p-4 border border-border-light">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full gold-gradient text-xl font-bold text-navy-900 ring-2 ring-gold-400/20 shadow-md">
                {detail.user?.name?.charAt(0).toUpperCase()}
              </div>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-lg truncate">{detail.user?.name}</p>
                <p className="text-sm text-muted truncate">{detail.user?.email}</p>
                <div className="flex items-center gap-2 mt-1 text-sm">
                  {detail.user?.classe?.name && <span className="font-medium">{detail.user.classe.name}</span>}
                  {detail.user?.classe?.stage && (
                    <>
                      <span className="text-muted">·</span>
                      <span className="text-muted">{detail.user.classe.stage.name}</span>
                    </>
                  )}
                </div>
              </div>
            </div>

            {/* Status & Date */}
            <div className="flex items-center gap-3 flex-wrap">
              {statusBadge(detail.status)}
              <span className="text-sm text-muted">
                {fmtDateTime(new Date(detail.created_at))}
              </span>
            </div>

            {/* Changes */}
            <div className="space-y-3">
              <h3 className="text-sm font-bold text-secondary uppercase tracking-wide">
                {t('profileUpdateRequests.requestedChanges')}
              </h3>
              {Object.entries(detail.changes || {}).map(([field, change]) => {
                const Icon = fieldIcons[field] || FileText
                return (
                  <div
                    key={field}
                    className="rounded-xl border border-border bg-surface p-4 space-y-3"
                  >
                    <div className="flex items-center gap-2">
                      <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-surface-secondary">
                        <Icon className="h-3.5 w-3.5 text-muted" />
                      </div>
                      <p className="text-xs font-bold text-muted uppercase tracking-wide">
                        {fieldLabel(field)}
                      </p>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                      <div className="rounded-lg bg-surface-secondary/50 p-3 border border-border-light">
                        <p className="text-xs text-muted mb-1 font-medium">
                          {t('profileUpdateRequests.current')}
                        </p>
                        <p className="text-muted break-words">
                          {change.old || '-'}
                        </p>
                      </div>
                      <div className="rounded-lg bg-success-50 dark:bg-success-900/20 p-3 border border-success-200 dark:border-success-800">
                        <p className="text-xs text-success-600 dark:text-success-400 mb-1 font-medium">
                          {t('profileUpdateRequests.new')}
                        </p>
                        <p className="font-semibold text-success-700 dark:text-success-300 break-words">
                          {change.new || '-'}
                        </p>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>

            {/* Rejection Reason */}
            {detail.rejection_reason && (
              <div className="rounded-xl bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800 p-4">
                <p className="text-sm font-bold text-danger-700 dark:text-danger-300 mb-1">
                  {t('profileUpdateRequests.rejectionReason')}
                </p>
                <p className="text-sm text-danger-600 dark:text-danger-400">
                  {detail.rejection_reason}
                </p>
              </div>
            )}

            {/* Reviewer */}
            {detail.reviewer && (
              <p className="text-xs text-muted">
                {t('profileUpdateRequests.reviewedBy')} <span className="font-semibold">{detail.reviewer.name}</span>
              </p>
            )}

            {/* Actions */}
            {detail.status === 'pending' && (
              <div className="flex gap-3 pt-2 border-t border-border">
                <button
                  onClick={() => {
                    setApproveOpen(true)
                    setApproveId(detail.id)
                    setDetailOpen(false)
                  }}
                  className="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-success px-4 py-3 text-sm font-bold text-white hover:bg-success/90 transition-all shadow-md shadow-success/25 hover:shadow-lg hover:shadow-success/30"
                >
                  <CheckCircle className="h-4 w-4" />
                  {t('common.approve')}
                </button>
                <button
                  onClick={() => {
                    setRejectOpen(true)
                    setRejectId(detail.id)
                    setDetailOpen(false)
                  }}
                  className="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger px-4 py-3 text-sm font-bold text-white hover:bg-danger/90 transition-all shadow-md shadow-danger/25 hover:shadow-lg hover:shadow-danger/30"
                >
                  <XCircle className="h-4 w-4" />
                  {t('common.reject')}
                </button>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Approve Confirmation Modal */}
      <Modal
        isOpen={approveOpen}
        onClose={() => {
          setApproveOpen(false)
          setApproveId(null)
        }}
        title={t('profileUpdateRequests.confirmApprove')}
      >
        <div className="space-y-4">
          <div className="flex items-start gap-3 rounded-xl bg-success-50 dark:bg-success-900/20 border border-success-200 dark:border-success-800 p-4">
            <AlertCircle className="h-5 w-5 text-success-600 dark:text-success-400 shrink-0 mt-0.5" />
            <p className="text-sm text-success-700 dark:text-success-300">
              {t('profileUpdateRequests.approveWarning')}
            </p>
          </div>
          <div className="flex gap-3">
            <button
              onClick={() => {
                setApproveOpen(false)
                setApproveId(null)
              }}
              className="flex-1 btn-ghost border rounded-xl"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleApprove}
              disabled={approving}
              className="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-success px-4 py-2.5 text-sm font-bold text-white hover:bg-success/90 transition-colors disabled:opacity-50"
            >
              {approving ? t('common.loading') : t('common.confirm')}
            </button>
          </div>
        </div>
      </Modal>

      {/* Reject Modal */}
      <Modal
        isOpen={rejectOpen}
        onClose={() => {
          setRejectOpen(false)
          setRejectId(null)
          setRejectReason('')
        }}
        title={t('profileUpdateRequests.confirmReject')}
      >
        <div className="space-y-4">
          <div>
            <label className="label font-semibold">{t('profileUpdateRequests.rejectionReason')} *</label>
            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              className="input min-h-[120px] rounded-xl"
              placeholder={t('profileUpdateRequests.rejectionReasonPlaceholder')}
              rows={4}
            />
          </div>
          <div className="flex gap-3">
            <button
              onClick={() => {
                setRejectOpen(false)
                setRejectId(null)
                setRejectReason('')
              }}
              className="flex-1 btn-ghost border rounded-xl"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleReject}
              disabled={rejecting || !rejectReason.trim()}
              className="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger px-4 py-2.5 text-sm font-bold text-white hover:bg-danger/90 transition-colors disabled:opacity-50"
            >
              {rejecting ? t('common.loading') : t('common.reject')}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
