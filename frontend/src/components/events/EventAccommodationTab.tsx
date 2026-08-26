import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Bed, DoorClosed, Home, Plus, RefreshCw, Search, Trash2, UserPlus } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import {
  getAccommodationDashboard,
  listRooms,
  createRooms,
  deleteRoom,
  listUnaccommodated,
  assignAccommodation,
  removeAccommodation,
} from '@/api/eventRegistrations'
import type { EventAccommodationDashboard, EventRoom, EventRoomCell, EventRegistration } from '@/types'
import { logCatch } from '@/lib/debug'

interface Props {
  eventId: number
}

interface RoomGroup {
  count: number
  capacity: number
}

type StatusFilter = 'all' | 'has_available' | 'full'

export default function EventAccommodationTab({ eventId }: Props) {
  const { t } = useTranslation()
  const [dashboard, setDashboard] = useState<EventAccommodationDashboard | null>(null)
  const [rooms, setRooms] = useState<EventRoom[]>([])
  const [unaccommodated, setUnaccommodated] = useState<EventRegistration[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [assignTarget, setAssignTarget] = useState<{ registrationId: number | null; cell: EventRoomCell | null } | null>(null)
  const [removeTarget, setRemoveTarget] = useState<{ cell: EventRoomCell } | null>(null)
  const [roomGroups, setRoomGroups] = useState<RoomGroup[]>([{ count: 1, capacity: 5 }])
  const [saving, setSaving] = useState(false)
  const loadSeq = useRef(0)

  // Single parallel pass per load. AbortController + sequence guard prevent
  // StrictMode double-mount duplicates and stale-response races without
  // refetching more than needed.
  const fetchData = useCallback(
    async (signal?: AbortSignal) => {
      const seq = ++loadSeq.current
      try {
        const [dash, rms, unacc] = await Promise.all([
          getAccommodationDashboard(eventId),
          listRooms(eventId, { per_page: 100 }),
          listUnaccommodated(eventId, { per_page: 100 }),
        ])
        if (signal?.aborted || seq !== loadSeq.current) return
        setDashboard(dash)
        setRooms(rms.data)
        setUnaccommodated(unacc.data)
      } catch (err) {
        logCatch('EventAccommodationTab.fetch', err)
        if (!signal?.aborted && seq === loadSeq.current) toast.error(t('common.loading'))
      }
    },
    [eventId, t],
  )

  useEffect(() => {
    const controller = new AbortController()
    // Deferred to a microtask so state updates never happen synchronously
    // inside the effect body.
    void Promise.resolve().then(() => fetchData(controller.signal)).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [fetchData])

  // Targeted refresh after mutations: stats + rooms always, the pending list
  // only when an assignment changed membership of that list.
  const refetchAfterMutation = useCallback(
    async (withUnaccommodated: boolean) => {
      if (!withUnaccommodated) {
        try {
          const [dash, rms] = await Promise.all([
            getAccommodationDashboard(eventId),
            listRooms(eventId, { per_page: 100 }),
          ])
          setDashboard(dash)
          setRooms(rms.data)
        } catch (err) {
          logCatch('EventAccommodationTab.refetch', err)
        }
        return
      }
      await fetchData()
    },
    [eventId, fetchData],
  )

  const handleCreateRooms = async () => {
    setSaving(true)
    try {
      await createRooms(eventId, roomGroups)
      toast.success(t('eventMgmt.roomsCreated'))
      setShowCreateModal(false)
      setRoomGroups([{ count: 1, capacity: 5 }])
      await refetchAfterMutation(false)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteRoom = async (roomId: number) => {
    try {
      await deleteRoom(eventId, roomId)
      toast.success(t('common.deleted'))
      setRooms((prev) => prev.filter((r) => r.id !== roomId))
      await refetchAfterMutation(true)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    }
  }

  const openAssignFor = (cell: EventRoomCell) => {
    if (unaccommodated.length === 0) {
      toast.error(t('eventMgmt.noUnaccommodated'))
      return
    }
    setAssignTarget({ registrationId: unaccommodated[0]?.id ?? null, cell })
  }

  const handleAssign = async () => {
    if (!assignTarget?.registrationId || !assignTarget.cell) return
    setSaving(true)
    try {
      await assignAccommodation(eventId, assignTarget.registrationId, assignTarget.cell.id)
      toast.success(t('eventMgmt.accommodationAssigned'))
      setAssignTarget(null)
      await refetchAfterMutation(true)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
      // The picked cell may have been taken meanwhile — refresh availability.
      await refetchAfterMutation(true)
    } finally {
      setSaving(false)
    }
  }

  const handleRemove = async () => {
    if (!removeTarget) return
    setSaving(true)
    try {
      await removeAccommodation(eventId, removeTarget.cell.accommodation?.registration_id ?? 0)
      toast.success(t('eventMgmt.accommodationRemoved'))
      setRemoveTarget(null)
      await refetchAfterMutation(true)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const addRoomGroup = () => setRoomGroups((g) => [...g, { count: 1, capacity: 5 }])
  const removeRoomGroup = (idx: number) => setRoomGroups((g) => g.filter((_, i) => i !== idx))
  const updateRoomGroup = (idx: number, field: keyof RoomGroup, value: number) =>
    setRoomGroups((g) => g.map((grp, i) => (i === idx ? { ...grp, [field]: value } : grp)))

  const filteredRooms = useMemo(() => {
    const q = search.trim()
    return rooms.filter((room) => {
      if (q !== '' && !String(room.room_number).includes(q)) return false
      if (statusFilter === 'has_available' && (room.available_cells ?? 0) === 0) return false
      if (statusFilter === 'full' && (room.available_cells ?? 0) > 0) return false
      return true
    })
  }, [rooms, search, statusFilter])

  const totalPlaces = roomGroups.reduce((sum, g) => sum + g.count * g.capacity, 0)
  const totalServant = roomGroups.reduce((sum, g) => sum + g.count, 0)
  const totalMember = totalPlaces - totalServant

  if (loading) {
    return (
      <div className="space-y-4" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-20 animate-pulse rounded-xl border border-border bg-surface" />
          ))}
        </div>
        <div className="h-10 animate-pulse rounded-xl border border-border bg-surface" />
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-40 animate-pulse rounded-xl border border-border bg-surface" />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Stats header */}
      {dashboard && (
        <section className="rounded-xl border border-border bg-surface p-4">
          <div className="mb-3 flex items-center gap-2 border-b border-border pb-3">
            <Bed className="h-5 w-5 text-primary shrink-0" />
            <h2 className="text-base font-bold">{t('eventMgmt.accommodation')}</h2>
            <button
              onClick={() => void fetchData()}
              className="btn-ghost btn-sm ms-auto flex items-center gap-1"
              title={t('common.refresh')}
            >
              <RefreshCw className="h-4 w-4" />
              <span className="hidden sm:inline">{t('common.refresh')}</span>
            </button>
          </div>
          <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <Stat label={t('eventMgmt.totalRooms')} value={dashboard.total_rooms} />
            <Stat label={t('eventMgmt.totalCapacity')} value={dashboard.total_capacity} />
            <Stat label={t('eventMgmt.occupied')} value={dashboard.occupied_member_cells} tone="text-danger" />
            <Stat label={t('eventMgmt.available')} value={dashboard.available_member_cells} tone="text-success" />
            <Stat label={t('eventMgmt.servantCapacity')} value={dashboard.servant_capacity} tone="text-purple-500 dark:text-purple-400" />
            <Stat label={t('eventMgmt.notAccommodated')} value={dashboard.not_accommodated} tone="text-warning" />
          </dl>
        </section>
      )}

      {/* Legend */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-secondary">
        <LegendDot className="bg-success" label={t('eventMgmt.availableCell')} />
        <LegendDot className="bg-danger" label={t('eventMgmt.occupiedCell')} />
        <LegendDot className="bg-purple-500 dark:bg-purple-400" label={t('eventMgmt.servantCell')} />
      </div>

      {/* Actions */}
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        {rooms.length === 0 && (
          <button onClick={() => setShowCreateModal(true)} className="btn-primary btn-sm w-full sm:w-auto">
            <Plus className="h-4 w-4" /> {t('eventMgmt.addRooms')}
          </button>
        )}
        <div className="relative w-full sm:w-48">
          <Search className="pointer-events-none absolute top-1/2 h-4 w-4 -translate-y-1/2 text-muted start-3" />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('eventMgmt.searchRoom')}
            className="input-field w-full ps-9"
            aria-label={t('eventMgmt.searchRoom')}
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
          className="input-field w-full sm:w-40"
          aria-label={t('eventMgmt.filterStatus')}
        >
          <option value="all">{t('eventMgmt.filterAll')}</option>
          <option value="has_available">{t('eventMgmt.filterHasAvailable')}</option>
          <option value="full">{t('eventMgmt.filterFull')}</option>
        </select>
      </div>

      {/* Rooms */}
      {rooms.length === 0 ? (
        <div className="rounded-xl border border-border bg-surface p-8 text-center">
          <Home className="mx-auto h-12 w-12 text-secondary opacity-40" />
          <p className="mt-2 text-sm text-secondary">{t('eventMgmt.noRooms')}</p>
        </div>
      ) : filteredRooms.length === 0 ? (
        <div className="rounded-xl border border-border bg-surface p-8 text-center">
          <Search className="mx-auto h-10 w-10 text-secondary opacity-40" />
          <p className="mt-2 text-sm text-secondary">{t('common.noData')}</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {filteredRooms.map((room) => (
            <article key={room.id} className="flex flex-col rounded-xl border border-border bg-surface p-4">
              <header className="flex items-center justify-between gap-2 border-b border-border pb-2">
                <div className="flex min-w-0 items-center gap-2">
                  <DoorClosed className="h-4 w-4 shrink-0 text-primary" />
                  <h3 className="truncate font-semibold">
                    {t('eventMgmt.room')} #{room.room_number}
                  </h3>
                </div>
                {!room.is_active && <Badge variant="warning">{t('events.inactive')}</Badge>}
              </header>

              <div className="flex flex-wrap gap-x-4 gap-y-1 py-2 text-xs text-secondary">
                <span>{t('eventMgmt.capacity')}: <strong className="text-primary">{room.capacity}</strong></span>
                <span>
                  <span className="text-success">●</span> {t('eventMgmt.available')}: <strong>{room.available_cells ?? 0}</strong>
                </span>
                <span>
                  <span className="text-danger">●</span> {t('eventMgmt.occupied')}: <strong>{room.occupied_cells ?? 0}</strong>
                </span>
              </div>

              <ul className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                {(room.cells ?? []).map((cell) => {
                  if (cell.type === 'servant_reserved') {
                    return (
                      <li
                        key={cell.id}
                        title={t('eventMgmt.servantCell')}
                        className="flex cursor-not-allowed items-center justify-center gap-1 rounded-lg border border-purple-300 bg-purple-50 px-2 py-2 text-xs font-medium text-purple-700 dark:border-purple-500/40 dark:bg-purple-500/10 dark:text-purple-300"
                      >
                        <span className="h-2 w-2 shrink-0 rounded-full bg-purple-500 dark:bg-purple-400" />
                        <span className="truncate">{cell.cell_number}</span>
                      </li>
                    )
                  }

                  if (!cell.is_available) {
                    return (
                      <li key={cell.id}>
                        <button
                          type="button"
                          onClick={() => setRemoveTarget({ cell })}
                          title={cell.accommodation ? `${t('eventMgmt.occupiedBy')}: ${cell.accommodation.user.name}` : t('eventMgmt.occupiedCell')}
                          className="group flex w-full cursor-pointer items-center justify-center gap-1 rounded-lg border border-red-200 bg-red-50 px-2 py-2 text-xs font-medium text-red-700 transition-colors hover:bg-red-100 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20"
                        >
                          <span className="h-2 w-2 shrink-0 rounded-full bg-danger" />
                          <span className="min-w-0 truncate">
                            {cell.accommodation ? cell.accommodation.user.name : `${cell.cell_number}`}
                          </span>
                        </button>
                      </li>
                    )
                  }

                  return (
                    <li key={cell.id}>
                      <button
                        type="button"
                        onClick={() => openAssignFor(cell)}
                        title={t('eventMgmt.assignAccommodation')}
                        disabled={unaccommodated.length === 0}
                        className="flex w-full items-center justify-center gap-1 rounded-lg border border-green-200 bg-green-50 px-2 py-2 text-xs font-medium text-green-700 transition-colors enabled:hover:bg-green-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-green-500/40 dark:bg-green-500/10 dark:text-green-300 dark:enabled:hover:bg-green-500/20"
                      >
                        <span className="h-2 w-2 shrink-0 rounded-full bg-success" />
                        {cell.cell_number}
                      </button>
                    </li>
                  )
                })}
              </ul>

              <footer className="mt-3 flex items-center justify-between border-t border-border pt-3">
                <span className="text-xs text-secondary">
                  {room.member_capacity} {t('eventMgmt.memberCapacity')}
                </span>
                <button
                  onClick={() => void handleDeleteRoom(room.id)}
                  className="btn-ghost btn-sm text-danger"
                  title={t('common.delete')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </footer>
            </article>
          ))}
        </div>
      )}

      {/* Create Rooms Modal */}
      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title={t('eventMgmt.addRooms')}
        size="md"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setShowCreateModal(false)} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button onClick={handleCreateRooms} disabled={saving} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : t('common.create')}
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          {roomGroups.map((group, idx) => (
            <div key={idx} className="flex items-end gap-3">
              <div className="min-w-0 flex-1">
                <label className="mb-1 block text-xs font-medium">{t('eventMgmt.numberOfRooms')}</label>
                <input
                  type="number"
                  min={1}
                  value={group.count}
                  onChange={(e) => updateRoomGroup(idx, 'count', parseInt(e.target.value) || 1)}
                  className="input-field w-full"
                />
              </div>
              <div className="min-w-0 flex-1">
                <label className="mb-1 block text-xs font-medium">{t('eventMgmt.capacityPerRoom')}</label>
                <input
                  type="number"
                  min={2}
                  value={group.capacity}
                  onChange={(e) => updateRoomGroup(idx, 'capacity', parseInt(e.target.value) || 2)}
                  className="input-field w-full"
                />
              </div>
              {roomGroups.length > 1 && (
                <button onClick={() => removeRoomGroup(idx)} className="btn-ghost btn-sm shrink-0 text-danger">
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>
          ))}
          <button onClick={addRoomGroup} className="btn-ghost btn-sm text-primary">
            <Plus className="h-4 w-4" /> {t('eventMgmt.addRoomGroup')}
          </button>
          <div className="rounded-lg bg-surface-secondary p-3 text-sm">
            <p>{t('eventMgmt.totalRooms')}: <strong>{roomGroups.reduce((s, g) => s + g.count, 0)}</strong></p>
            <p>{t('eventMgmt.totalCapacity')}: <strong>{totalPlaces}</strong></p>
            <p>{t('eventMgmt.memberCapacity')}: <strong>{totalMember}</strong></p>
            <p>{t('eventMgmt.servantCapacity')}: <strong>{totalServant}</strong></p>
          </div>
        </div>
      </Modal>

      {/* Assign Accommodation Modal */}
      <Modal
        isOpen={assignTarget !== null}
        onClose={() => setAssignTarget(null)}
        title={t('eventMgmt.assignAccommodation')}
        size="md"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setAssignTarget(null)} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button onClick={handleAssign} disabled={saving || !assignTarget?.registrationId} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : t('eventMgmt.assignAccommodation')}
            </button>
          </div>
        }
      >
        {assignTarget && (
          <div className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium">{t('eventMgmt.assigningFor')}</label>
              <select
                value={assignTarget.registrationId ?? ''}
                onChange={(e) => setAssignTarget({ ...assignTarget, registrationId: Number(e.target.value) })}
                className="input-field w-full"
              >
                {unaccommodated.map((reg) => (
                  <option key={reg.id} value={reg.id}>
                    {reg.user?.name ?? `#${reg.id}`}
                  </option>
                ))}
              </select>
            </div>
            {assignTarget.cell && (
              <div className="flex items-center gap-2 rounded-lg bg-surface-secondary p-3 text-sm">
                <UserPlus className="h-4 w-4 shrink-0 text-primary" />
                <span>
                  {t('eventMgmt.room')} #{assignTarget.cell.room_id ? roomNumberFor(rooms, assignTarget.cell.room_id) : ''} ·{' '}
                  {t('eventMgmt.memberCell')} #{assignTarget.cell.cell_number}
                </span>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Remove Assignment Confirm Modal */}
      <Modal
        isOpen={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        title={t('eventMgmt.removeAccommodationTitle')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setRemoveTarget(null)} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button onClick={handleRemove} disabled={saving} className="flex-1 btn-danger btn-md">
              {saving ? t('common.saving') : t('common.delete')}
            </button>
          </div>
        }
      >
        {removeTarget && (
          <p className="text-sm">
            {t('eventMgmt.removeAccommodationConfirm', {
              name: removeTarget.cell.accommodation?.user.name ?? '',
            })}
          </p>
        )}
      </Modal>
    </div>
  )
}

function roomNumberFor(rooms: EventRoom[], roomId: number): number | null {
  return rooms.find((r) => r.id === roomId)?.room_number ?? null
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div className="rounded-lg border border-border bg-background px-3 py-2">
      <dd className={`text-xl font-bold ${tone ?? 'text-primary'}`}>{value}</dd>
      <dt className="mt-0.5 truncate text-xs text-secondary">{label}</dt>
    </div>
  )
}

function LegendDot({ className, label }: { className: string; label: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className={`inline-block h-2.5 w-2.5 rounded-full ${className}`} />
      {label}
    </span>
  )
}
