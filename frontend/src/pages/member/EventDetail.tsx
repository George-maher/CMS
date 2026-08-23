import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Calendar, MapPin, Users, ArrowLeft, QrCode } from 'lucide-react'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import type { Event, EventRegistration } from '@/types'
import { getEvent, trackEventView } from '@/api/events'
import { myRegistrations, registerSelf } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'

export default function MemberEventDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [event, setEvent] = useState<Event | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [registration, setRegistration] = useState<EventRegistration | null>(null)
  const [registering, setRegistering] = useState(false)

  useEffect(() => {
    if (!id) return
    Promise.all([
      getEvent(Number(id)),
      myRegistrations().catch(() => []),
    ])
      .then(([ev, regs]) => {
        setEvent(ev)
        trackEventView(ev.id).catch(() => {})
        setRegistration(regs.find((r) => r.event_id === ev.id) ?? null)
      })
      .catch((e) => {
        logCatch('MemberEventDetail.load', e)
        setError(true)
      })
      .finally(() => setLoading(false))
  }, [id])

  const handleRegister = async () => {
    if (!event) return
    setRegistering(true)
    try {
      const reg = await registerSelf(event.id)
      setRegistration(reg)
      toast.success(t('eventMgmt.participantAdded'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setRegistering(false)
    }
  }

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

  const canSelfRegister = event.status === 'open' && !registration

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
            {event.status ? (
              <Badge variant={event.status === 'open' ? 'success' : event.status === 'cancelled' ? 'danger' : 'warning'}>
                {t(`eventMgmt.status_${event.status}`)}
              </Badge>
            ) : null}
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

          {canSelfRegister ? (
            <button onClick={handleRegister} disabled={registering} className="btn-primary btn-md w-full sm:w-auto">
              {registering ? t('common.saving') : t('eventMgmt.addParticipant')}
            </button>
          ) : null}

          {registration && registration.status !== 'cancelled' ? (
            <div className="rounded-xl border border-border bg-surface p-4">
              <div className="flex items-center gap-2">
                <QrCode className="h-5 w-5 text-primary" />
                <p className="text-sm font-semibold">{t('eventMgmt.registration')}</p>
                <Badge variant={registration.status === 'waitlisted' ? 'info' : registration.status === 'confirmed' ? 'success' : 'warning'}>
                  {t(`eventMgmt.reg_${registration.status}`)}
                </Badge>
              </div>
              {registration.qr_token ? (
                <p className="mt-2 break-all rounded-lg bg-surface-tertiary p-2 font-mono text-xs">
                  {registration.qr_token}
                </p>
              ) : null}
              <p className="mt-1 text-xs text-secondary">{t('eventMgmt.qrTokenPlaceholder')}</p>
            </div>
          ) : null}

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
