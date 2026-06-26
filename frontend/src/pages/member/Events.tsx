import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { CalendarDays, Eye, ImageOff } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import type { Event } from '@/types'
import { listEvents } from '@/api/events'

function EventCard({ event, onSeeMore }: { event: Event; onSeeMore: (e: Event) => void }) {
  const { t } = useTranslation()
  const [imgError, setImgError] = useState(false)

  return (
    <div className="card overflow-hidden flex flex-col">
      {event.image && !imgError ? (
        <img
          src={event.image}
          alt={event.name}
          loading="lazy"
          onError={() => setImgError(true)}
          className="w-full h-40 object-cover"
        />
      ) : event.image ? (
        <div className="w-full h-40 flex items-center justify-center bg-surface-tertiary">
          <ImageOff className="h-6 w-6 text-muted" />
        </div>
      ) : null}
      <div className="p-5 flex flex-col flex-1">
        <h3 className="font-semibold">{event.name}</h3>
        {event.event_date && (
          <p className="mt-2 flex items-center gap-1.5 text-sm text-secondary">
            <CalendarDays className="h-4 w-4 shrink-0" />
            {event.event_date ? new Date(event.event_date).toLocaleDateString(undefined, {
              weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
            }) : t('common.dateTbd')}
          </p>
        )}
        <div className="mt-auto pt-4">
          <button
            onClick={() => onSeeMore(event)}
            className="btn-primary btn-sm flex items-center gap-1.5"
          >
            <Eye className="h-4 w-4" />
            {t('events.seeMore')}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function MemberEvents() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [events, setEvents] = useState<Event[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    listEvents({ per_page: 50 }).then((res) => setEvents(res.data)).catch(() => setEvents([])).finally(() => setLoading(false))
  }, [])

  const handleSeeMore = (event: Event) => {
    navigate(`/member/events/${event.id}`)
  }

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold">{t('events.events')}</h2>

      {events.length === 0 && (
        <div className="card py-12 text-center">
          <p className="text-muted">{t('common.noDataYet')}</p>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {events.map((event) => (
          <EventCard key={event.id} event={event} onSeeMore={handleSeeMore} />
        ))}
      </div>
    </div>
  )
}
