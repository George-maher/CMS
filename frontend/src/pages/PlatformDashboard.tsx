import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, CheckCircle, XCircle, Building2, Users, Activity, Ban, SearchX } from 'lucide-react'
import type { TFunction } from 'i18next'
import StatCard from '@/components/common/StatCard'
import DataTable from '@/components/common/DataTable'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import type { Column } from '@/components/common/DataTable'
import type { ChurchApplication, PlatformDashboardStats, ApplicationCounts } from '@/types'
import { getPlatformDashboard, listApplications } from '@/api/churchApplications'
import { logCatch } from '@/lib/debug'

const statusBadge: Record<string, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const tabVariants: Record<string, 'warning' | 'success' | 'danger' | 'default'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

function translateStatus(status: string, t: TFunction): string {
  const map: Record<string, string> = {
    pending: t('platform.pendingOnly'),
    approved: t('platform.approvedOnly'),
    rejected: t('platform.rejectedOnly'),
  }
  return map[status] || status
}

const emptyMessages: Record<string, string> = {
  pending: 'platform.noPendingApplications',
  approved: 'platform.noApprovedApplications',
  rejected: 'platform.noRejectedApplications',
}

export default function PlatformDashboard() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [stats, setStats] = useState<PlatformDashboardStats | null>(null)
  const [apps, setApps] = useState<ChurchApplication[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [counts, setCounts] = useState<ApplicationCounts>({ pending: 0, approved: 0, rejected: 0, total: 0 })
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('')

  const fetchData = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const [s, a] = await Promise.all([
        getPlatformDashboard(),
        listApplications(filter || undefined, page, 15),
      ])
      setStats(s)
      setApps(a.data)
      setMeta(a.meta)
      setCounts(a.counts)
    } catch (e) { logCatch('PlatformDashboard.fetchData', e) }
    finally { setLoading(false) }
  }, [filter])

  useEffect(() => {
    getPlatformDashboard().then(s => setStats(s))
    listApplications(filter || undefined, 1, 15).then(a => { setApps(a.data); setMeta(a.meta); setCounts(a.counts) }).finally(() => setLoading(false))
  }, [filter])

  const tabs = [
    { key: '', label: t('common.all'), count: counts.total, variant: 'default' as const },
    { key: 'pending', label: t('platform.pendingOnly'), count: counts.pending, variant: tabVariants.pending },
    { key: 'approved', label: t('platform.approvedOnly'), count: counts.approved, variant: tabVariants.approved },
    { key: 'rejected', label: t('platform.rejectedOnly'), count: counts.rejected, variant: tabVariants.rejected },
  ]

  const columns: Column<ChurchApplication>[] = [
    { key: 'church_name', header: t('platform.churchName'), render: (a) => <span className="font-medium">{a.church_name}</span> },
    { key: 'priest_name', header: t('platform.priestName') },
    { key: 'phone', header: t('platform.phone'), render: (a) => <span>{a.phone || a.priest_phone}</span> },
    { key: 'status', header: t('platform.status'), render: (a) => <Badge variant={statusBadge[a.status]}>{translateStatus(a.status, t)}</Badge> },
    { key: 'created_at', header: t('platform.date'), render: (a) => new Date(a.created_at).toLocaleDateString() },
  ]

  if (loading && !stats) return <LoadingSpinner className="py-20" />

  const currentEmptyMessage = filter ? emptyMessages[filter] || 'platform.noApplications' : 'platform.noApplications'

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">{t('platform.title')}</h1>

      {stats && (
        <div className="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 stagger-children">
          <StatCard title={t('platform.pending')} value={stats.pending_applications} icon={<ClipboardList className="h-5 w-5" />} color="warning" />
          <StatCard title={t('platform.approved')} value={stats.approved_applications} icon={<CheckCircle className="h-5 w-5" />} color="success" />
          <StatCard title={t('platform.rejected')} value={stats.rejected_applications} icon={<XCircle className="h-5 w-5" />} color="danger" />
          <StatCard title={t('platform.activeChurches')} value={stats.active_churches} icon={<Activity className="h-5 w-5" />} color="primary" />
          <StatCard title={t('platform.suspendedChurches')} value={stats.suspended_churches} icon={<Ban className="h-5 w-5" />} color="danger" />
          <StatCard title={t('platform.totalChurches')} value={stats.total_churches} icon={<Building2 className="h-5 w-5" />} color="primary" />
          <StatCard title={t('platform.totalUsers')} value={stats.total_users} icon={<Users className="h-5 w-5" />} color="primary" />
        </div>
      )}

      <div className="card">
        <div className="border-b border-border px-5 py-4">
          <h2 className="mb-3 font-semibold">{t('platform.applications')}</h2>
          <div className="flex flex-wrap gap-1.5">
            {tabs.map((tab) => (
              <button
                key={tab.key}
                onClick={() => setFilter(tab.key)}
                className={`relative inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors ${
                  filter === tab.key
                    ? 'bg-gold-500 text-white shadow-sm'
                    : 'bg-surface-secondary text-secondary hover:bg-surface-tertiary'
                }`}
              >
                {tab.label}
                <Badge
                  variant={filter === tab.key ? 'default' : tab.variant}
                  className={filter === tab.key ? 'bg-white/20 text-white' : ''}
                >
                  {tab.count}
                </Badge>
              </button>
            ))}
          </div>
        </div>

        {!loading && apps.length === 0 ? (
          <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
            <SearchX className="mb-3 h-12 w-12 text-muted" />
            <p className="text-base font-medium text-secondary">{t(currentEmptyMessage)}</p>
            <p className="mt-1 text-sm text-muted">{t('platform.emptyFilterHint')}</p>
          </div>
        ) : (
          <DataTable
            columns={[...columns, { key: 'actions', header: '', render: (a) => (
              <button onClick={(e) => { e.stopPropagation(); navigate(`/platform/applications/${a.id}`) }} className="btn-ghost btn-sm">{t('platform.viewDetails')}</button>
            )}]}
            data={apps} meta={meta} isLoading={loading} onPageChange={fetchData}
            emptyMessage={t(currentEmptyMessage)}
            onRowClick={(a) => navigate(`/platform/applications/${a.id}`)}
          />
        )}
      </div>
    </div>
  )
}
