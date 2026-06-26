import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Calendar, MapPin, Users, ArrowLeft } from 'lucide-react'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import type { Event } from '@/types'
import { getEvent, trackEventView } from '@/api/events'

export default function MemberEventDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [event, setEvent] = useState<Event | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    if (!id) return
    setLoading(true)
    setError(false)
    getEvent(Number(id))
      .then((ev) => {
        setEvent(ev)
        trackEventView(ev.id).catch(() => {})
      })
      .catch(() => setError(true))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <LoadingSpinner className="py-20" />
  if (error || !event) {
    return (
      <div className="card py-20 text-center">
        <p className="text-muted">{t('common.noData')}</p>
        <button onClick={() => navigate('/member/events')} className="btn-primary btn-sm mt-4">
          <ArrowLeft className="h-4 w-4" /> {t('common.back')}
        </button>
      </div>
    )
  }

  return (
    <div className="max-w-3xl mx-auto space-y-4">
      <button
        onClick={() => navigate('/member/events')}
        className="btn-ghost btn-sm flex items-center gap-1.5"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common.back')}
      </button>

      <div className="card overflow-hidden">
        {event.image && (
          <ImageWithFallback key={event.image} src={event.image} alt={event.name} className="w-full h-64 object-cover" />
        )}
        <div className="p-6 space-y-4">
          <div className="flex items-center gap-2 flex-wrap">
            <h2 className="text-2xl font-bold">{event.name}</h2>
            <Badge variant="info">{event.type_label}</Badge>
          </div>

          <p className="flex items-center gap-2 text-sm text-secondary">
            <Calendar className="h-4 w-4 shrink-0" />
            {event.event_date ? new Date(event.event_date).toLocaleDateString(undefined, {
              weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
            }) : t('common.dateTbd')}
          </p>

          {event.location && (
            <p className="flex items-center gap-2 text-sm text-secondary">
              <MapPin className="h-4 w-4 shrink-0" /> {event.location}
            </p>
          )}

          <p className="flex items-center gap-2 text-sm text-muted">
            <Users className="h-4 w-4 shrink-0" />
            {event.is_all_classes ? t('events.allClasses') : (event.classe?.name ?? event.target_classes?.map(c => c.name).join(', ') ?? t('events.allClasses'))}
          </p>

          {event.description && (
            <div className="border-t pt-4">
              <p className="text-sm text-secondary whitespace-pre-wrap leading-relaxed">{event.description}</p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
