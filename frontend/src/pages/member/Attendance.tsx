import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import DataTable from '@/components/common/DataTable'
import StatCard from '@/components/common/StatCard'
import { useTheme } from '@/hooks/useTheme'
import type { Column } from '@/components/common/DataTable'
import type { Attendance } from '@/types'
import { getAttendanceStats, getAttendanceHistory } from '@/api/attendance'

function statusLabel(status: string | null | undefined, t: (key: string) => string): string {
  if (!status) return '-'
  switch (status.toLowerCase()) {
    case 'present':
      return t('attendance.present')
    case 'absent':
      return t('attendance.absent')
    default:
      return status
  }
}

export default function MemberAttendance() {
  const { t } = useTranslation()
  const { language } = useTheme()

  const columns: Column<Attendance>[] = [
    { key: 'attended_at', header: t('attendance.date'), render: (a) => a.attended_at ? new Date(a.attended_at).toLocaleDateString() : '-' },
    { key: 'context', header: t('context.context'), render: (a) => a.attendance_context ? ctxName(a.attendance_context, language) : '-' },
    { key: 'event', header: t('attendance.event'), render: (a) => a.event?.name ?? '-' },
    { key: 'status', header: t('attendance.status'), render: (a) => statusLabel(a.status, t) },
    { key: 'points_earned', header: t('attendance.pointsEarnedCol') },
    { key: 'recorder', header: t('attendance.recordedBy'), render: (a) => a.recorder?.name ?? '-' },
  ]
  const [attendances, setAttendances] = useState<Attendance[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [loading, setLoading] = useState(true)

  const fetchData = async (page = 1) => {
    setLoading(true)
    try {
      const [s, res] = await Promise.all([getAttendanceStats(), getAttendanceHistory(undefined, { page, per_page: 15 })])
      setStats(s); setAttendances(res.data); setMeta(res.meta)
    } finally { setLoading(false) }
  }

  useEffect(() => {
    getAttendanceStats().then(s => setStats(s))
    getAttendanceHistory(undefined, { page: 1, per_page: 15 }).then(res => { setAttendances(res.data); setMeta(res.meta) }).finally(() => setLoading(false))
  }, [])

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <StatCard title={t('dashboard.myAttendances')} value={stats.total_attendances} color="info" />
        <StatCard title={t('common.thisMonth')} value={stats.this_month} color="gold" />
      </div>
      <DataTable columns={columns} data={attendances} meta={meta} isLoading={loading} onPageChange={fetchData} />
    </div>
  )
}