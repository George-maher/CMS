import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ChevronDown, ChevronUp } from 'lucide-react'
import Badge from '@/components/common/Badge'
import type { EventRegistration } from '@/types'
import { listEventReservationRequests } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'
import {
  registrationStatusLabelKey,
  registrationStatusVariant,
} from './eventStatus'

interface Props {
  eventId: number
}

export default function EventReservationRequestsTab({ eventId }: Props) {
  const { t } = useTranslation()
  const [requests, setRequests] = useState<EventRegistration[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [expandedId, setExpandedId] = useState<number | null>(null)

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
          {filtered.map((r) => (
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
                    <p className="truncate text-xs text-secondary">{r.user?.class_name ?? ''}</p>
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
                <div className="border-t border-border px-4 py-3 space-y-2 bg-background text-sm">
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div>
                      <span className="font-medium text-secondary">{t('eventMgmt.participant')}: </span>
                      {r.user?.name}
                    </div>
                    <div>
                      <span className="font-medium text-secondary">{t('eventMgmt.registration')}: </span>
                      <Badge variant={registrationStatusVariant[r.status]}>
                        {t(registrationStatusLabelKey(r.status))}
                      </Badge>
                    </div>
                    {r.status === 'booked' && (
                      <>
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
                      </>
                    )}
                    {r.status === 'not_reserved' && (
                      <div className="sm:col-span-2 text-secondary">
                        {t('eventMgmt.notReservedMessage')}
                      </div>
                    )}
                    {r.status === 'thinking' && (
                      <div className="sm:col-span-2 text-secondary">
                        {t('eventMgmt.thinkingMessage')}
                      </div>
                    )}
                  </div>
                  {r.registered_at && (
                    <p className="text-xs text-secondary">
                      {t('eventMgmt.registeredAt')}: {new Date(r.registered_at).toLocaleString()}
                    </p>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
