import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Home, Users, Plus, Trash2 } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import {
  getAccommodationDashboard,
  listRooms,
  createRooms,
  deleteRoom,
  listUnaccommodated,
  assignAccommodation,
} from '@/api/eventRegistrations'
import type { EventAccommodationDashboard, EventRoom, EventRegistration } from '@/types'
import { logCatch } from '@/lib/debug'

interface Props {
  eventId: number
}

interface RoomGroup {
  count: number
  capacity: number
}

export default function EventAccommodationTab({ eventId }: Props) {
  const { t } = useTranslation()
  const [dashboard, setDashboard] = useState<EventAccommodationDashboard | null>(null)
  const [rooms, setRooms] = useState<EventRoom[]>([])
  const [unaccommodated, setUnaccommodated] = useState<EventRegistration[]>([])
  const [loading, setLoading] = useState(true)
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [showAssignModal, setShowAssignModal] = useState(false)
  const [selectedRegistration, setSelectedRegistration] = useState<EventRegistration | null>(null)
  const [roomGroups, setRoomGroups] = useState<RoomGroup[]>([{ count: 1, capacity: 5 }])
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let cancelled = false
    const load = async () => {
      setLoading(true)
      try {
        const [dash, rms, unacc] = await Promise.all([
          getAccommodationDashboard(eventId),
          listRooms(eventId, { per_page: 50 }),
          listUnaccommodated(eventId, { per_page: 50 }),
        ])
        if (!cancelled) {
          setDashboard(dash)
          setRooms(rms.data)
          setUnaccommodated(unacc.data)
        }
      } catch (err) {
        logCatch('EventAccommodationTab.fetch', err)
        toast.error(t('common.loading'))
      } finally {
        if (!cancelled) setLoading(false)
      }
    }
    void load()
    return () => { cancelled = true }
  }, [eventId, t])

  const refetch = useCallback(async () => {
    try {
      const [dash, rms, unacc] = await Promise.all([
        getAccommodationDashboard(eventId),
        listRooms(eventId, { per_page: 50 }),
        listUnaccommodated(eventId, { per_page: 50 }),
      ])
      setDashboard(dash)
      setRooms(rms.data)
      setUnaccommodated(unacc.data)
    } catch (err) {
      logCatch('EventAccommodationTab.refetch', err)
    }
  }, [eventId])

  const handleCreateRooms = async () => {
    setSaving(true)
    try {
      await createRooms(eventId, roomGroups)
      toast.success(t('eventMgmt.roomsCreated'))
      setShowCreateModal(false)
      setRoomGroups([{ count: 1, capacity: 5 }])
      refetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteRoom = async (roomId: number) => {
    if (!confirm(t('common.confirm'))) return
    try {
      await deleteRoom(eventId, roomId)
      toast.success(t('common.deleted'))
      refetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    }
  }

  const handleAssign = async (cellId: number) => {
    if (!selectedRegistration) return
    setSaving(true)
    try {
      await assignAccommodation(eventId, selectedRegistration.id, cellId)
      toast.success(t('eventMgmt.accommodationAssigned'))
      setShowAssignModal(false)
      setSelectedRegistration(null)
      refetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const addRoomGroup = () => setRoomGroups([...roomGroups, { count: 1, capacity: 5 }])
  const removeRoomGroup = (idx: number) => setRoomGroups(roomGroups.filter((_, i) => i !== idx))
  const updateRoomGroup = (idx: number, field: keyof RoomGroup, value: number) => {
    const updated = [...roomGroups]
    updated[idx] = { ...updated[idx], [field]: value } as RoomGroup
    setRoomGroups(updated)
  }

  const totalRooms = roomGroups.reduce((sum, g) => sum + g.count, 0)
  const totalPlaces = roomGroups.reduce((sum, g) => sum + g.count * g.capacity, 0)
  const totalServant = roomGroups.reduce((sum, g) => sum + g.count, 0)
  const totalMember = totalPlaces - totalServant

  if (loading) {
    return (
      <div className="flex py-10 items-center justify-center">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Dashboard Stats */}
      {dashboard && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard icon={<Home className="h-5 w-5" />} label={t('eventMgmt.totalRooms')} value={dashboard.total_rooms} />
          <StatCard icon={<Users className="h-5 w-5" />} label={t('eventMgmt.totalCapacity')} value={dashboard.total_capacity} />
          <StatCard icon={<Users className="h-5 w-5" />} label={t('eventMgmt.approvedReservations')} value={dashboard.approved_reservations} variant="info" />
          <StatCard icon={<Users className="h-5 w-5" />} label={t('eventMgmt.accommodated')} value={dashboard.accommodated} variant="success" />
        </div>
      )}

      {/* Actions */}
      <div className="flex flex-wrap gap-2">
        <button onClick={() => setShowCreateModal(true)} className="btn-primary btn-sm">
          <Plus className="h-4 w-4" /> {t('eventMgmt.addRooms')}
        </button>
        {unaccommodated.length > 0 && (
          <button onClick={() => { setSelectedRegistration(unaccommodated[0] ?? null); setShowAssignModal(true) }} className="btn-secondary btn-sm">
            <Home className="h-4 w-4" /> {t('eventMgmt.assignAccommodation')} ({unaccommodated.length})
          </button>
        )}
      </div>

      {/* Rooms Table */}
      {rooms.length === 0 ? (
        <div className="rounded-xl border border-border bg-surface p-8 text-center">
          <Home className="mx-auto h-12 w-12 text-secondary" />
          <p className="mt-2 text-sm text-secondary">{t('eventMgmt.noRooms')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface-secondary">
              <tr>
                <th className="px-4 py-3 text-left font-medium">{t('eventMgmt.room')}</th>
                <th className="px-4 py-3 text-left font-medium">{t('eventMgmt.capacity')}</th>
                <th className="px-4 py-3 text-left font-medium">{t('eventMgmt.memberCapacity')}</th>
                <th className="px-4 py-3 text-left font-medium">{t('eventMgmt.occupied')}</th>
                <th className="px-4 py-3 text-left font-medium">{t('eventMgmt.available')}</th>
                <th className="px-4 py-3 text-right font-medium">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rooms.map((room) => (
                <tr key={room.id} className="hover:bg-surface-secondary/50">
                  <td className="px-4 py-3 font-medium">#{room.room_number}</td>
                  <td className="px-4 py-3">{room.capacity}</td>
                  <td className="px-4 py-3">{room.member_capacity}</td>
                  <td className="px-4 py-3">
                    <Badge variant="warning">{room.occupied_cells ?? 0}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <Badge variant="success">{room.available_cells ?? 0}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={() => handleDeleteRoom(room.id)}
                      className="text-danger hover:text-danger/80"
                      title={t('common.delete')}
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
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
              <div className="flex-1">
                <label className="mb-1 block text-xs font-medium">{t('eventMgmt.numberOfRooms')}</label>
                <input
                  type="number"
                  min={1}
                  value={group.count}
                  onChange={(e) => updateRoomGroup(idx, 'count', parseInt(e.target.value) || 1)}
                  className="input-field w-full"
                />
              </div>
              <div className="flex-1">
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
                <button onClick={() => removeRoomGroup(idx)} className="btn-ghost btn-sm text-danger">
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>
          ))}
          <button onClick={addRoomGroup} className="btn-ghost btn-sm text-primary">
            <Plus className="h-4 w-4" /> {t('eventMgmt.addRoomGroup')}
          </button>
          <div className="rounded-lg bg-surface-secondary p-3 text-sm">
            <p>{t('eventMgmt.totalRooms')}: <strong>{totalRooms}</strong></p>
            <p>{t('eventMgmt.totalCapacity')}: <strong>{totalPlaces}</strong></p>
            <p>{t('eventMgmt.memberCapacity')}: <strong>{totalMember}</strong></p>
            <p>{t('eventMgmt.servantCapacity')}: <strong>{totalServant}</strong></p>
          </div>
        </div>
      </Modal>

      {/* Assign Accommodation Modal */}
      <Modal
        isOpen={showAssignModal}
        onClose={() => { setShowAssignModal(false); setSelectedRegistration(null) }}
        title={t('eventMgmt.assignAccommodation')}
        size="lg"
      >
        {selectedRegistration && (
          <div className="space-y-4">
            <p className="text-sm">
              {t('eventMgmt.assigningFor')}: <strong>{selectedRegistration.user?.name}</strong>
            </p>
            {rooms.map((room) => (
              <div key={room.id} className="rounded-lg border border-border p-3">
                <p className="mb-2 font-medium">{t('eventMgmt.room')} #{room.room_number} ({room.capacity} {t('eventMgmt.capacity')})</p>
                <div className="flex flex-wrap gap-2">
                  {room.cells?.map((cell) => (
                    <button
                      key={cell.id}
                      onClick={() => cell.is_available && cell.type === 'member' ? handleAssign(cell.id) : undefined}
                      disabled={!cell.is_available || cell.type === 'member' === false}
                      className={`rounded-lg px-3 py-2 text-sm ${
                        cell.type === 'servant_reserved'
                          ? 'bg-gold-100 text-gold-800 cursor-not-allowed'
                          : cell.is_available
                            ? 'bg-success/10 text-success hover:bg-success/20 cursor-pointer'
                            : 'bg-danger/10 text-danger cursor-not-allowed'
                      }`}
                    >
                      {cell.type === 'servant_reserved' ? `S${cell.cell_number}` : `M${cell.cell_number}`}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </Modal>
    </div>
  )
}

function StatCard({ icon, label, value, variant = 'default' }: {
  icon: React.ReactNode
  label: string
  value: number
  variant?: 'default' | 'info' | 'success' | 'warning'
}) {
  const colors = {
    default: 'text-secondary',
    info: 'text-blue-500',
    success: 'text-green-500',
    warning: 'text-yellow-500',
  }
  return (
    <div className="rounded-xl border border-border bg-surface p-4">
      <div className={`flex items-center gap-2 ${colors[variant]}`}>
        {icon}
        <span className="text-2xl font-bold">{value}</span>
      </div>
      <p className="mt-1 text-xs text-secondary">{label}</p>
    </div>
  )
}
