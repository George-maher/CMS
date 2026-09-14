import { fmtDate } from '@/lib/dates'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CalendarDays, Layers } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { useAuth } from '@/hooks/useAuth'
import { getAttendanceStats } from '@/api/attendance'
import { listStages } from '@/api/stages'
import { getStageClasses } from '@/api/stages'
import { listEvents } from '@/api/events'
import { logCatch } from '@/lib/debug'

interface UpcomingEvent { id: number; name: string; event_date: string }

export default function StageAdminDashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [classesCount, setClassesCount] = useState<number>(0)
  const [stageName, setStageName] = useState<string>('')
  const [upcomingEvents, setUpcomingEvents] = useState<UpcomingEvent[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const scope = user?.stage_id ? user.stage_id : undefined

    Promise.all([
      getAttendanceStats().catch((e) => { logCatch('StageDashboard.getStats', e); return { total_attendances: 0, this_month: 0 } }),
      listStages().catch((e) => { logCatch('StageDashboard.listStages', e); return [] }),
      scope ? getStageClasses(scope).catch((e) => { logCatch('StageDashboard.getStageClasses', e); return [] }) : Promise.resolve([]),
      listEvents({ upcoming: true, per_page: 3 }).catch((e) => { logCatch('StageDashboard.listEvents', e); return { data: [] } }),
    ]).then(([s, stages, classes, ev]) => {
      setStats(s)
      const ownStage = stages.find((st) => st.id === user?.stage_id)
      setStageName(ownStage?.name ?? '')
      setClassesCount(Array.isArray(classes) ? classes.length : 0)
      setUpcomingEvents(ev.data)
    }).finally(() => setLoading(false))
  }, [user?.stage_id])

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-4">
      <DailyVerseBanner />

      <div className="glass-card-solid p-5">
        <h2 className="text-lg font-semibold">{t('dashboard.welcome')}, {user?.name}</h2>
        <p className="mt-1 text-sm text-secondary">
          {stageName ? `${stageName} · ` : ''}{t('dashboard.stageAdminDashboard')}
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard title={t('dashboard.totalAttendances')} value={stats.total_attendances} subtitle={t('common.total')} icon={<CalendarDays className="h-5 w-5" />} color="gold" />
        <StatCard title={t('common.thisMonth')} value={stats.this_month} subtitle={t('attendance.attendance')} color="navy" />
        <StatCard title={t('dashboard.stageClasses')} value={classesCount} subtitle={stageName || t('structure.selectStage')} icon={<Layers className="h-5 w-5" />} color="primary" />
      </div>

      {upcomingEvents.length > 0 && (
        <div className="glass-card-solid p-5">
          <div className="flex items-center gap-2 mb-4">
            <CalendarDays className="h-5 w-5 gold-text" />
            <h2 className="text-lg font-semibold">{t('dashboard.upcomingEvents')}</h2>
          </div>
          <div className="space-y-3">
            {upcomingEvents.map((event) => (
              <div key={event.id} className="flex items-center justify-between border-b border-border pb-2 last:border-0">
                <span className="font-medium text-sm">{event.name}</span>
                <span className="text-xs text-secondary">
                  {event.event_date ? fmtDate(new Date(event.event_date)) : t('common.dateTbd')}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}