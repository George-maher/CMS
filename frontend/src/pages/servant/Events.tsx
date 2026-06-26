import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Plus } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import EventDetailModal from '@/components/common/EventDetailModal'
import ImageUpload from '@/components/common/ImageUpload'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { Event } from '@/types'
import { listEvents, getEvent, createEvent, updateEvent, deleteEvent } from '@/api/events'
import { getMyClasses } from '@/api/structure'

interface EventForm { name: string; type: string; image: string | File; description: string; event_date: string; location: string; class_id: string; is_all_classes: boolean; target_class_ids: number[] }
const emptyForm: EventForm = { name: '', type: 'service', image: '', description: '', event_date: '', location: '', class_id: '', is_all_classes: false, target_class_ids: [] }

export default function ServantEvents() {
  const { t } = useTranslation()

  const columns: Column<Event>[] = [
    { key: 'image', header: '', render: (e) => e.image ? (
      <ImageWithFallback src={e.image} alt={e.name} className="h-10 w-16 rounded object-cover" />
    ) : <div className="h-10 w-16 rounded bg-surface-tertiary" /> },
    { key: 'name', header: t('events.eventName'), render: (e) => <span className="font-medium">{e.name}</span> },
    { key: 'type', header: t('events.eventType'), render: (e) => <Badge variant="info">{e.type_label}</Badge> },
    { key: 'event_date', header: t('events.eventDate'), render: (e) => e.event_date ? new Date(e.event_date).toLocaleDateString() : '-' },
    { key: 'location', header: t('events.location') },
    { key: 'classe', header: t('events.target'), render: (e) => e.classe?.name ?? t('events.allClasses') },
  ]
  const [events, setEvents] = useState<Event[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Event | null>(null)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState<EventForm>(emptyForm)
  const [viewing, setViewing] = useState<Event | null>(null)
  const [viewLoading, setViewLoading] = useState(false)

  const handleView = async (id: number) => {
    setViewLoading(true)
    try { setViewing(await getEvent(id)) }
    catch { toast.error(t('common.loading')) }
    finally { setViewLoading(false) }
  }

  const fetch = async (page = 1) => {
    setLoading(true)
    try { const res = await listEvents({ page, per_page: 15 }); setEvents(res.data); setMeta(res.meta) }
    finally { setLoading(false) }
  }

  useEffect(() => { fetch(); getMyClasses().then(setClasses).catch(() => {}) }, [])

  const openCreate = () => {
    setEditing(null)
    const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset())
    setForm({ ...emptyForm, event_date: now.toISOString().slice(0, 16) }); setError(''); setShowModal(true)
  }

  const openEdit = (event: Event) => {
    setEditing(event)
    setForm({ name: event.name, type: event.type, image: event.image ?? '', description: event.description ?? '', event_date: event.event_date ? event.event_date.slice(0, 16) : '', location: event.location ?? '', class_id: event.class_id?.toString() ?? '', is_all_classes: event.is_all_classes ?? false, target_class_ids: event.target_classes?.map(c => c.id) ?? [] })
    setError(''); setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true); setError('')
    try {
      const payload: Record<string, unknown> = { name: form.name, type: form.type, description: form.description, location: form.location || null, class_id: form.class_id ? Number(form.class_id) : null, is_active: true, is_all_classes: form.is_all_classes }
      if (form.is_all_classes) {
        payload.target_class_ids = []
      } else if (form.target_class_ids.length > 0) {
        payload.target_class_ids = form.target_class_ids
      }
      if (form.event_date) payload.event_date = new Date(form.event_date).toISOString()
      if (form.image instanceof File) { payload.image = form.image }
      else if (editing && (form.image === '' || form.image === null)) { payload.remove_image = true }
      if (editing) { await updateEvent(editing.id, payload) } else { await createEvent(payload) }
      setShowModal(false); fetch(); toast.success(editing ? t('common.update') : t('common.create'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data
      setError(msg?.errors ? Object.values(msg.errors).flat().join(', ') : msg?.message || t('common.loading'))
    } finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (window.confirm(t('events.deleteConfirm'))) { await deleteEvent(id); fetch(); toast.success(t('common.delete')) }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-secondary">{meta.total} {t('events.events')}</p>
        <button onClick={openCreate} className="btn-primary btn-md self-start sm:self-auto">
          <Plus className="h-4 w-4" /> {t('events.createEvent')}
        </button>
      </div>

      <DataTable columns={[...columns, { key: 'actions', header: '', render: (e) => (
        <div className="flex gap-2">
          <button onClick={() => handleView(e.id)} disabled={viewLoading} className="btn-ghost btn-sm">{t('common.view')}</button>
          <button onClick={() => openEdit(e)} className="btn-ghost btn-sm text-primary-500">{t('common.edit')}</button>
          <button onClick={() => handleDelete(e.id)} className="btn-ghost btn-sm text-red-500">{t('common.delete')}</button>
        </div>
      )}]} data={events} meta={meta} isLoading={loading} onPageChange={fetch} />

      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title={editing ? t('events.editEvent') : t('events.createEvent')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowModal(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleSave} disabled={saving} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : (editing ? t('common.update') : t('common.create'))}
            </button>
          </div>
        }>
        <div className="space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input placeholder={t('events.eventName')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="input-field" />
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}
              className="input-field">
              <option value="service">{t('events.type_service')}</option>
              <option value="trip">{t('events.type_trip')}</option>
              <option value="meeting">{t('events.type_meeting')}</option>
              <option value="other">{t('events.type_other')}</option>
            </select>
          </div>
          <ImageUpload value={form.image} onChange={(file) => setForm({ ...form, image: file ?? '' })} />
          <textarea placeholder={t('events.description')} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}
            className="input-field" rows={2} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input type="datetime-local" value={form.event_date} onChange={(e) => setForm({ ...form, event_date: e.target.value })}
              className="input-field" />
            <input placeholder={t('events.location')} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })}
              className="input-field" />
          </div>
          <label className="label">{t('events.target')}</label>
          <label className="flex items-center gap-2 text-sm mb-2">
            <input type="checkbox" checked={form.is_all_classes} onChange={(e) => setForm({ ...form, is_all_classes: e.target.checked, target_class_ids: [] })} />
            {t('events.allClasses')}
          </label>
          {!form.is_all_classes && classes.length > 0 ? (
            <div className="space-y-1 max-h-32 overflow-y-auto border border-border rounded-lg p-2">
              {classes.map((c) => (
                <label key={c.id} className="flex items-center gap-2 text-sm py-0.5">
                  <input type="checkbox" checked={form.target_class_ids.includes(c.id)}
                    onChange={(e) => {
                      if (e.target.checked) {
                        setForm({ ...form, target_class_ids: [...form.target_class_ids, c.id] })
                      } else {
                        setForm({ ...form, target_class_ids: form.target_class_ids.filter(id => id !== c.id) })
                      }
                    }} />
                  {c.name}
                </label>
              ))}
            </div>
          ) : !form.is_all_classes ? (
            <p className="text-sm text-secondary">{t('structure.noClasses')}</p>
          ) : null}
          {error && <div className="form-error">{error}</div>}
        </div>
      </Modal>

      <EventDetailModal event={viewing} isOpen={viewing !== null} onClose={() => setViewing(null)} />
    </div>
  )
}
