import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, CheckCircle, XCircle, Building2, Users } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import DataTable from '@/components/common/DataTable'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import type { Column } from '@/components/common/DataTable'
import type { ChurchApplication, PlatformDashboardStats } from '@/types'
import { getPlatformDashboard, listApplications } from '@/api/churchApplications'

const statusBadge: Record<string, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

export default function PlatformDashboard() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [stats, setStats] = useState<PlatformDashboardStats | null>(null)
  const [apps, setApps] = useState<ChurchApplication[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('')

  const fetchData = async (page = 1) => {
    setLoading(true)
    try {
      const [s, a] = await Promise.all([
        getPlatformDashboard(),
        listApplications(filter || undefined, page, 15),
      ])
      setStats(s)
      setApps(a.data)
      setMeta(a.meta)
    } catch { /* ignore */ }
    finally { setLoading(false) }
  }

  useEffect(() => { fetchData() }, [filter])

  const columns: Column<ChurchApplication>[] = [
    { key: 'church_name', header: t('platform.churchName'), render: (a) => <span className="font-medium">{a.church_name}</span> },
    { key: 'priest_name', header: t('platform.priestName') },
    { key: 'phone', header: t('platform.phone'), render: (a) => <span>{a.phone || a.priest_phone}</span> },
    { key: 'status', header: t('platform.status'), render: (a) => <Badge variant={statusBadge[a.status]}>{a.status}</Badge> },
    { key: 'created_at', header: t('platform.date'), render: (a) => new Date(a.created_at).toLocaleDateString() },
  ]

  if (loading && !stats) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">{t('platform.title')}</h1>

      {stats && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard title={t('platform.pending')} value={stats.pending_applications} icon={<ClipboardList className="h-5 w-5" />} />
          <StatCard title={t('platform.approved')} value={stats.approved_applications} icon={<CheckCircle className="h-5 w-5" />} />
          <StatCard title={t('platform.rejected')} value={stats.rejected_applications} icon={<XCircle className="h-5 w-5" />} />
          <StatCard title={t('platform.totalChurches')} value={stats.total_churches} icon={<Building2 className="h-5 w-5" />} />
          <StatCard title={t('platform.totalUsers')} value={stats.total_users} icon={<Users className="h-5 w-5" />} />
        </div>
      )}

      <div className="card">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-border px-5 py-4">
          <h2 className="font-semibold">{t('platform.applications')}</h2>
          <select value={filter} onChange={(e) => setFilter(e.target.value)} className="input-field w-full sm:w-40">
            <option value="">{t('platform.allStatuses')}</option>
            <option value="pending">{t('platform.pendingOnly')}</option>
            <option value="approved">{t('platform.approvedOnly')}</option>
            <option value="rejected">{t('platform.rejectedOnly')}</option>
          </select>
        </div>
        <DataTable
          columns={[...columns, { key: 'actions', header: '', render: (a) => (
            <button onClick={() => navigate(`/platform/applications/${a.id}`)} className="btn-ghost btn-sm">{t('platform.viewDetails')}</button>
          )}]}
          data={apps} meta={meta} isLoading={loading} onPageChange={fetchData}
          emptyMessage={t('platform.noApplications')}
          onRowClick={(a) => navigate(`/platform/applications/${a.id}`)}
        />
      </div>
    </div>
  )
}
