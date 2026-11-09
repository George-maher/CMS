import { fmtDate } from '@/lib/dates'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CalendarDays, BookOpen } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { useAuth } from '@/hooks/useAuth'
import { getAttendanceStats } from '@/api/attendance'
import { getMembers } from '@/api/users'
import { listEvents } from '@/api/events'
import { getMyClasses } from '@/api/structure'
import { logCatch } from '@/lib/debug'

interface MemberCountUser { id: number; name: string }

export default function ServantDashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [members, setMembers] = useState<MemberCountUser[]>([])
  const [myClasses, setMyClasses] = useState<{ id: number; name: string }[]>([])
  const [upcomingEvents, setUpcomingEvents] = useState<{ id: number; name: string; event_date: string }[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([
      getAttendanceStats().catch((e) => { logCatch('ServantDashboard.getStats', e); return { total_attendances: 0, this_month: 0 } }),
      getMembers().catch((e) => { logCatch('ServantDashboard.getMembers', e); return [] }),
      listEvents({ upcoming: true, per_page: 3 }).catch((e) => { logCatch('ServantDashboard.listEvents', e); return { data: [] } }),
      getMyClasses().catch((e) => { logCatch('ServantDashboard.getMyClasses', e); return [] }),
    ]).then(([s, m, ev, classes]) => {
      setStats(s)
      setMembers(m)
      setUpcomingEvents(ev.data)
      setMyClasses(classes)
    }).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-4">
      <DailyVerseBanner />

      <div className="glass-card-solid p-5">
        <h2 className="text-lg font-semibold">{t('dashboard.welcome')}, {user?.name}</h2>
        <p className="mt-1 text-sm text-secondary">
          {user?.classe ? `${user.classe.name} · ` : ''}{t('dashboard.servantDashboard')}
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard title={t('dashboard.myAttendances')} value={stats.total_attendances} subtitle={t('common.total')} icon={<CalendarDays className="h-5 w-5" />} color="gold" />
        <StatCard title={t('common.thisMonth')} value={stats.this_month} subtitle={t('attendance.attendance')} color="navy" />
        <StatCard title={t('dashboard.myMembers')} value={members.length} subtitle={t('attendance.todayAttendance')} color="primary" />
      </div>

      {myClasses.length > 0 && (
        <div className="glass-card-solid p-5">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold">
            <BookOpen className="h-5 w-5 gold-text" />
            {t('dashboard.myClasses')}
          </h2>
          <div className="space-y-2">
            {myClasses.map((classe) => (
              <button
                key={classe.id}
                onClick={() => navigate(`/servant/classes/${classe.id}`)}
                className="flex w-full items-center justify-between rounded-lg border border-border px-4 py-3 text-start transition-colors hover:bg-surface-secondary"
              >
                <span className="font-medium">{classe.name}</span>
                <span className="text-xs text-secondary">{t('structure.classDetail')}</span>
              </button>
            ))}
          </div>
        </div>
      )}

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
