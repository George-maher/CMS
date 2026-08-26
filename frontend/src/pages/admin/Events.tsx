import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
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
import EventActiveStatus from '@/components/events/EventActiveStatus'

interface RoomGroup { count: number; capacity: number }
interface BusEntry { capacity: number }

interface EventForm { name: string; type: string; image: string | File; description: string; event_date: string; location: string; class_id: string; is_active: boolean; is_all_classes: boolean; target_class_ids: number[]; responsible_servant_id: string; total_rooms: string; room_groups: RoomGroup[]; bus_config: BusEntry[]; church_id: number }

const emptyForm: EventForm = { name: '', type: 'service', image: '', description: '', event_date: '', location: '', class_id: '', is_active: true, is_all_classes: false, target_class_ids: [], responsible_servant_id: '', total_rooms: '', room_groups: [], bus_config: [], church_id: 0 }

export default function AdminEvents() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [viewMode, setViewMode] = useState<'list' | 'calendar'>('list')

  const columns: Column<Event>[] = [
    { key: 'image', header: '', render: (e) => e.image ? (
      <ImageWithFallback src={e.image} alt={e.name} className="h-10 w-16 rounded object-cover" />
    ) : <div className="h-10 w-16 rounded bg-surface-tertiary" /> },
    { key: 'name', header: t('events.eventName'), render: (e) => <span className="font-medium">{e.name}</span> },
    { key: 'type', header: t('events.eventType'), render: (e) => <Badge variant="info">{t(`events.type_${e.type}`)}</Badge> },
    { key: 'status', header: t('common.status'), render: (e) => <EventActiveStatus event={e} /> },
    { key: 'capacity', header: t('eventMgmt.capacity'), render: (e) => e.max_capacity ? `${e.registered_count ?? 0} / ${e.max_capacity}` : '-' },
    { key: 'event_date', header: t('events.eventDate'), render: (e) => e.event_date ? new Date(e.event_date).toLocaleDateString() : '-' },
    { key: 'location', header: t('events.location') },
    { key: 'class_id', header: t('events.target'), render: (e) => e.classe?.name ?? t('events.allClasses') },
    { key: 'creator', header: t('events.createdBy'), render: (e) => e.creator?.name ?? '-' },
  ]
  const [events, setEvents] = useState<Event[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Event | null>(null)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [servants, setServants] = useState<{ id: number; name: string; phone?: string | null; church_id?: number | null }[]>([])
  const [filteredServants, setFilteredServants] = useState<{ id: number; name: string; phone?: string | null; church_id?: number | null }[]>([])
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [viewing, setViewing] = useState<Event | null>(null)
  const [viewLoading, setViewLoading] = useState(false)
  const [changeServantModalOpen, setChangeServantModalOpen] = useState(false)
  const [form, setForm] = useState<EventForm>(emptyForm)
  const [servantEvent, setServantEvent] = useState<Event | null>(null)
  const [newServantId, setNewServantId] = useState<string>('')
  const [servantsLoading, setServantsLoading] = useState(true)
  const [servantSearch, setServantSearch] = useState('')

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
    getServants().then(servants => {
      const churchId = user?.church_id ?? 0
      const filtered = servants.filter((s) => s.church_id === churchId)
      setServants(servants)
      setFilteredServants(filtered)
      setServantsLoading(false)
    }).catch((e) => {
      logCatch('AdminEvents.getServants', e)
      setServantsLoading(false)
    })
  }, [user?.church_id])

  const fetch = async (page = 1) => {
    setLoading(true)
    try { const res = await listEvents({ page, per_page: 15, upcoming: false }); setEvents(res.data); setMeta(res.meta) }
    finally { setLoading(false) }
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ ...emptyForm, event_date: new Date().toISOString().slice(0, 16), church_id: user?.church_id ?? 0 }); setSaveError(''); setShowModal(true); setServantSearch('')
  }

  const openEdit = (event: Event) => {
    setEditing(event)
    const eventChurchId = user?.church_id ?? 0
    const eventServants = servants.filter((s) => s.church_id === eventChurchId)
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
      total_rooms: '',
      room_groups: [],
      bus_config: [],
      church_id: eventChurchId,
    })
    setFilteredServants(eventServants)
    setSaveError(''); setShowModal(true); setServantSearch('')
  }

  const handleSave = async () => {
    if (['conference', 'trip'].includes(form.type) && !form.responsible_servant_id) {
      setSaveError(t('eventMgmt.responsibleServantRequired'))
      return
    }
    setSaving(true); setSaveError('')
    try {
      const payload: Record<string, unknown> = { name: form.name, type: form.type, description: form.description, location: form.location || null, class_id: form.class_id ? Number(form.class_id) : null, is_active: form.is_active, is_all_classes: form.is_all_classes, church_id: form.church_id }
      if (form.is_all_classes) {
        payload.target_class_ids = []
      } else if (form.target_class_ids.length > 0) {
        payload.target_class_ids = form.target_class_ids
      }
      if (form.event_date) payload.event_date = new Date(form.event_date).toISOString()
      if (form.image instanceof File) { payload.image = form.image }
      else if (editing && (form.image === '' || form.image === null)) { payload.remove_image = true }
      if (form.responsible_servant_id) {
        payload.responsible_servant_id = Number(form.responsible_servant_id)
      }
      if (['conference', 'trip'].includes(form.type) && !editing) {
        if (form.room_groups.length > 0) {
          const totalRooms = form.total_rooms ? Number(form.total_rooms) : form.room_groups.reduce((s, g) => s + (g.count || 0), 0)
          payload.total_rooms = totalRooms
          payload.room_groups = form.room_groups.filter(g => g.count > 0 && g.capacity > 1)
        }
        if (form.bus_config.length > 0) {
          payload.bus_config = form.bus_config.filter(b => b.capacity > 0)
        }
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

  const openChangeServantModal = (event: Event) => {
    setServantEvent(event)
    const eventChurchId = user?.church_id ?? 0
    setFilteredServants(servants.filter((s) => s.church_id === eventChurchId))
    setNewServantId(event.responsible_servant_id?.toString() ?? '')
    setSaveError('')
    setChangeServantModalOpen(true)
  }

  const handleChangeServant = async () => {
    if (!servantEvent || !newServantId) return
    try {
      await updateEvent(servantEvent.id, { responsible_servant_id: Number(newServantId) })
      setChangeServantModalOpen(false)
      fetch()
      toast.success(t('common.update'))
    } catch (e) {
      logCatch('AdminEvents.changeServant', e)
      toast.error(t('common.saving'))
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
              {e.responsible_servant_id && (
                <button onClick={() => openChangeServantModal(e)} className="btn-icon btn-ghost text-primary-600">{t('eventMgmt.changeResponsibleServant')}</button>
              )}
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
            <div className="rounded-lg border border-border p-3 space-y-2">
              <label className="block text-sm font-medium">{t('eventMgmt.responsibleServant')}</label>
              <p className="text-xs text-secondary">{t('eventMgmt.responsibleServantHint')}</p>
              {servantsLoading ? (
                <div className="flex items-center gap-2 py-2 text-sm text-secondary">
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
                  {t('eventMgmt.loadingServants')}
                </div>
              ) : filteredServants.length === 0 ? (
                <p className="py-1 text-sm text-secondary">{t('eventMgmt.noServantsAvailable')}</p>
              ) : (
                <>
                  <input
                    type="search"
                    placeholder={t('eventMgmt.searchServant')}
                    value={servantSearch}
                    onChange={(e) => setServantSearch(e.target.value)}
                    className="input-field"
                  />
                  <select
                    value={form.responsible_servant_id}
                    onChange={(e) => setForm({ ...form, responsible_servant_id: e.target.value })}
                    className="input-field"
                  >
                    <option value="">{t('eventMgmt.selectResponsibleServant')}</option>
                    {filteredServants
                      .filter((s) => {
                        const q = servantSearch.trim().toLowerCase()
                        if (!q) return true
                        return s.name.toLowerCase().includes(q) || (s.phone ?? '').includes(q)
                      })
                      .map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.name} {t('events.type_servant')}{s.phone ? ` — ${s.phone}` : ''}
                        </option>
                      ))}
                  </select>
                </>
              )}
              {form.responsible_servant_id && (() => {
                const selected = filteredServants.find(s => s.id === Number(form.responsible_servant_id))
                return (
                  <div className="rounded-lg bg-surface-secondary p-3 text-xs space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-sm break-all">{selected?.name ?? '—'}</span>
                      <span className="rounded-full bg-primary/10 px-2 py-0.5 text-primary text-xs">{t('events.type_servant')}</span>
                    </div>
                    {selected?.phone && <div className="text-secondary break-all">{selected.phone}</div>}
                  </div>
                )
              })()}
            </div>
          )}
          {['conference', 'trip'].includes(form.type) && !editing && (
            <>
              <div className="space-y-2 rounded-lg border border-border p-3">
                <label className="block text-sm font-medium">{t('eventMgmt.accommodation')}</label>
                <div>
                  <label className="block text-xs text-secondary mb-1">{t('eventMgmt.totalRoomsLabel')}</label>
                  <input type="number" min={1} placeholder={t('eventMgmt.totalRoomsLabel')} value={form.total_rooms}
                    onChange={(e) => setForm({ ...form, total_rooms: e.target.value })} className="input-field w-32" />
                </div>
                {form.room_groups.map((group, idx) => (
                  <div key={idx} className="flex gap-2 items-center">
                    <input type="number" min={1} placeholder={t('eventMgmt.numberOfRooms')} value={group.count || ''}
                      onChange={(e) => { const g = [...form.room_groups]; g[idx] = { ...g[idx], count: Number(e.target.value) } as RoomGroup; setForm({ ...form, room_groups: g }) }}
                      className="input-field flex-1" />
                    <span className="text-xs text-secondary">→</span>
                    <input type="number" min={2} placeholder={t('eventMgmt.capacityPerRoom')} value={group.capacity || ''}
                      onChange={(e) => { const g = [...form.room_groups]; g[idx] = { ...g[idx], capacity: Number(e.target.value) } as RoomGroup; setForm({ ...form, room_groups: g }) }}
                      className="input-field flex-1" />
                    <button type="button" onClick={() => setForm({ ...form, room_groups: form.room_groups.filter((_, i) => i !== idx) })}
                      className="btn-icon btn-ghost text-red-500">✕</button>
                  </div>
                ))}
                <button type="button" onClick={() => setForm({ ...form, room_groups: [...form.room_groups, { count: 1, capacity: 5 }] })}
                  className="text-sm text-primary hover:underline">+ {t('eventMgmt.addRoomGroup')}</button>
                {form.room_groups.length > 0 && (() => {
                  const totalRoomCount = form.room_groups.reduce((s, g) => s + (g.count || 0), 0)
                  const totalCapacity = form.room_groups.reduce((s, g) => s + (g.count || 0) * (g.capacity || 0), 0)
                  const totalMemberCapacity = form.room_groups.reduce((s, g) => s + (g.count || 0) * Math.max(0, (g.capacity || 0) - 1), 0)
                  const totalServantCells = totalRoomCount
                  const roomsMatch = !form.total_rooms || totalRoomCount === Number(form.total_rooms)
                  return (
                    <div className={`mt-2 rounded-lg p-2 text-xs space-y-1 ${roomsMatch ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'}`}>
                      <div className="flex justify-between"><span className="text-secondary">{t('eventMgmt.totalRooms')}</span><span className="font-medium">{totalRoomCount}{form.total_rooms && !roomsMatch ? ` / ${form.total_rooms}` : ''}</span></div>
                      <div className="flex justify-between"><span className="text-secondary">{t('eventMgmt.totalCapacity')}</span><span className="font-medium">{totalCapacity}</span></div>
                      <div className="flex justify-between"><span className="text-secondary">{t('eventMgmt.memberCapacity')}</span><span className="font-medium">{totalMemberCapacity}</span></div>
                      <div className="flex justify-between"><span className="text-secondary">{t('eventMgmt.servantCapacity')}</span><span className="font-medium">{totalServantCells}</span></div>
                      {!roomsMatch && <p className="text-red-600 dark:text-red-400">{t('eventMgmt.roomGroupMismatch')}</p>}
                    </div>
                  )
                })()}
              </div>
              <div className="space-y-2 rounded-lg border border-border p-3">
                <label className="block text-sm font-medium">{t('eventMgmt.tabBuses')}</label>
                {form.bus_config.map((bus, idx) => (
                  <div key={idx} className="flex gap-2 items-center">
                    <span className="text-xs text-secondary whitespace-nowrap">{t('eventMgmt.bus')} {idx + 1}</span>
                    <span className="text-xs text-secondary">→</span>
                    <input type="number" min={1} placeholder={t('eventMgmt.seats')} value={bus.capacity || ''}
                      onChange={(e) => { const b = [...form.bus_config]; b[idx] = { ...b[idx], capacity: Number(e.target.value) } as BusEntry; setForm({ ...form, bus_config: b }) }}
                      className="input-field flex-1" />
                    <span className="text-xs text-secondary">{t('eventMgmt.seats')}</span>
                    <button type="button" onClick={() => setForm({ ...form, bus_config: form.bus_config.filter((_, i) => i !== idx) })}
                      className="btn-icon btn-ghost text-red-500">✕</button>
                  </div>
                ))}
                <button type="button" onClick={() => setForm({ ...form, bus_config: [...form.bus_config, { capacity: 50 }] })}
                  className="text-sm text-primary hover:underline">+ {t('eventMgmt.addBus')}</button>
                {form.bus_config.length > 0 && (() => {
                  const totalBusCapacity = form.bus_config.reduce((s, b) => s + (b.capacity || 0), 0)
                  return (
                    <div className="mt-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 p-2 text-xs">
                      <div className="flex justify-between"><span className="text-secondary">{t('eventMgmt.totalBusCapacity')}</span><span className="font-medium">{totalBusCapacity} {t('eventMgmt.seats')} ({form.bus_config.length} {t('eventMgmt.buses')})</span></div>
                    </div>
                  )
                })()}
              </div>
            </>
          )}
          {saveError && <div className="rounded-lg bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-600 dark:text-red-400">{saveError}</div>}
        </div>
      </Modal>

      <EventDetailModal event={viewing} isOpen={viewing !== null} onClose={() => setViewing(null)} />
      
      <Modal isOpen={changeServantModalOpen} onClose={() => setChangeServantModalOpen(false)} title={t('eventMgmt.changeResponsibleServant')} size="sm">
        <div className="space-y-4">
          <div className="rounded-lg border border-border p-4">
            <label className="block text-sm font-medium">{t('eventMgmt.currentResponsibleServant')}</label>
            {servantEvent?.responsible_servant_id ? (
              <div className="rounded-lg bg-surface-secondary p-2 text-xs space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-sm">{servantEvent.responsible_servant?.name ?? filteredServants.find(s => s.id === servantEvent.responsible_servant_id)?.name ?? '—'}</span>
                  <span className="rounded-full bg-primary/10 px-2 py-0.5 text-primary text-xs">{t('events.type_servant')}</span>
                </div>
                {(servantEvent.responsible_servant?.phone ?? filteredServants.find(s => s.id === servantEvent.responsible_servant_id)?.phone) && (
                  <div className="text-secondary">{servantEvent.responsible_servant?.phone ?? filteredServants.find(s => s.id === servantEvent.responsible_servant_id)?.phone}</div>
                )}
              </div>
            ) : (
              <p className="text-sm text-secondary">{t('events.noServantAssigned')}</p>
            )}
          </div>
          <label className="block text-sm font-medium">{t('eventMgmt.newResponsibleServant')}</label>
          <select value={newServantId} onChange={(e) => setNewServantId(e.target.value)} className="input-field">
            <option value="">--</option>
            {filteredServants.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name} {t('events.type_servant')}{s.phone ? ` — ${s.phone}` : ''}
              </option>
            ))}
          </select>
          <div className="mt-3">
            <button onClick={() => { void handleChangeServant() }} disabled={!newServantId} className="btn-primary btn-md w-full disabled:opacity-50">{t('common.save')}</button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
