import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Plus, List, CalendarDays } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import EventDetailModal from '@/components/common/EventDetailModal'
import EventsCalendar from '@/components/common/EventsCalendar'
import ImageUpload from '@/components/common/ImageUpload'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { Event } from '@/types'
import { listEvents, getEvent, createEvent, updateEvent, deleteEvent } from '@/api/events'
import { listAllClasses } from '@/api/structure'
import { getServants } from '@/api/users'
import { logCatch } from '@/lib/debug'
import { eventStatusVariant, eventStatusLabelKey } from '@/components/events/eventStatus'

interface RoomGroup { count: number; capacity: number }
interface BusConfig { count: number; capacity: number }

interface EventForm { name: string; type: string; image: string | File; description: string; event_date: string; location: string; class_id: string; is_active: boolean; is_all_classes: boolean; target_class_ids: number[]; responsible_servant_id: string; room_groups: RoomGroup[]; bus_config: BusConfig[] }

const emptyForm: EventForm = { name: '', type: 'service', image: '', description: '', event_date: '', location: '', class_id: '', is_active: true, is_all_classes: false, target_class_ids: [], responsible_servant_id: '', room_groups: [], bus_config: [] }

export default function AdminEvents() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [viewMode, setViewMode] = useState<'list' | 'calendar'>('list')

  const columns: Column<Event>[] = [
    { key: 'image', header: '', render: (e) => e.image ? (
      <ImageWithFallback src={e.image} alt={e.name} className="h-10 w-16 rounded object-cover" />
    ) : <div className="h-10 w-16 rounded bg-surface-tertiary" /> },
    { key: 'name', header: t('events.eventName'), render: (e) => <span className="font-medium">{e.name}</span> },
    { key: 'type', header: t('events.eventType'), render: (e) => <Badge variant="info">{t(`events.type_${e.type}`)}</Badge> },
    { key: 'status', header: t('common.status'), render: (e) => (
      <Badge variant={eventStatusVariant[(e.status ?? 'draft') as keyof typeof eventStatusVariant]}>
        {t(eventStatusLabelKey(e.status ?? 'draft'))}
      </Badge>
    ) },
    { key: 'capacity', header: t('eventMgmt.capacity'), render: (e) => e.max_capacity ? `${e.registered_count ?? 0} / ${e.max_capacity}` : '-' },
    { key: 'event_date', header: t('events.eventDate'), render: (e) => e.event_date ? new Date(e.event_date).toLocaleDateString() : '-' },
    { key: 'location', header: t('events.location') },
    { key: 'class_id', header: t('events.target'), render: (e) => e.classe?.name ?? t('events.allClasses') },
    { key: 'is_active', header: t('common.status'), render: (e) => <Badge variant={e.is_active ? 'success' : 'danger'}>{e.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    { key: 'creator', header: t('events.createdBy'), render: (e) => e.creator?.name ?? '-' },
  ]
  const [events, setEvents] = useState<Event[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Event | null>(null)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [servants, setServants] = useState<{ id: number; name: string }[]>([])
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [form, setForm] = useState<EventForm>(emptyForm)
  const [viewing, setViewing] = useState<Event | null>(null)
  const [viewLoading, setViewLoading] = useState(false)

  const handleView = async (id: number) => {
    setViewLoading(true)
    try { setViewing(await getEvent(id)) }
    catch (e) { logCatch('AdminEvents.getEvent', e); toast.error(t('common.loading')) }
    finally { setViewLoading(false) }
  }

  useEffect(() => {
    listEvents({ page: 1, per_page: 15, upcoming: false })
      .then((res) => { setEvents(res.data); setMeta(res.meta) })
      .finally(() => setLoading(false))
    listAllClasses().then(setClasses).catch((e) => logCatch('AdminEvents.listAllClasses', e))
    getServants().then(setServants).catch((e) => logCatch('AdminEvents.getServants', e))
  }, [])

  const fetch = async (page = 1) => {
    setLoading(true)
    try { const res = await listEvents({ page, per_page: 15, upcoming: false }); setEvents(res.data); setMeta(res.meta) }
    finally { setLoading(false) }
  }

  const openCreate = () => {
    setEditing(null)
    const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset())
    setForm({ ...emptyForm, event_date: now.toISOString().slice(0, 16) }); setSaveError(''); setShowModal(true)
  }

  const openEdit = (event: Event) => {
    setEditing(event)
    setForm({
      name: event.name,
      type: event.type,
      image: event.image ?? '',
      description: event.description ?? '',
      event_date: event.event_date ? event.event_date.slice(0, 16) : '',
      location: event.location ?? '',
      class_id: event.class_id?.toString() ?? '',
      is_active: event.is_active,
      is_all_classes: event.is_all_classes ?? false,
      target_class_ids: event.target_classes?.map(c => c.id) ?? [],
      responsible_servant_id: event.responsible_servant_id?.toString() ?? '',
      room_groups: [],
      bus_config: [],
    })
    setSaveError(''); setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true); setSaveError('')
    try {
      const payload: Record<string, unknown> = { name: form.name, type: form.type, description: form.description, location: form.location || null, class_id: form.class_id ? Number(form.class_id) : null, is_active: form.is_active, is_all_classes: form.is_all_classes }
      if (form.is_all_classes) {
        payload.target_class_ids = []
      } else if (form.target_class_ids.length > 0) {
        payload.target_class_ids = form.target_class_ids
      }
      if (form.event_date) payload.event_date = new Date(form.event_date).toISOString()
      if (form.image instanceof File) { payload.image = form.image }
      else if (editing && (form.image === '' || form.image === null)) { payload.remove_image = true }
      if (['conference', 'trip'].includes(form.type) && form.responsible_servant_id) {
        payload.responsible_servant_id = Number(form.responsible_servant_id)
      }
      if (form.type === 'conference' && !editing && form.room_groups.length > 0) {
        payload.room_groups = form.room_groups
      }
      if (form.type === 'trip' && !editing && form.bus_config.length > 0) {
        payload.bus_config = form.bus_config
      }
      if (editing) { await updateEvent(editing.id, payload) } else { await createEvent(payload) }
      setShowModal(false); fetch(); toast.success(editing ? t('common.update') : t('common.create'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data
      setSaveError(msg?.errors ? Object.values(msg.errors).flat().join(', ') : msg?.message || t('common.save'))
    } finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (window.confirm(t('events.deleteConfirm'))) {
      try { await deleteEvent(id); fetch(); toast.success(t('common.delete')) }
      catch (e) { logCatch('AdminEvents.deleteEvent', e); toast.error(t('common.saving')) }
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-secondary">{meta.total} {t('events.events')}</p>
        <div className="flex flex-wrap gap-2">
          <div className="flex overflow-hidden rounded-lg border border-border">
            <button
              onClick={() => setViewMode('list')}
              className={`flex items-center gap-1 px-3 py-2 text-sm ${viewMode === 'list' ? 'bg-primary text-white' : 'text-secondary'}`}
            >
              <List className="h-4 w-4" /> {t('eventMgmt.listView')}
            </button>
            <button
              onClick={() => setViewMode('calendar')}
              className={`flex items-center gap-1 px-3 py-2 text-sm ${viewMode === 'calendar' ? 'bg-primary text-white' : 'text-secondary'}`}
            >
              <CalendarDays className="h-4 w-4" /> {t('eventMgmt.calendarView')}
            </button>
          </div>
          <button onClick={openCreate} className="btn-primary btn-md self-start sm:self-auto">
            <Plus className="h-4 w-4" /> {t('events.createEvent')}
          </button>
        </div>
      </div>

      {viewMode === 'calendar' ? (
        <EventsCalendar events={events} onOpenEvent={(id) => navigate(`/admin/events/${id}`)} />
      ) : (
        <>
          <DataTable columns={[...columns, { key: 'actions', header: '', render: (e) => (
            <div className="flex gap-2">
              <button onClick={() => navigate(`/admin/events/${e.id}`)} className="btn-icon btn-ghost">{t('eventMgmt.tabOverview')}</button>
              <button onClick={() => handleView(e.id)} disabled={viewLoading} className="btn-icon btn-ghost">{t('common.view')}</button>
              <button onClick={() => openEdit(e)} className="btn-icon btn-ghost">{t('common.edit')}</button>
              <button onClick={() => handleDelete(e.id)} className="btn-icon btn-ghost">{t('common.delete')}</button>
            </div>
          )}]} data={events} meta={meta} isLoading={loading} onPageChange={fetch} />
        </>
      )}

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
            <input placeholder={t('events.eventName')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input-field" />
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className="input-field">
              <option value="service">{t('events.type_service')}</option>
              <option value="conference">{t('events.type_conference')}</option>
              <option value="trip">{t('events.type_trip')}</option>
              <option value="meeting">{t('events.type_meeting')}</option>
              <option value="other">{t('events.type_other')}</option>
            </select>
          </div>
          <ImageUpload value={form.image} onChange={(file) => setForm({ ...form, image: file ?? '' })} />
          <textarea placeholder={t('events.description')} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="input-field" rows={2} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input type="datetime-local" value={form.event_date} onChange={(e) => setForm({ ...form, event_date: e.target.value })} className="input-field" />
            <input placeholder={t('events.location')} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} className="input-field" />
          </div>
          <label className="block text-sm font-medium">{t('events.target')}</label>
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
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            {t('common.active')}
          </label>
          {['conference', 'trip'].includes(form.type) && (
            <div>
              <label className="block text-sm font-medium mb-1">{t('eventMgmt.tabOverview')} - {t('events.createdBy')}</label>
              <select value={form.responsible_servant_id} onChange={(e) => setForm({ ...form, responsible_servant_id: e.target.value })} className="input-field">
                <option value="">--</option>
                {servants.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
          )}
          {form.type === 'conference' && !editing && (
            <div className="space-y-2">
              <label className="block text-sm font-medium">{t('eventMgmt.tabAccommodation')} - {t('eventMgmt.addRooms')}</label>
              {form.room_groups.map((group, idx) => (
                <div key={idx} className="flex gap-2 items-center">
                  <input type="number" min={1} placeholder={t('eventMgmt.numberOfRooms')} value={group.count || ''}
                    onChange={(e) => { const g = [...form.room_groups]; g[idx] = { ...g[idx], count: Number(e.target.value) }; setForm({ ...form, room_groups: g }) }}
                    className="input-field flex-1" />
                  <input type="number" min={1} placeholder={t('eventMgmt.capacityPerRoom')} value={group.capacity || ''}
                    onChange={(e) => { const g = [...form.room_groups]; g[idx] = { ...g[idx], capacity: Number(e.target.value) }; setForm({ ...form, room_groups: g }) }}
                    className="input-field flex-1" />
                  <button type="button" onClick={() => setForm({ ...form, room_groups: form.room_groups.filter((_, i) => i !== idx) })}
                    className="btn-icon btn-ghost text-red-500">✕</button>
                </div>
              ))}
              <button type="button" onClick={() => setForm({ ...form, room_groups: [...form.room_groups, { count: 1, capacity: 6 }] })}
                className="text-sm text-primary hover:underline">+ {t('eventMgmt.addRoomGroup')}</button>
            </div>
          )}
          {form.type === 'trip' && !editing && (
            <div className="space-y-2">
              <label className="block text-sm font-medium">{t('eventMgmt.tabBuses')} - {t('eventMgmt.addBus')}</label>
              {form.bus_config.map((group, idx) => (
                <div key={idx} className="flex gap-2 items-center">
                  <input type="number" min={1} placeholder={t('eventMgmt.numberOfRooms')} value={group.count || ''}
                    onChange={(e) => { const g = [...form.bus_config]; g[idx] = { ...g[idx], count: Number(e.target.value) }; setForm({ ...form, bus_config: g }) }}
                    className="input-field flex-1" />
                  <input type="number" min={1} placeholder={t('eventMgmt.seats')} value={group.capacity || ''}
                    onChange={(e) => { const g = [...form.bus_config]; g[idx] = { ...g[idx], capacity: Number(e.target.value) }; setForm({ ...form, bus_config: g }) }}
                    className="input-field flex-1" />
                  <button type="button" onClick={() => setForm({ ...form, bus_config: form.bus_config.filter((_, i) => i !== idx) })}
                    className="btn-icon btn-ghost text-red-500">✕</button>
                </div>
              ))}
              <button type="button" onClick={() => setForm({ ...form, bus_config: [...form.bus_config, { count: 1, capacity: 20 }] })}
                className="text-sm text-primary hover:underline">+ {t('eventMgmt.addBus')}</button>
            </div>
          )}
          {saveError && <div className="rounded-lg bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-600 dark:text-red-400">{saveError}</div>}
        </div>
      </Modal>

      <EventDetailModal event={viewing} isOpen={viewing !== null} onClose={() => setViewing(null)} />
    </div>
  )
}
