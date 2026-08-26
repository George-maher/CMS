import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ChevronDown, ChevronUp, Mail, Phone } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import type { EventRegistration } from '@/types'
import {
  approveReservation,
  listEventReservationRequests,
  rejectReservation,
} from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'
import {
  registrationStatusLabelKey,
  registrationStatusVariant,
} from './eventStatus'

interface Props {
  eventId: number
}

/** Statuses the responsible servant can still approve/reject. */
const REVIEWABLE_STATUSES = ['pending', 'booked', 'not_reserved', 'thinking']

export default function EventReservationRequestsTab({ eventId }: Props) {
  const { t } = useTranslation()
  const [requests, setRequests] = useState<EventRegistration[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [expandedId, setExpandedId] = useState<number | null>(null)
  const [approveTarget, setApproveTarget] = useState<EventRegistration | null>(null)
  const [rejectTarget, setRejectTarget] = useState<EventRegistration | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const [busy, setBusy] = useState(false)

  const fetch = useCallback(async () => {
    setLoading(true)
    try {
      const data = await listEventReservationRequests(eventId)
      setRequests(data)
    } catch (e) {
      logCatch('EventReservationRequestsTab.load', e)
      toast.error(t('eventMgmt.actionFailed'))
    } finally {
      setLoading(false)
    }
  }, [eventId, t])

  useEffect(() => {
    void Promise.resolve().then(fetch)
  }, [fetch])

  /** Replace the reviewed item in place — no full refetch needed. */
  const applyUpdate = useCallback((updated: EventRegistration) => {
    setRequests((prev) => prev.map((r) => (r.id === updated.id ? { ...r, ...updated } : r)))
  }, [])

  const handleApprove = async () => {
    if (!approveTarget) return
    setBusy(true)
    try {
      const updated = await approveReservation(eventId, approveTarget.id)
      applyUpdate(updated)
      toast.success(t('eventMgmt.approved'))
      setApproveTarget(null)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setBusy(false)
    }
  }

  const handleReject = async () => {
    if (!rejectTarget) return
    setBusy(true)
    try {
      const updated = await rejectReservation(eventId, rejectTarget.id, rejectReason.trim() || undefined)
      applyUpdate(updated)
      toast.success(t('eventMgmt.rejected'))
      setRejectTarget(null)
      setRejectReason('')
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setBusy(false)
    }
  }

  const filtered = statusFilter
    ? requests.filter((r) => r.status === statusFilter)
    : requests

  const counts = {
    booked: requests.filter((r) => r.status === 'booked').length,
    not_reserved: requests.filter((r) => r.status === 'not_reserved').length,
    thinking: requests.filter((r) => r.status === 'thinking').length,
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-10">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <button
          onClick={() => setStatusFilter('')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
            statusFilter === '' ? 'bg-primary text-white' : 'bg-surface-tertiary text-secondary hover:text-primary'
          }`}
        >
          {t('eventMgmt.allStatuses')} ({requests.length})
        </button>
        <button
          onClick={() => setStatusFilter('booked')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
            statusFilter === 'booked' ? 'bg-success text-white' : 'bg-surface-tertiary text-secondary hover:text-primary'
          }`}
        >
          {t('eventMgmt.reg_booked')} ({counts.booked})
        </button>
        <button
          onClick={() => setStatusFilter('not_reserved')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
            statusFilter === 'not_reserved' ? 'bg-warning text-white' : 'bg-surface-tertiary text-secondary hover:text-primary'
          }`}
        >
          {t('eventMgmt.reg_not_reserved')} ({counts.not_reserved})
        </button>
        <button
          onClick={() => setStatusFilter('thinking')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
            statusFilter === 'thinking' ? 'bg-info text-white' : 'bg-surface-tertiary text-secondary hover:text-primary'
          }`}
        >
          {t('eventMgmt.reg_thinking')} ({counts.thinking})
        </button>
      </div>

      {filtered.length === 0 ? (
        <p className="py-6 text-center text-sm text-secondary">{t('eventMgmt.noParticipants')}</p>
      ) : (
        <div className="space-y-2">
          {filtered.map((r) => {
            const canReview = REVIEWABLE_STATUSES.includes(r.status)
            return (
              <div
                key={r.id}
                className="rounded-xl border border-border bg-surface overflow-hidden"
              >
                <button
                  onClick={() => setExpandedId(expandedId === r.id ? null : r.id)}
                  className="flex w-full items-center justify-between px-4 py-3 text-start hover:bg-surface-tertiary transition-colors"
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{r.user?.name}</p>
                      <p className="truncate text-xs text-secondary">
                        {[r.user?.phone, r.user?.email].filter(Boolean).join(' · ') || (r.user?.class_name ?? '')}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Badge variant={registrationStatusVariant[r.status]}>
                      {t(registrationStatusLabelKey(r.status))}
                    </Badge>
                    {expandedId === r.id ? (
                      <ChevronUp className="h-4 w-4 text-secondary" />
                    ) : (
                      <ChevronDown className="h-4 w-4 text-secondary" />
                    )}
                  </div>
                </button>

                {expandedId === r.id && (
                  <div className="border-t border-border px-4 py-3 space-y-3 bg-background text-sm">
                    {/* Member information — resolved from the account by the backend */}
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                      <div>
                        <span className="font-medium text-secondary">{t('auth.name')}: </span>
                        {r.user?.name}
                      </div>
                      <div>
                        <span className="font-medium text-secondary">{t('eventMgmt.registration')}: </span>
                        <Badge variant={registrationStatusVariant[r.status]}>
                          {t(registrationStatusLabelKey(r.status))}
                        </Badge>
                      </div>
                      {r.user?.phone && (
                        <div className="flex items-center gap-1.5">
                          <span className="font-medium text-secondary">{t('auth.phone')}: </span>
                          <a href={`tel:${r.user.phone}`} className="inline-flex items-center gap-1 text-primary hover:underline">
                            <Phone className="h-3.5 w-3.5" /> {r.user.phone}
                          </a>
                        </div>
                      )}
                      {r.user?.email && (
                        <div className="flex items-center gap-1.5 min-w-0">
                          <span className="font-medium text-secondary shrink-0">{t('auth.email')}: </span>
                          <a href={`mailto:${r.user.email}`} className="inline-flex items-center gap-1 text-primary hover:underline truncate">
                            <Mail className="h-3.5 w-3.5 shrink-0" /> <span className="truncate">{r.user.email}</span>
                          </a>
                        </div>
                      )}
                      {r.booking_with && (
                        <div>
                          <span className="font-medium text-secondary">{t('eventMgmt.bookingWith')}: </span>
                          {r.booking_with}
                        </div>
                      )}
                      {r.amount_paid && parseFloat(r.amount_paid) > 0 && (
                        <div>
                          <span className="font-medium text-secondary">{t('eventMgmt.amountPaid')}: </span>
                          {r.amount_paid} EGP
                        </div>
                      )}
                      {r.medical_notes && (
                        <div className="sm:col-span-2">
                          <span className="font-medium text-secondary">{t('eventMgmt.medicalNotes')}: </span>
                          {r.medical_notes}
                        </div>
                      )}
                      {r.medication_name && (
                        <div>
                          <span className="font-medium text-secondary">{t('eventMgmt.medicationName')}: </span>
                          {r.medication_name}
                        </div>
                      )}
                      {r.medication_time && (
                        <div>
                          <span className="font-medium text-secondary">{t('eventMgmt.medicationTime')}: </span>
                          {new Date(r.medication_time).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
                        </div>
                      )}
                      {r.rejection_reason && (
                        <div className="sm:col-span-2">
                          <span className="font-medium text-secondary">{t('eventMgmt.rejectionReason')}: </span>
                          {r.rejection_reason}
                        </div>
                      )}
                      {(r.approved_by_name || r.approved_at) && (
                        <div className="sm:col-span-2 text-xs text-secondary">
                          {t('eventMgmt.approved')} {r.approved_by_name ? `· ${r.approved_by_name}` : ''} {r.approved_at ? `· ${new Date(r.approved_at).toLocaleString()}` : ''}
                        </div>
                      )}
                      {(r.rejected_by_name || r.rejected_at) && (
                        <div className="sm:col-span-2 text-xs text-secondary">
                          {t('eventMgmt.rejected')} {r.rejected_by_name ? `· ${r.rejected_by_name}` : ''} {r.rejected_at ? `· ${new Date(r.rejected_at).toLocaleString()}` : ''}
                        </div>
                      )}
                    </div>

                    {/* Review actions — visible only while still reviewable */}
                    {canReview && (
                      <div className="flex gap-2 pt-1">
                        <button onClick={() => setApproveTarget(r)} className="btn-primary btn-sm flex-1 sm:flex-none">
                          {t('eventMgmt.approve')}
                        </button>
                        <button onClick={() => { setRejectReason(''); setRejectTarget(r) }} className="btn-danger btn-sm flex-1 sm:flex-none">
                          {t('eventMgmt.reject')}
                        </button>
                      </div>
                    )}

                    {r.registered_at && (
                      <p className="text-xs text-secondary">
                        {t('eventMgmt.registeredAt')}: {new Date(r.registered_at).toLocaleString()}
                      </p>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Approve confirmation */}
      <Modal
        isOpen={approveTarget !== null}
        onClose={() => setApproveTarget(null)}
        title={t('eventMgmt.approve')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setApproveTarget(null)} disabled={busy} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button onClick={handleApprove} disabled={busy} className="flex-1 btn-primary btn-md">
              {busy ? t('common.saving') : t('common.confirm')}
            </button>
          </div>
        }
      >
        <p className="text-sm">
          {t('eventMgmt.approveConfirm')} <strong>{approveTarget?.user?.name}</strong>?
        </p>
      </Modal>

      {/* Reject confirmation with optional reason */}
      <Modal
        isOpen={rejectTarget !== null}
        onClose={() => { setRejectTarget(null); setRejectReason('') }}
        title={t('eventMgmt.reject')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => { setRejectTarget(null); setRejectReason('') }} disabled={busy} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button onClick={handleReject} disabled={busy} className="flex-1 btn-danger btn-md">
              {busy ? t('common.saving') : t('common.confirm')}
            </button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-sm">
            {t('eventMgmt.rejectConfirm')} <strong>{rejectTarget?.user?.name}</strong>?
          </p>
          <textarea
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            rows={3}
            maxLength={1000}
            className="input-field w-full"
            placeholder={t('eventMgmt.optionalReason')}
          />
        </div>
      </Modal>
    </div>
  )
}
