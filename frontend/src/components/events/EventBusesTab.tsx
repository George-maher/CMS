import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import Modal from '@/components/common/Modal'
import type { EventBusItem } from '@/types'
import { createBus, deleteBus, listBuses } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'

interface Props {
  eventId: number
}

export default function EventBusesTab({ eventId }: Props) {
  const { t } = useTranslation()

  const [buses, setBuses] = useState<EventBusItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showAdd, setShowAdd] = useState(false)
  const [busNumber, setBusNumber] = useState('')
  const [capacity, setCapacity] = useState('45')
  const [driver, setDriver] = useState('')
  const [coordinator, setCoordinator] = useState('')
  const [saving, setSaving] = useState(false)
  const [deleteId, setDeleteId] = useState<number | null>(null)

  const fetch = useCallback(async () => {
    setLoading(true)
    try {
      setBuses(await listBuses(eventId))
    } catch (e) {
      logCatch('EventBusesTab.listBuses', e)
    } finally {
      setLoading(false)
    }
  }, [eventId])

  useEffect(() => {
    void Promise.resolve().then(fetch)
  }, [fetch])

  const handleCreate = async () => {
    const cap = Number(capacity)
    if (!busNumber.trim() || !Number.isInteger(cap) || cap < 1) {
      toast.error(t('eventMgmt.invalidBus'))
      return
    }
    setSaving(true)
    try {
      await createBus(eventId, {
        bus_number: busNumber,
        capacity: cap,
        driver_name: driver || undefined,
        coordinator_name: coordinator || undefined,
      })
      setShowAdd(false)
      setBusNumber('')
      setCapacity('45')
      setDriver('')
      setCoordinator('')
      toast.success(t('eventMgmt.busCreated'))
      fetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (deleteId === null) return
    try {
      await deleteBus(eventId, deleteId)
      toast.success(t('common.delete'))
      fetch()
    } catch (e) {
      logCatch('EventBusesTab.deleteBus', e)
      toast.error(t('eventMgmt.actionFailed'))
    } finally {
      setDeleteId(null)
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm text-secondary">{t('common.loading')}</div>
  }

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button onClick={() => setShowAdd(true)} className="btn-primary btn-md">
          + {t('eventMgmt.addBus')}
        </button>
      </div>

      {buses.length === 0 ? (
        <p className="py-6 text-center text-sm text-secondary">{t('eventMgmt.noBuses')}</p>
      ) : (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {buses.map((b) => (
            <div key={b.id} className="rounded-xl border border-border bg-surface p-4">
              <div className="flex items-start justify-between gap-2">
                <p className="font-semibold">{b.bus_number}</p>
                <button onClick={() => setDeleteId(b.id)} className="btn-icon btn-ghost text-red-500">🗑</button>
              </div>
              <p className="mt-1 text-xs text-secondary">{b.capacity} {t('eventMgmt.seats')}</p>
              <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-tertiary">
                <div
                  className={`h-full rounded-full ${b.occupancy_percentage >= 100 ? 'bg-red-500' : 'bg-primary'}`}
                  style={{ width: `${Math.min(100, b.occupancy_percentage)}%` }}
                />
              </div>
              <dl className="mt-2 space-y-0.5 text-xs text-secondary">
                <div className="flex justify-between"><dt>{t('eventMgmt.assigned')}</dt><dd>{b.assigned_count}</dd></div>
                <div className="flex justify-between"><dt>{t('eventMgmt.available')}</dt><dd>{b.available_seats}</dd></div>
                {b.driver_name ? <div className="flex justify-between truncate"><dt>{t('eventMgmt.driver')}</dt><dd className="max-w-[60%] truncate">{b.driver_name}</dd></div> : null}
                {b.coordinator_name ? <div className="flex justify-between truncate"><dt>{t('eventMgmt.coordinator')}</dt><dd className="max-w-[60%] truncate">{b.coordinator_name}</dd></div> : null}
              </dl>
            </div>
          ))}
        </div>
      )}

      <Modal
        isOpen={showAdd}
        onClose={() => setShowAdd(false)}
        title={t('eventMgmt.addBus')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setShowAdd(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleCreate} disabled={saving} className="flex-1 btn-primary btn-md">{saving ? t('common.saving') : t('common.create')}</button>
          </div>
        }
      >
        <div className="space-y-3">
          <input value={busNumber} onChange={(e) => setBusNumber(e.target.value)} placeholder={t('eventMgmt.busNumber')} className="input-field w-full" />
          <input type="number" min="1" value={capacity} onChange={(e) => setCapacity(e.target.value)} placeholder={t('eventMgmt.capacity')} className="input-field w-full" />
          <input value={driver} onChange={(e) => setDriver(e.target.value)} placeholder={t('eventMgmt.driver')} className="input-field w-full" />
          <input value={coordinator} onChange={(e) => setCoordinator(e.target.value)} placeholder={t('eventMgmt.coordinator')} className="input-field w-full" />
        </div>
      </Modal>

      <Modal
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        title={t('common.delete')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setDeleteId(null)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleDelete} className="flex-1 btn-danger btn-md">{t('common.delete')}</button>
          </div>
        }
      >
        <p className="text-sm">{t('eventMgmt.deleteBusConfirm')}</p>
      </Modal>
    </div>
  )
}
