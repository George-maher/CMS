import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import Modal from '@/components/common/Modal'
import type { EventSession, EventSpeaker } from '@/types'
import { createSession, createSpeaker, deleteSession, deleteSpeaker, listSessions, listSpeakers } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'

interface Props {
  eventId: number
}

export default function EventScheduleTab({ eventId }: Props) {
  const { t } = useTranslation()

  const [sessions, setSessions] = useState<EventSession[]>([])
  const [speakers, setSpeakers] = useState<EventSpeaker[]>([])
  const [loading, setLoading] = useState(true)
  const [showAddSession, setShowAddSession] = useState(false)
  const [showAddSpeaker, setShowAddSpeaker] = useState(false)
  const [sessionTitle, setSessionTitle] = useState('')
  const [sessionSpeaker, setSessionSpeaker] = useState('')
  const [sessionStartsAt, setSessionStartsAt] = useState('')
  const [speakerName, setSpeakerName] = useState('')
  const [speakerTitle, setSpeakerTitle] = useState('')
  const [saving, setSaving] = useState(false)

  const fetch = useCallback(async () => {
    setLoading(true)
    try {
      const [s, sp] = await Promise.all([listSessions(eventId), listSpeakers(eventId)])
      setSessions(s)
      setSpeakers(sp)
    } catch (e) {
      logCatch('EventScheduleTab.load', e)
    } finally {
      setLoading(false)
    }
  }, [eventId])

  useEffect(() => {
    void Promise.resolve().then(fetch)
  }, [fetch])

  const handleAddSession = async () => {
    if (!sessionTitle.trim()) return
    setSaving(true)
    try {
      await createSession(eventId, {
        title: sessionTitle,
        speaker_name: sessionSpeaker || undefined,
        starts_at: sessionStartsAt ? new Date(sessionStartsAt).toISOString() : undefined,
      })
      setShowAddSession(false)
      setSessionTitle('')
      setSessionSpeaker('')
      setSessionStartsAt('')
      toast.success(t('common.create'))
      fetch()
    } catch (e) {
      logCatch('EventScheduleTab.createSession', e)
      toast.error(t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleAddSpeaker = async () => {
    if (!speakerName.trim()) return
    setSaving(true)
    try {
      await createSpeaker(eventId, { name: speakerName, title: speakerTitle || undefined })
      setShowAddSpeaker(false)
      setSpeakerName('')
      setSpeakerTitle('')
      toast.success(t('common.create'))
      fetch()
    } catch (e) {
      logCatch('EventScheduleTab.createSpeaker', e)
      toast.error(t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm text-secondary">{t('common.loading')}</div>
  }

  return (
    <div className="space-y-6">
      <section>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">{t('eventMgmt.sessions')}</h3>
          <button onClick={() => setShowAddSession(true)} className="btn-secondary btn-sm">+ {t('eventMgmt.addSession')}</button>
        </div>
        {sessions.length === 0 ? (
          <p className="py-4 text-center text-sm text-secondary">{t('eventMgmt.noSessions')}</p>
        ) : (
          <ol className="space-y-2">
            {sessions.map((s, idx) => (
              <li key={s.id} className="flex items-start justify-between gap-2 rounded-xl border border-border bg-surface p-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium">{idx + 1}. {s.title}</p>
                  {s.speaker_name ? <p className="text-xs text-secondary">{t('eventMgmt.speaker')}: {s.speaker_name}</p> : null}
                  {s.starts_at ? <p className="text-xs text-secondary">{new Date(s.starts_at).toLocaleString()}</p> : null}
                </div>
                <button
                  onClick={async () => {
                    try {
                      await deleteSession(eventId, s.id)
                      toast.success(t('common.delete'))
                      fetch()
                    } catch (e) {
                      logCatch('EventScheduleTab.deleteSession', e)
                    }
                  }}
                  className="btn-icon btn-ghost shrink-0 text-red-500"
                >
                  🗑
                </button>
              </li>
            ))}
          </ol>
        )}
      </section>

      <section>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">{t('eventMgmt.speakers')}</h3>
          <button onClick={() => setShowAddSpeaker(true)} className="btn-secondary btn-sm">+ {t('eventMgmt.addSpeaker')}</button>
        </div>
        {speakers.length === 0 ? (
          <p className="py-4 text-center text-sm text-secondary">{t('eventMgmt.noSpeakers')}</p>
        ) : (
          <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {speakers.map((sp) => (
              <li key={sp.id} className="flex items-start justify-between gap-2 rounded-xl border border-border bg-surface p-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{sp.name}</p>
                  {sp.title ? <p className="text-xs text-secondary">{sp.title}</p> : null}
                </div>
                <button
                  onClick={async () => {
                    try {
                      await deleteSpeaker(eventId, sp.id)
                      toast.success(t('common.delete'))
                      fetch()
                    } catch (e) {
                      logCatch('EventScheduleTab.deleteSpeaker', e)
                    }
                  }}
                  className="btn-icon btn-ghost shrink-0 text-red-500"
                >
                  🗑
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <Modal
        isOpen={showAddSession}
        onClose={() => setShowAddSession(false)}
        title={t('eventMgmt.addSession')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setShowAddSession(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleAddSession} disabled={saving} className="flex-1 btn-primary btn-md">{saving ? t('common.saving') : t('common.create')}</button>
          </div>
        }
      >
        <div className="space-y-3">
          <input value={sessionTitle} onChange={(e) => setSessionTitle(e.target.value)} placeholder={t('eventMgmt.sessionTitle')} className="input-field w-full" />
          <input value={sessionSpeaker} onChange={(e) => setSessionSpeaker(e.target.value)} placeholder={t('eventMgmt.speaker')} className="input-field w-full" />
          <input type="datetime-local" value={sessionStartsAt} onChange={(e) => setSessionStartsAt(e.target.value)} className="input-field w-full" />
        </div>
      </Modal>

      <Modal
        isOpen={showAddSpeaker}
        onClose={() => setShowAddSpeaker(false)}
        title={t('eventMgmt.addSpeaker')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setShowAddSpeaker(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleAddSpeaker} disabled={saving} className="flex-1 btn-primary btn-md">{saving ? t('common.saving') : t('common.create')}</button>
          </div>
        }
      >
        <div className="space-y-3">
          <input value={speakerName} onChange={(e) => setSpeakerName(e.target.value)} placeholder={t('eventMgmt.speakerName')} className="input-field w-full" />
          <input value={speakerTitle} onChange={(e) => setSpeakerTitle(e.target.value)} placeholder={t('eventMgmt.speakerRole')} className="input-field w-full" />
        </div>
      </Modal>
    </div>
  )
}
