import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import type { EventFinancialSummary, EventPayment, EventRegistration, User } from '@/types'
import { listPayments, listRegistrations, recordPayment, refundPayment } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'

interface Props {
  eventId: number
  currentUser: User | null
}

export default function EventPaymentsTab({ eventId, currentUser }: Props) {
  const { t } = useTranslation()

  const [payments, setPayments] = useState<EventPayment[]>([])
  const [registrations, setRegistrations] = useState<EventRegistration[]>([])
  const [summary, setSummary] = useState<EventFinancialSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [payFor, setPayFor] = useState<{ regId: number; name: string; remaining: number } | null>(null)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState('cash')
  const [saving, setSaving] = useState(false)

  const fetch = useCallback(async () => {
    setLoading(true)
    try {
      const [paymentsRes, regsRes] = await Promise.all([
        listPayments(eventId),
        listRegistrations(eventId, { per_page: 500 }),
      ])
      setPayments(paymentsRes.data)
      setSummary(paymentsRes.summary)
      setRegistrations(regsRes.data)
    } catch (e) {
      logCatch('EventPaymentsTab.listPayments', e)
    } finally {
      setLoading(false)
    }
  }, [eventId])

  useEffect(() => {
    void Promise.resolve().then(fetch)
  }, [fetch])

  const handleRecord = async () => {
    if (!payFor) return
    const value = Number(amount)
    if (!Number.isFinite(value) || value <= 0) {
      toast.error(t('eventMgmt.invalidAmount'))
      return
    }
    if (value > payFor.remaining + 0.001) {
      toast.error(t('eventMgmt.exceedsRemaining'))
      return
    }
    setSaving(true)
    try {
      await recordPayment(eventId, payFor.regId, { amount: value, method })
      setPayFor(null)
      setAmount('')
      toast.success(t('eventMgmt.paymentRecorded'))
      fetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleRefund = async (regId: number, paymentId: number) => {
    if (!window.confirm(t('eventMgmt.refundConfirm'))) return
    try {
      await refundPayment(eventId, regId, paymentId)
      toast.success(t('eventMgmt.refundedToast'))
      fetch()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm text-secondary">{t('common.loading')}</div>
  }

  if (!currentUser) {
    return null
  }

  const price = summary?.price_per_participant ?? 0
  const outstanding = registrations
    .filter((r) => r.status !== 'cancelled' && price > 0 && Number(r.amount_paid) < price)
    .map((r) => ({ reg: r, remaining: price - Number(r.amount_paid) }))

  return (
    <div className="space-y-4">
      {summary ? (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {[
            { label: t('eventMgmt.expectedRevenue'), value: summary.expected_revenue },
            { label: t('eventMgmt.collected'), value: summary.collected },
            { label: t('eventMgmt.refundedLabel'), value: summary.refunded },
            { label: t('eventMgmt.remaining'), value: summary.remaining },
          ].map((c) => (
            <div key={c.label} className="rounded-xl border border-border bg-surface p-3">
              <p className="text-xs text-secondary">{c.label}</p>
              <p className="mt-1 text-lg font-semibold">{c.value.toFixed(2)}</p>
            </div>
          ))}
        </div>
      ) : null}

      {outstanding.length > 0 && price > 0 ? (
        <div>
          <p className="mb-2 text-sm font-medium">{t('eventMgmt.outstandingBalances')}</p>
          <div className="space-y-1">
            {outstanding.map(({ reg, remaining }) => (
              <div key={reg.id} className="flex items-center justify-between gap-2 rounded-lg border border-border bg-surface px-3 py-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{reg.user?.name}</p>
                  <p className="text-xs text-secondary">{t('eventMgmt.remainingBalance')}: {remaining.toFixed(2)}</p>
                </div>
                <button
                  onClick={() => {
                    setPayFor({ regId: reg.id, name: reg.user?.name ?? '', remaining })
                    setAmount(remaining.toFixed(2))
                  }}
                  className="btn-secondary btn-sm whitespace-nowrap"
                >
                  {t('eventMgmt.recordPayment')}
                </button>
              </div>
            ))}
          </div>
        </div>
      ) : null}

      {payments.length === 0 ? (
        <p className="py-6 text-center text-sm text-secondary">{t('eventMgmt.noPayments')}</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-surface-tertiary">
                <th className="px-3 py-2.5 text-start font-medium text-secondary">{t('events.eventName')}</th>
                <th className="px-3 py-2.5 text-start font-medium text-secondary">{t('eventMgmt.amount')}</th>
                <th className="px-3 py-2.5 text-start font-medium text-secondary">{t('eventMgmt.method')}</th>
                <th className="px-3 py-2.5 text-start font-medium text-secondary">{t('eventMgmt.date')}</th>
                <th className="px-3 py-2.5 text-end font-medium text-secondary" />
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id} className="border-b border-border last:border-0">
                  <td className="px-3 py-2.5 font-medium">{p.member?.name ?? `#${p.registration_id}`}</td>
                  <td className="px-3 py-2.5">
                    {p.amount}
                    {p.refunded ? (
                      <Badge variant="info" className="ms-2">{t('eventMgmt.pay_refunded')}</Badge>
                    ) : null}
                  </td>
                  <td className="px-3 py-2.5">{t(`eventMgmt.method_${p.method}`)}</td>
                  <td className="px-3 py-2.5">{new Date(p.paid_at).toLocaleDateString()}</td>
                  <td className="px-3 py-2.5 text-end">
                    {!p.refunded ? (
                      <button
                        onClick={() => handleRefund(p.registration_id, p.id)}
                        className="btn-icon btn-ghost text-red-500"
                      >
                        {t('eventMgmt.refund')}
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal
        isOpen={payFor !== null}
        onClose={() => setPayFor(null)}
        title={t('eventMgmt.recordPayment')}
        size="sm"
        footer={
          <div className="flex w-full gap-3">
            <button onClick={() => setPayFor(null)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleRecord} disabled={saving} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : t('common.save')}
            </button>
          </div>
        }
      >
        <div className="space-y-3">
          <p className="text-sm">{payFor?.name}</p>
          <p className="text-xs text-secondary">
            {t('eventMgmt.remainingBalance')}: {payFor?.remaining.toFixed(2)}
          </p>
          <input type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={t('eventMgmt.amount')} className="input-field w-full" />
          <select value={method} onChange={(e) => setMethod(e.target.value)} className="input-field w-full">
            <option value="cash">{t('eventMgmt.method_cash')}</option>
            <option value="bank_transfer">{t('eventMgmt.method_bank_transfer')}</option>
            <option value="other">{t('eventMgmt.method_other')}</option>
          </select>
        </div>
      </Modal>
    </div>
  )
}
