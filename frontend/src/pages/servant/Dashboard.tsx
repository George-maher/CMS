import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CalendarDays } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { useAuth } from '@/contexts/AuthContext'
import { getAttendanceStats } from '@/api/attendance'
import { getMembers } from '@/api/users'
import { listEvents } from '@/api/events'

interface MemberCountUser { id: number; name: string }

export default function ServantDashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [members, setMembers] = useState<MemberCountUser[]>([])
  const [upcomingEvents, setUpcomingEvents] = useState<{ id: number; name: string; event_date: string }[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getAttendanceStats()
      .then(setStats).catch(() => {})
    getMembers()
      .then(setMembers).catch(() => setMembers([]))
    listEvents({ upcoming: true, per_page: 3 })
      .then((ev) => setUpcomingEvents(ev.data)).catch(() => setUpcomingEvents([]))
      .finally(() => setLoading(false))
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
                  {event.event_date ? new Date(event.event_date).toLocaleDateString() : t('common.dateTbd')}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
