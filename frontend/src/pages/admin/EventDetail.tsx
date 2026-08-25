import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import Badge from '@/components/common/Badge'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import Modal from '@/components/common/Modal'
import EventOverviewTab from '@/components/events/EventOverviewTab'
import EventParticipantsTab from '@/components/events/EventParticipantsTab'
import EventPaymentsTab from '@/components/events/EventPaymentsTab'
import EventBusesTab from '@/components/events/EventBusesTab'
import EventScheduleTab from '@/components/events/EventScheduleTab'
import EventCheckInTab from '@/components/events/EventCheckInTab'
import EventAccommodationTab from '@/components/events/EventAccommodationTab'
import EventReservationRequestsTab from '@/components/events/EventReservationRequestsTab'
import { eventStatusVariant, eventStatusLabelKey } from '@/components/events/eventStatus'
import {
  cancelEvent,
  closeRegistration,
  completeEvent,
  duplicateEvent,
  getEventDashboard,
  publishEvent,
  reopenRegistration,
  reportUrl,
} from '@/api/eventRegistrations'
import { getEvent } from '@/api/events'
import type { Event, EventDashboardStats } from '@/types'
import { useAuth } from '@/hooks/useAuth'
import { logCatch } from '@/lib/debug'

type Tab = 'overview' | 'participants' | 'payments' | 'buses' | 'schedule' | 'checkin' | 'accommodation' | 'requests'

const tabs: { key: Tab; labelKey: string }[] = [
  { key: 'overview', labelKey: 'eventMgmt.tabOverview' },
  { key: 'participants', labelKey: 'eventMgmt.tabParticipants' },
  { key: 'payments', labelKey: 'eventMgmt.tabPayments' },
  { key: 'buses', labelKey: 'eventMgmt.tabBuses' },
  { key: 'schedule', labelKey: 'eventMgmt.tabSchedule' },
  { key: 'checkin', labelKey: 'eventMgmt.tabCheckIn' },
  { key: 'accommodation', labelKey: 'eventMgmt.tabAccommodation' },
  { key: 'requests', labelKey: 'eventMgmt.tabRequests' },
]

export default function EventDetail() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user } = useAuth()
  const params = useParams()
  const eventId = Number(params.id)

  const [event, setEvent] = useState<Event | null>(null)
  const [stats, setStats] = useState<EventDashboardStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<Tab>('overview')
  const [confirmAction, setConfirmAction] = useState<'cancel' | 'complete' | null>(null)
  const [busy, setBusy] = useState(false)

  const isTrip = event?.type === 'trip'
  const isConference = event?.type === 'conference'
  const isAdmin = user?.role === 'admin' || user?.role === 'assistant_admin'
  const canManage = user?.role === 'admin' || user?.role === 'assistant_admin' || user?.role === 'servant'

  const fetchAll = useCallback(async () => {
    if (!Number.isFinite(eventId)) return
    setLoading(true)
    try {
      const [e, s] = await Promise.all([getEvent(eventId), getEventDashboard(eventId)])
      setEvent(e)
      setStats(s)
    } catch (err) {
      logCatch('EventDetail.load', err)
      toast.error(t('common.loading'))
    } finally {
      setLoading(false)
    }
  }, [eventId, t])

  useEffect(() => {
    void Promise.resolve().then(fetchAll)
  }, [fetchAll])

  // Derive the effective tab: schedule only exists for conferences, accommodation only for conference/trip.
  const activeTab: Tab = tab === 'schedule' && !isConference ? 'overview' : tab === 'accommodation' && !event?.has_accommodation ? 'overview' : tab

  const runLifecycle = async (action: () => Promise<Event>, successMsg: string) => {
    setBusy(true)
    try {
      await action()
      toast.success(successMsg)
      fetchAll()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setBusy(false)
      setConfirmAction(null)
    }
  }

  if (loading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
      </div>
    )
  }

  if (!event) {
    return <p className="py-10 text-center text-sm text-secondary">{t('events.noEvents')}</p>
  }

  const status = event.status ?? 'draft'

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          {event.image ? (
            <ImageWithFallback src={event.image} alt={event.name} className="h-16 w-24 rounded-lg object-cover sm:h-20 sm:w-32" />
          ) : null}
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-lg font-bold break-all sm:text-xl">{event.name}</h1>
              <Badge variant={eventStatusVariant[status]}>{t(eventStatusLabelKey(status))}</Badge>
              <Badge variant="info">{t(`events.type_${event.type}`)}</Badge>
            </div>
            <p className="mt-1 text-sm text-secondary">
              {event.event_date ? new Date(event.event_date).toLocaleString() : ''}
              {event.location ? ` · ${event.location}` : ''}
            </p>
          </div>
        </div>
        <button onClick={() => navigate(-1)} className="btn-secondary btn-md shrink-0 self-start">{t('common.back')}</button>
      </div>

      {/* Quick actions */}
      {canManage ? (
        <div className="flex flex-wrap gap-2">
          {status === 'draft' ? (
            <button disabled={busy} onClick={() => runLifecycle(() => publishEvent(event.id), t('eventMgmt.publishedToast'))} className="btn-primary btn-sm">
              {t('eventMgmt.publish')}
            </button>
          ) : null}
          {status === 'open' ? (
            <button disabled={busy} onClick={() => runLifecycle(() => closeRegistration(event.id), t('eventMgmt.closedToast'))} className="btn-secondary btn-sm">
              {t('eventMgmt.closeRegistration')}
            </button>
          ) : null}
          {status === 'closed' ? (
            <button disabled={busy} onClick={() => runLifecycle(() => reopenRegistration(event.id), t('eventMgmt.reopenedToast'))} className="btn-secondary btn-sm">
              {t('eventMgmt.reopenRegistration')}
            </button>
          ) : null}
          {status !== 'completed' && status !== 'cancelled' ? (
            <button disabled={busy} onClick={() => setConfirmAction('cancel')} className="btn-danger btn-sm">
              {t('eventMgmt.cancelEvent')}
            </button>
          ) : null}
          {status === 'open' || status === 'closed' ? (
            <button disabled={busy} onClick={() => setConfirmAction('complete')} className="btn-secondary btn-sm">
              {t('eventMgmt.completeEvent')}
            </button>
          ) : null}
          {isAdmin ? (
            <button
              disabled={busy}
              onClick={() =>
                runLifecycle(async () => {
                  await duplicateEvent(event.id)
                  return event
                }, t('eventMgmt.duplicatedToast'))
              }
              className="btn-secondary btn-sm"
            >
              {t('eventMgmt.duplicate')}
            </button>
          ) : null}
        </div>
      ) : null}

      {/* Tabs */}
      <div className="overflow-x-auto -mx-1 px-1">
        <nav className="flex gap-1 border-b border-border pb-px">
          {tabs
            .filter((tb) => (tb.key === 'buses' ? isTrip : true))
            .filter((tb) => (tb.key === 'schedule' ? isConference : true))
            .filter((tb) => (tb.key === 'accommodation' ? event?.has_accommodation : true))
            .filter((tb) => (tb.key === 'requests' ? event?.has_accommodation : true))
            .map((tb) => (
              <button
                key={tb.key}
                onClick={() => setTab(tb.key)}
                className={`whitespace-nowrap px-3 py-2 text-sm font-medium transition-colors ${
                  activeTab === tb.key ? 'border-b-2 border-primary text-primary' : 'text-secondary hover:text-primary'
                }`}
              >
                {t(tb.labelKey)}
              </button>
            ))}
        </nav>
      </div>

      {/* Tab content */}
      {activeTab === 'overview' ? <EventOverviewTab stats={stats} loading={loading} /> : null}
      {activeTab === 'participants' ? <EventParticipantsTab eventId={eventId} isTrip={isTrip} /> : null}
      {activeTab === 'payments' ? <EventPaymentsTab eventId={eventId} currentUser={user} /> : null}
      {activeTab === 'buses' && isTrip ? <EventBusesTab eventId={eventId} /> : null}
      {activeTab === 'schedule' && isConference ? <EventScheduleTab eventId={eventId} /> : null}
      {activeTab === 'checkin' ? <EventCheckInTab eventId={eventId} /> : null}
      {activeTab === 'accommodation' && event?.has_accommodation ? <EventAccommodationTab eventId={eventId} /> : null}
      {activeTab === 'requests' && event?.has_accommodation ? <EventReservationRequestsTab eventId={eventId} /> : null}

      {/* Reports */}
      {canManage ? (
        <div className="rounded-xl border border-border bg-surface p-4">
          <p className="mb-2 text-sm font-medium">{t('eventMgmt.reports')}</p>
          <div className="flex flex-wrap gap-2">
            <a href={reportUrl(eventId, 'participants')} className="btn-secondary btn-sm" target="_blank" rel="noreferrer">
              {t('eventMgmt.participantsReport')}
            </a>
            <a href={reportUrl(eventId, 'attendance')} className="btn-secondary btn-sm" target="_blank" rel="noreferrer">
              {t('eventMgmt.attendanceReport')}
            </a>
            {isAdmin ? (
              <a href={reportUrl(eventId, 'financial')} className="btn-secondary btn-sm" target="_blank" rel="noreferrer">
                {t('eventMgmt.financialReport')}
              </a>
            ) : null}
          </div>
        </div>
      ) : null}

      {/* Confirmation modal */}
      <Modal
        isOpen={confirmAction !== null}
        onClose={() => setConfirmAction(null)}
        title={confirmAction === 'cancel' ? t('eventMgmt.cancelEvent') : t('eventMgmt.completeEvent')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setConfirmAction(null)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button
              onClick={() =>
                runLifecycle(
                  () => (confirmAction === 'cancel' ? cancelEvent(event.id) : completeEvent(event.id)),
                  confirmAction === 'cancel' ? t('eventMgmt.cancelledToast') : t('eventMgmt.completedToast'),
                )
              }
              disabled={busy}
              className={`flex-1 btn-md ${confirmAction === 'cancel' ? 'btn-danger' : 'btn-primary'}`}
            >
              {t('common.confirm')}
            </button>
          </div>
        }
      >
        <p className="text-sm">
          {confirmAction === 'cancel' ? t('eventMgmt.cancelEventConfirm') : t('eventMgmt.completeEventConfirm')}
        </p>
      </Modal>
    </div>
  )
}
