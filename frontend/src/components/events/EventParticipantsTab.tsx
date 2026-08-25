import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Plus } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { EventBusItem, EventRegistration, User } from '@/types'
import {
  addParticipant,
  approveReservation,
  assignBus,
  cancelRegistration,
  checkIn,
  confirmRegistration,
  listBuses,
  listRegistrations,
  rejectReservation,
  removeRegistration,
  undoCheckIn,
  waitlistRegistration,
} from '@/api/eventRegistrations'
import { getMembers } from '@/api/users'
import { logCatch } from '@/lib/debug'
import {
  attendanceStatusLabelKey,
  attendanceStatusVariant,
  paymentStatusLabelKey,
  paymentStatusVariant,
  registrationStatusLabelKey,
  registrationStatusVariant,
} from './eventStatus'

interface Props {
  eventId: number
  isTrip: boolean
}

export default function EventParticipantsTab({ eventId, isTrip }: Props) {
  const { t } = useTranslation()

  const [rows, setRows] = useState<EventRegistration[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [showAdd, setShowAdd] = useState(false)
  const [memberQuery, setMemberQuery] = useState('')
  const [members, setMembers] = useState<User[]>([])
  const [selectedMember, setSelectedMember] = useState<User | null>(null)
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const [buses, setBuses] = useState<EventBusItem[]>([])
  const [confirmRemoveId, setConfirmRemoveId] = useState<number | null>(null)

  const fetch = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const res = await listRegistrations(eventId, {
        page,
        per_page: 20,
        search: search || undefined,
        status: statusFilter || undefined,
      })
      setRows(res.data)
      setMeta(res.meta)
    } catch (e) {
      logCatch('EventParticipantsTab.listRegistrations', e)
      toast.error(t('eventMgmt.actionFailed'))
    } finally {
      setLoading(false)
    }
  }, [eventId, search, statusFilter, t])

  useEffect(() => {
    void Promise.resolve().then(() => fetch(1))
  }, [fetch])

  useEffect(() => {
    if (isTrip) {
      listBuses(eventId).then(setBuses).catch((e) => logCatch('EventParticipantsTab.listBuses', e))
    }
  }, [eventId, isTrip])

  const searchMembers = async () => {
    if (!memberQuery.trim()) return
    try {
      const res = await getMembers()
      const q = memberQuery.trim().toLowerCase()
      setMembers(res.filter((m) => m.name.toLowerCase().includes(q) || m.phone?.includes(q)).slice(0, 10))
    } catch (e) {
      logCatch('EventParticipantsTab.getMembers', e)
      toast.error(t('eventMgmt.actionFailed'))
    }
  }

  const handleAdd = async () => {
    if (!selectedMember) return
    setSaving(true)
    try {
      await addParticipant(eventId, { user_id: selectedMember.id, notes: notes || undefined })
      setShowAdd(false)
      setSelectedMember(null)
      setNotes('')
      fetch(meta.current_page)
      toast.success(t('eventMgmt.participantAdded'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const runAction = async (action: () => Promise<unknown>, successMsg?: string) => {
    try {
      await action()
      if (successMsg) toast.success(successMsg)
      fetch(meta.current_page)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    }
  }

  const regColumns: Column<EventRegistration>[] = [
    {
      key: 'user',
      header: t('eventMgmt.participant'),
      render: (r) => (
        <div className="min-w-0">
          <p className="truncate font-medium">{r.user?.name}</p>
          <p className="truncate text-xs text-secondary">{r.user?.phone ?? ''}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('eventMgmt.registration'),
      render: (r) => <Badge variant={registrationStatusVariant[r.status]}>{t(registrationStatusLabelKey(r.status))}</Badge>,
    },
    {
      key: 'payment_status',
      header: t('eventMgmt.payment'),
      render: (r) => (
        <div>
          <Badge variant={paymentStatusVariant[r.payment_status]}>{t(paymentStatusLabelKey(r.payment_status))}</Badge>
          <p className="mt-0.5 text-xs text-secondary">{r.amount_paid}</p>
        </div>
      ),
    },
    {
      key: 'attendance',
      header: t('events.attendance'),
      render: (r) => <Badge variant={attendanceStatusVariant[r.attendance_status]}>{t(attendanceStatusLabelKey(r.attendance_status))}</Badge>,
    },
    ...(isTrip
      ? [{
          key: 'bus',
          header: t('eventMgmt.bus'),
          render: (r: EventRegistration) => r.bus?.bus_number ?? '-',
        } satisfies Column<EventRegistration>]
      : []),
    { key: 'accommodation', header: t('eventMgmt.accommodation'), render: (r) => r.accommodation ? (
      <Badge variant="success">Room {r.accommodation.cell.room.room_number} / Cell {r.accommodation.cell.cell_number}</Badge>
    ) : <span className="text-xs text-secondary">-</span> },
    { key: 'registered_at', header: t('eventMgmt.registeredAt'), render: (r) => new Date(r.registered_at).toLocaleDateString() },
  ]

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && fetch(1)}
          placeholder={t('common.search')}
          className="input-field w-full sm:w-56"
        />
        <div className="flex flex-col gap-2 sm:flex-row">
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="input-field w-full sm:w-36">
            <option value="">{t('eventMgmt.allStatuses')}</option>
            <option value="pending">{t('eventMgmt.reg_pending')}</option>
            <option value="confirmed">{t('eventMgmt.reg_confirmed')}</option>
            <option value="approved">{t('eventMgmt.reg_approved')}</option>
            <option value="rejected">{t('eventMgmt.reg_rejected')}</option>
            <option value="waitlisted">{t('eventMgmt.reg_waitlisted')}</option>
            <option value="cancelled">{t('eventMgmt.reg_cancelled')}</option>
            <option value="booked">{t('eventMgmt.reg_booked')}</option>
            <option value="not_reserved">{t('eventMgmt.reg_not_reserved')}</option>
            <option value="thinking">{t('eventMgmt.reg_thinking')}</option>
          </select>
          <button onClick={() => setShowAdd(true)} className="btn-primary btn-md whitespace-nowrap">
            <Plus className="h-4 w-4" /> {t('eventMgmt.addParticipant')}
          </button>
        </div>
      </div>

      {/* Desktop table */}
      <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-tertiary text-start">
              {regColumns.map((c) => (
                <th key={c.key} className="px-3 py-2.5 text-start font-medium text-secondary">{c.header}</th>
              ))}
              <th className="px-3 py-2.5 text-end font-medium text-secondary">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-border last:border-0">
                {regColumns.map((c) => (
                  <td key={c.key} className="px-3 py-2.5">{c.render ? c.render(r) : null}</td>
                ))}
                <td className="px-3 py-2.5">
                  <div className="flex flex-wrap justify-end gap-1">
                    {r.status === 'pending' ? (
                      <>
                        <button onClick={() => runAction(() => approveReservation(eventId, r.id), t('eventMgmt.approved'))} className="btn-icon btn-ghost text-green-500" title={t('eventMgmt.approve')}>✓</button>
                        <button onClick={() => runAction(() => rejectReservation(eventId, r.id), t('eventMgmt.rejected'))} className="btn-icon btn-ghost text-red-500" title={t('eventMgmt.reject')}>✕</button>
                      </>
                    ) : null}
                    {r.status === 'pending' || r.status === 'waitlisted' ? (
                      <button onClick={() => runAction(() => confirmRegistration(eventId, r.id), t('eventMgmt.confirmed'))} className="btn-icon btn-ghost" title={t('eventMgmt.confirm')}>✓</button>
                    ) : null}
                    {r.attendance_status === 'checked_in' ? (
                      <button onClick={() => runAction(() => undoCheckIn(eventId, r.id))} className="btn-icon btn-ghost" title={t('eventMgmt.undoCheckIn')}>↩</button>
                    ) : r.attendance_status !== 'absent' && r.status !== 'cancelled' ? (
                      <button onClick={() => runAction(() => checkIn(eventId, r.id), t('eventMgmt.checkedInToast'))} className="btn-icon btn-ghost" title={t('eventMgmt.checkIn')}>⬇</button>
                    ) : null}
                    {r.status === 'pending' || r.status === 'confirmed' ? (
                      <>
                        <button onClick={() => runAction(() => waitlistRegistration(eventId, r.id))} className="btn-icon btn-ghost" title={t('eventMgmt.toWaitlist')}>⏳</button>
                        <button onClick={() => runAction(() => cancelRegistration(eventId, r.id), t('eventMgmt.cancelledToast'))} className="btn-icon btn-ghost text-red-500" title={t('common.cancel')}>✕</button>
                      </>
                    ) : null}
                    {r.status !== 'cancelled' ? (
                      <button onClick={() => setConfirmRemoveId(r.id)} className="btn-icon btn-ghost text-red-500" title={t('common.delete')}>🗑</button>
                    ) : null}
                    {isTrip && buses.length > 0 && r.status !== 'cancelled' ? (
                      <select
                        value={r.bus_id ?? ''}
                        onChange={(e) => runAction(() => assignBus(eventId, r.id, e.target.value ? Number(e.target.value) : null))}
                        className="input-field !w-auto py-1 text-xs"
                      >
                        <option value="">-</option>
                        {buses.map((b) => (
                          <option key={b.id} value={b.id}>{b.bus_number}</option>
                        ))}
                      </select>
                    ) : null}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* __PART5__ */}
      {!loading && rows.length === 0 ? (
        <p className="py-6 text-center text-sm text-secondary">{t('eventMgmt.noParticipants')}</p>
      ) : null}

      {meta.last_page > 1 ? (
        <div className="flex items-center justify-between">
          <button disabled={meta.current_page <= 1} onClick={() => fetch(meta.current_page - 1)} className="btn-secondary btn-sm">{t('common.prev')}</button>
          <span className="text-xs text-secondary">{meta.current_page} / {meta.last_page}</span>
          <button disabled={meta.current_page >= meta.last_page} onClick={() => fetch(meta.current_page + 1)} className="btn-secondary btn-sm">{t('common.next')}</button>
        </div>
      ) : null}

      {/* Add participant modal */}
      <Modal
        isOpen={showAdd}
        onClose={() => setShowAdd(false)}
        title={t('eventMgmt.addParticipant')}
        size="md"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setShowAdd(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleAdd} disabled={saving || !selectedMember} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : t('common.save')}
            </button>
          </div>
        }
      >
        <div className="space-y-3">
          <div className="flex gap-2">
            <input value={memberQuery} onChange={(e) => setMemberQuery(e.target.value)} placeholder={t('eventMgmt.searchMember')} className="input-field flex-1" />
            <button onClick={searchMembers} className="btn-secondary btn-md">{t('common.search')}</button>
          </div>
          {members.length > 0 ? (
            <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-border p-2">
              {members.map((m) => (
                <button
                  key={m.id}
                  onClick={() => setSelectedMember(m)}
                  className={`block w-full rounded-lg px-2 py-1.5 text-start text-sm ${selectedMember?.id === m.id ? 'bg-primary/10 ring-1 ring-primary' : 'hover:bg-surface-tertiary'}`}
                >
                  <span className="font-medium">{m.name}</span>
                  <span className="ms-2 text-xs text-secondary">{m.phone ?? ''}</span>
                </button>
              ))}
            </div>
          ) : null}
          <textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t('eventMgmt.notesOptional')} className="input-field" rows={2} />
          <p className="text-xs text-secondary">{selectedMember ? `${t('eventMgmt.selected')}: ${selectedMember.name}` : t('eventMgmt.selectMemberFirst')}</p>
        </div>
      </Modal>
      {/* Remove confirmation */}
      <Modal
        isOpen={confirmRemoveId !== null}
        onClose={() => setConfirmRemoveId(null)}
        title={t('eventMgmt.removeParticipant')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setConfirmRemoveId(null)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button
              onClick={() => {
                const id = confirmRemoveId
                setConfirmRemoveId(null)
                if (id !== null) void runAction(() => removeRegistration(eventId, id), t('common.delete'))
              }}
              className="flex-1 btn-danger btn-md"
            >
              {t('common.delete')}
            </button>
          </div>
        }
      >
        <p className="text-sm">{t('eventMgmt.removeParticipantConfirm')}</p>
      </Modal>
    </div>
  )
}
