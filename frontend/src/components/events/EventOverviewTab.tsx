import { useTranslation } from 'react-i18next'
import Badge from '@/components/common/Badge'
import type { EventDashboardStats } from '@/types'

interface Props {
  stats: EventDashboardStats | null
  loading: boolean
}

export default function EventOverviewTab({ stats, loading }: Props) {
  const { t } = useTranslation()

  if (loading || !stats) {
    return <div className="py-8 text-center text-sm text-secondary">{t('common.loading')}</div>
  }

  const s = stats.statistics
  const pay = stats.payments
  const att = stats.attendance

  const capacityLabel = s.max_capacity === null ? '∞' : String(s.max_capacity)

  const cards = [
    {
      label: t('eventMgmt.capacity'),
      value: `${s.total_registered} / ${capacityLabel}`,
      hint: s.available_spaces === null ? t('eventMgmt.unlimited') : `${s.available_spaces} ${t('eventMgmt.available')}`,
    },
    {
      label: t('eventMgmt.occupancy'),
      value: `${s.occupancy_percentage}%`,
      hint: s.is_full ? t('eventMgmt.full') : '',
    },
    {
      label: t('eventMgmt.waitlisted'),
      value: String(s.waitlisted),
    },
    {
      label: t('events.attendance'),
      value: `${att.checked_in} / ${att.total_registered}`,
      hint: `${att.attendance_percentage}%`,
    },
    {
      label: t('eventMgmt.expectedRevenue'),
      value: pay.expected_revenue.toFixed(2),
    },
    {
      label: t('eventMgmt.collected'),
      value: pay.collected.toFixed(2),
    },
    {
      label: t('eventMgmt.remaining'),
      value: pay.remaining.toFixed(2),
    },
    {
      label: t('eventMgmt.paidParticipants'),
      value: `${pay.paid_participants} / ${pay.unpaid_participants} ${t('eventMgmt.unpaid')}`,
    },
  ]

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {cards.map((c) => (
          <div key={c.label} className="rounded-xl border border-border bg-surface p-3 sm:p-4">
            <p className="text-xs text-secondary">{c.label}</p>
            <p className="mt-1 text-lg font-semibold break-all sm:text-xl">{c.value}</p>
            {c.hint ? <p className="text-xs text-secondary">{c.hint}</p> : null}
          </div>
        ))}
      </div>

      <div className="rounded-xl border border-border bg-surface p-4">
        <div className="mb-2 flex items-center justify-between gap-2">
          <p className="text-sm font-medium">{t('events.eventName')}</p>
          {stats.event.status ? (
            <Badge variant={stats.event.status === 'open' ? 'success' : stats.event.status === 'cancelled' ? 'danger' : 'info'}>
              {t(`eventMgmt.status_${stats.event.status}`)}
            </Badge>
          ) : null}
        </div>
        <dl className="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
          <div className="flex justify-between gap-2 sm:block">
            <dt className="text-secondary">{t('events.eventDate')}</dt>
            <dd>{stats.event.event_date ? new Date(stats.event.event_date).toLocaleString() : '-'}</dd>
          </div>
          <div className="flex justify-between gap-2 sm:block">
            <dt className="text-secondary">{t('events.location')}</dt>
            <dd>{stats.event.location ?? '-'}</dd>
          </div>
          <div className="flex justify-between gap-2 sm:block">
            <dt className="text-secondary">{t('events.eventType')}</dt>
            <dd>{t(`events.type_${stats.event.type ?? 'other'}`)}</dd>
          </div>
        </dl>
      </div>
    </div>
  )
}
