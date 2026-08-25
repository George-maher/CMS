import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Calendar, Users, Bed, Bus, Clock, CheckCircle, AlertCircle } from 'lucide-react'
import Badge from '@/components/common/Badge'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import { getMyAssignedEvents } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'
import { eventStatusVariant, eventStatusLabelKey } from '@/components/events/eventStatus'
import type { Event, PaginationMeta } from '@/types'

export default function MyAssignedEvents() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [events, setEvents] = useState<Event[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)

  const fetchEvents = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const res = await getMyAssignedEvents({ page, per_page: 15 })
      setEvents(res.data)
      setMeta(res.meta)
    } catch (e) {
      logCatch('MyAssignedEvents.fetch', e)
      toast.error(t('common.loading'))
    } finally {
      setLoading(false)
    }
  }, [t])

  useEffect(() => {
    void Promise.resolve().then(() => fetchEvents())
  }, [fetchEvents])

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-lg font-bold">{t('eventMgmt.myAssignedEvents')}</h1>
        <p className="text-sm text-secondary">{meta.total} {t('events.events')}</p>
      </div>

      {loading ? (
        <div className="flex min-h-[40vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
        </div>
      ) : events.length === 0 ? (
        <div className="rounded-xl border border-border bg-surface p-8 text-center">
          <Calendar className="mx-auto h-12 w-12 text-secondary opacity-40" />
          <p className="mt-3 text-sm text-secondary">{t('eventMgmt.noAssignedEvents')}</p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {events.map((event) => {
            const status = event.status ?? 'draft'
            return (
              <div
                key={event.id}
                className="rounded-xl border border-border bg-surface p-4 space-y-3 cursor-pointer hover:border-primary/30 transition-colors"
                onClick={() => navigate(`/servant/events/${event.id}`)}
              >
                <div className="flex items-start gap-3">
                  {event.image ? (
                    <ImageWithFallback src={event.image} alt={event.name} className="h-12 w-16 rounded-lg object-cover" />
                  ) : (
                    <div className="h-12 w-16 rounded-lg bg-surface-tertiary" />
                  )}
                  <div className="min-w-0 flex-1">
                    <h3 className="font-medium truncate">{event.name}</h3>
                    <p className="text-xs text-secondary">
                      {event.event_date ? new Date(event.event_date).toLocaleDateString() : ''}
                      {event.location ? ` · ${event.location}` : ''}
                    </p>
                  </div>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  <Badge variant={eventStatusVariant[status]}>{t(eventStatusLabelKey(status))}</Badge>
                  <Badge variant="info">{t(`events.type_${event.type}`)}</Badge>
                </div>

                {event.has_accommodation && (
                  <div className="grid grid-cols-2 gap-2 text-xs">
                    <div className="flex items-center gap-1 text-secondary">
                      <Bed className="h-3.5 w-3.5" />
                      <span>{event.rooms_count ?? 0} {t('eventMgmt.totalRooms')}</span>
                    </div>
                    <div className="flex items-center gap-1 text-secondary">
                      <Users className="h-3.5 w-3.5" />
                      <span>{event.total_member_capacity ?? 0} {t('eventMgmt.memberCapacity')}</span>
                    </div>
                  </div>
                )}

                {event.type === 'trip' && (
                  <div className="text-xs text-secondary flex items-center gap-1">
                    <Bus className="h-3.5 w-3.5" />
                    <span>{t('eventMgmt.tabBuses')}</span>
                  </div>
                )}

                <div className="flex items-center justify-between text-xs pt-2 border-t border-border">
                  <div className="flex items-center gap-3">
                    <span className="flex items-center gap-1 text-yellow-600 dark:text-yellow-400">
                      <Clock className="h-3.5 w-3.5" />
                      {event.pending_count ?? 0} {t('eventMgmt.reg_pending')}
                    </span>
                    <span className="flex items-center gap-1 text-green-600 dark:text-green-400">
                      <CheckCircle className="h-3.5 w-3.5" />
                      {event.approved_count ?? 0} {t('eventMgmt.reg_approved')}
                    </span>
                  </div>
                  <span className="flex items-center gap-1 text-secondary">
                    <AlertCircle className="h-3.5 w-3.5" />
                    {event.registered_count ?? 0} {t('eventMgmt.participant')}
                  </span>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {meta.last_page > 1 && (
        <div className="flex justify-center gap-2">
          {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
            <button
              key={page}
              onClick={() => fetchEvents(page)}
              className={`px-3 py-1 text-sm rounded-lg ${page === meta.current_page ? 'bg-primary text-white' : 'bg-surface-secondary text-secondary hover:bg-surface-tertiary'}`}
            >
              {page}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
