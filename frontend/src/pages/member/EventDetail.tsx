import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Calendar, MapPin, Users, ArrowLeft, QrCode, CheckCircle, Clock, AlertTriangle } from 'lucide-react'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import EventActiveStatus from '@/components/events/EventActiveStatus'
import type { Event, EventRegistration } from '@/types'
import { getEvent, trackEventView } from '@/api/events'
import { myRegistrations, registerSelf, submitMemberReservationRequest } from '@/api/eventRegistrations'
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
  const [selectedStatus, setSelectedStatus] = useState<'booked' | 'not_reserved' | 'thinking'>('booked')
  const [saveError, setSaveError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [showDetails, setShowDetails] = useState(false)
  const [bookedWith, setBookedWith] = useState('')
  const [amountPaid, setAmountPaid] = useState('')
  const [medicalNotes, setMedicalNotes] = useState('')
  const [medicationName, setMedicationName] = useState('')
  const [medicationTime, setMedicationTime] = useState('')

  useEffect(() => {
    if (!id) return
    Promise.all([
      getEvent(Number(id)),
      myRegistrations().catch(() => []),
    ])
      .then(([ev, regs]) => {
        setEvent(ev)
        trackEventView(ev.id).catch(() => {})
        const existingReg = regs.find((r) => r.event_id === ev.id) ?? null
        setRegistration(existingReg)
        if (existingReg) {
          setSelectedStatus(existingReg.status as 'booked' | 'not_reserved' | 'thinking')
          setBookedWith(existingReg.booking_with || '')
          setAmountPaid(existingReg.amount_paid || '')
          setMedicalNotes(existingReg.medical_notes || '')
          setMedicationTime(existingReg.medication_time ? new Date(existingReg.medication_time).toISOString().slice(11, 16) : '')
        }
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

  const handleSubmitReservation = async () => {
    if (!event) return
    setSubmitting(true)
    setSaveError(null)
    try {
      const reg = await submitMemberReservationRequest(event.id, selectedStatus, {
        booked_with: selectedStatus === 'booked' ? bookedWith : undefined,
        amount_paid: selectedStatus === 'booked' ? amountPaid : undefined,
        medical_notes: selectedStatus === 'booked' ? medicalNotes : undefined,
        medication_name: selectedStatus === 'booked' ? medicationName : undefined,
        medication_time: selectedStatus === 'booked' ? medicationTime : undefined,
      })
      setRegistration(reg)
      setShowDetails(false)
      toast.success(t('eventMgmt.requestSubmitted'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setSaveError(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSubmitting(false)
    }
  }

  const handleStatusChange = (status: 'booked' | 'not_reserved' | 'thinking') => {
    setSelectedStatus(status)
    setSaveError(null)
    if (status === 'booked') {
      setShowDetails(true)
    } else {
      setShowDetails(false)
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

  // Determine status display
  const statusLabels: Record<string, string> = {
    booked: t('eventMgmt.booked'),
    not_reserved: t('eventMgmt.notReserved'),
    thinking: t('eventMgmt.thinking'),
    pending: t('eventMgmt.reg_pending'),
    confirmed: t('eventMgmt.reg_confirmed'),
    waitlisted: t('eventMgmt.reg_waitlisted'),
    cancelled: t('eventMgmt.reg_cancelled'),
    approved: t('eventMgmt.reg_approved'),
    rejected: t('eventMgmt.reg_rejected'),
  }

  const statusIcons: Record<string, React.ReactNode> = {
    booked: <CheckCircle className="h-5 w-5 text-success" />,
    not_reserved: <Clock className="h-5 w-5 text-warning" />,
    thinking: <AlertTriangle className="h-5 w-5 text-info" />,
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

          <div className="flex items-center gap-2 text-sm">
            <span className="font-medium text-secondary">{t('common.status')}:</span>
            <EventActiveStatus event={event} size="md" />
          </div>

          <p className="flex items-center gap-2 text-sm text-muted">
            <Users className="h-4 w-4 shrink-0" />
            {event.is_all_classes ? t('events.allClasses') : (event.classe?.name ?? event.target_classes?.map(c => c.name).join(', ') ?? t('events.allClasses'))}
          </p>

          {canSelfRegister ? (
            <button onClick={handleRegister} disabled={registering} className="btn-primary btn-md w-full sm:w-auto">
              {registering ? t('common.saving') : t('eventMgmt.addParticipant')}
            </button>
          ) : null}

          {/* Member's Current Reservation Status Display */}
          {registration && registration.status !== 'cancelled' && (
            <div className="rounded-xl border border-border bg-surface p-4">
              <div className="flex items-center gap-2">
                {statusIcons[registration.status] || <QrCode className="h-5 w-5 text-primary" />}
                <p className="text-sm font-semibold">{t('eventMgmt.registration')}</p>
                <Badge variant={registration.status === 'waitlisted' ? 'info' : registration.status === 'confirmed' ? 'success' : registration.status === 'booked' ? 'success' : registration.status === 'not_reserved' ? 'warning' : registration.status === 'thinking' ? 'info' : 'warning'}>
                  {statusLabels[registration.status]}
                </Badge>
              </div>
              {registration.qr_token ? (
                <p className="mt-2 break-all rounded-lg bg-surface-tertiary p-2 font-mono text-xs">
                  {registration.qr_token}
                </p>
              ) : null}
              <p className="mt-1 text-xs text-secondary">{t('eventMgmt.qrTokenPlaceholder')}</p>

              {/* Show reservation details for 'booked' status */}
              {registration.status === 'booked' && (
                <div className="mt-4 space-y-2 pt-4 border-t">
                  {registration.booking_with && (
                    <p className="text-sm"><span className="font-medium">{t('eventMgmt.bookingWith')}: </span>{registration.booking_with}</p>
                  )}
                  {registration.amount_paid && parseFloat(registration.amount_paid) > 0 && (
                    <p className="text-sm"><span className="font-medium">{t('eventMgmt.amountPaid')}: </span>{registration.amount_paid} EGP</p>
                  )}
                  {registration.medical_notes && (
                    <p className="text-sm"><span className="font-medium">{t('eventMgmt.medicalNotes')}: </span>{registration.medical_notes}</p>
                  )}
                  {registration.medication_name && (
                    <p className="text-sm"><span className="font-medium">{t('eventMgmt.medicationName')}: </span>{registration.medication_name}</p>
                  )}
                  {registration.medication_time && (
                    <p className="text-sm"><span className="font-medium">{t('eventMgmt.medicationTime')}: </span>{new Date(registration.medication_time).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</p>
                  )}
                </div>
              )}

              {/* Show status message for not_reserved and thinking */}
              {registration.status === 'not_reserved' && (
                <p className="mt-2 text-sm text-secondary">{t('eventMgmt.notReservedMessage')}</p>
              )}
              {registration.status === 'thinking' && (
                <p className="mt-2 text-sm text-secondary">{t('eventMgmt.thinkingMessage')}</p>
              )}
            </div>
          )}

          {/* Success Message After Submission */}
          {registration && !canSelfRegister && (
            <div className="rounded-xl border border-success bg-success/10 p-4">
              <div className="flex items-center gap-2 text-success">
                <CheckCircle className="h-5 w-5" />
                <p className="font-medium">{t('eventMgmt.requestSubmittedSuccess')}</p>
              </div>
              <p className="mt-2 text-sm">
                <span className="font-medium">{t('eventMgmt.yourStatus')}: </span>
                {statusLabels[registration.status]}
              </p>
              {event.responsible_servant && (
                <p className="mt-1 text-sm text-secondary">
                  <span className="font-medium">{t('eventMgmt.responsibleServant')}: </span>
                  {event.responsible_servant.name}
                </p>
              )}
            </div>
          )}

          {/* Reservation Status Options */}
          {event.status === 'open' && !registration ? (
            <div className="mt-4 p-3 border rounded-lg bg-surface/50 text-sm">
              <p className="font-medium text-secondary mb-3">{t('eventMgmt.reservationStatus')}</p>
              <div className="space-y-2">
                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="radio"
                    name="reservationStatus"
                    value="booked"
                    className="w-4 h-4 rounded border-gray-300 bg-primary-600 focus:ring-primary-500"
                    onChange={() => handleStatusChange('booked')}
                    checked={selectedStatus === 'booked'}
                  />
                  <span>{t('eventMgmt.booked')}</span>
                </label>

                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="radio"
                    name="reservationStatus"
                    value="not_reserved"
                    className="w-4 h-4 rounded border-gray-300 bg-primary-600 focus:ring-primary-500"
                    onChange={() => handleStatusChange('not_reserved')}
                    checked={selectedStatus === 'not_reserved'}
                  />
                  <span>{t('eventMgmt.notReserved')}</span>
                </label>

                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="radio"
                    name="reservationStatus"
                    value="thinking"
                    className="w-4 h-4 rounded border-gray-300 bg-primary-600 focus:ring-primary-500"
                    onChange={() => handleStatusChange('thinking')}
                    checked={selectedStatus === 'thinking'}
                  />
                  <span>{t('eventMgmt.thinking')}</span>
                </label>
              </div>

              {/* Detailed Form for 'Booked' Status */}
              {selectedStatus === 'booked' && showDetails && (
                <div className="mt-4 space-y-4 p-4 bg-background rounded-lg border">
                  <h4 className="font-medium">{t('eventMgmt.reservationDetails')}</h4>

                  <div>
                    <label className="block text-sm font-medium mb-1">{t('eventMgmt.bookingWith')}</label>
                    <input
                      type="text"
                      value={bookedWith}
                      onChange={(e) => setBookedWith(e.target.value)}
                      className="input-field w-full"
                      placeholder={t('eventMgmt.bookingWithPlaceholder')}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1">{t('eventMgmt.amountPaid')}</label>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      value={amountPaid}
                      onChange={(e) => setAmountPaid(e.target.value)}
                      className="input-field w-full"
                      placeholder={t('eventMgmt.amountPaidPlaceholder')}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1">{t('eventMgmt.medicalNotes')}</label>
                    <textarea
                      value={medicalNotes}
                      onChange={(e) => setMedicalNotes(e.target.value)}
                      rows={3}
                      className="input-field w-full"
                      placeholder={t('eventMgmt.medicalNotesPlaceholder')}
                    />
                    <p className="mt-1 text-xs text-muted">{t('eventMgmt.medicalNotesHelp')}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1">{t('eventMgmt.medicationName')}</label>
                    <input
                      type="text"
                      value={medicationName}
                      onChange={(e) => setMedicationName(e.target.value)}
                      className="input-field w-full"
                      placeholder={t('eventMgmt.medicationNamePlaceholder')}
                    />
                    <p className="mt-1 text-xs text-muted">{t('eventMgmt.medicationNameHelp')}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium mb-1">{t('eventMgmt.medicationTime')}</label>
                    <input
                      type="time"
                      value={medicationTime}
                      onChange={(e) => setMedicationTime(e.target.value)}
                      className="input-field w-full"
                    />
                    <p className="mt-1 text-xs text-muted">{t('eventMgmt.medicationTimeHelp')}</p>
                  </div>
                </div>
              )}

              {selectedStatus && saveError && (
                <p className="mt-2 text-sm text-red-600">{saveError}</p>
              )}

              {selectedStatus && (
                <button
                  onClick={handleSubmitReservation}
                  disabled={submitting}
                  className="mt-2 w-full btn-primary">
                  {submitting ? t('common.saving') : t('eventMgmt.submitReservation')}
                </button>
              )}
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
