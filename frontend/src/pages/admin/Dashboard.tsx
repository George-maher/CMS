import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trophy, Users, Cross, Sparkles } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import MotionDiv from '@/components/common/MotionDiv'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { getDashboardStats } from '@/api/dashboard'
import { getLeaderboard } from '@/api/points'
import { getTodayAttendance } from '@/api/attendance'

export default function AdminDashboard() {
  const { t } = useTranslation()
  const [memberStats, setMemberStats] = useState({ total_members: 0, active_members: 0, total_attendances: 0, total_points: 0 })
  const [servantSummary, setServantSummary] = useState({ total_servants: 0, total_members_managed: 0 })
  const [todayCount, setTodayCount] = useState(0)
  const [leaderboard, setLeaderboard] = useState<{ rank: number; name: string; total_points: number }[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getDashboardStats()
      .then((stats) => {
        setMemberStats({
          total_members: stats.total_members,
          active_members: stats.active_members,
          total_attendances: stats.total_attendances,
          total_points: stats.total_points,
        })
        setServantSummary({
          total_servants: stats.total_servants,
          total_members_managed: stats.total_members_managed,
        })
      }).catch(() => {})
    getTodayAttendance()
      .then((td) => setTodayCount(td.count)).catch(() => {})
    getLeaderboard(5)
      .then((lb) => setLeaderboard(lb.data)).catch(() => setLeaderboard([]))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-6">
      <MotionDiv animation="fade-in-up">
        <DailyVerseBanner />
      </MotionDiv>

      <MotionDiv animation="fade-in-up" delay={50}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 stagger-children">
          <StatCard title={t('dashboard.totalMembers')} value={memberStats.total_members} subtitle={t('dashboard.registered')} icon={<Users className="h-5 w-5" />} color="gold" delay={0} />
          <StatCard title={t('dashboard.activeMembers')} value={memberStats.active_members} subtitle={t('dashboard.currentlyActive')} icon={<Cross className="h-5 w-5" />} color="navy" delay={50} />
          <StatCard title={t('dashboard.totalServants')} value={servantSummary.total_servants} subtitle={`${servantSummary.total_members_managed} ${t('dashboard.myMembers')}`} icon={<Users className="h-5 w-5" />} color="primary" delay={100} />
          <StatCard title={t('dashboard.todaysAttendance')} value={todayCount} subtitle={t('dashboard.checkedIn')} icon={<Sparkles className="h-5 w-5" />} color="gold" delay={150} />
        </div>
      </MotionDiv>

      <MotionDiv animation="fade-in-up" delay={100}>
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="glass-card-solid p-6">
            <h2 className="section-title mb-4 flex items-center gap-2">
              <Cross className="h-5 w-5 gold-text" />
              {t('dashboard.systemOverview')}
            </h2>
            <div className="space-y-4">
              <div className="flex justify-between border-b border-border pb-2.5">
                <span className="text-sm text-secondary">{t('dashboard.totalAttendances')}</span>
                <span className="font-semibold text-lg">{memberStats.total_attendances}</span>
              </div>
              <div className="flex justify-between border-b border-border pb-2.5">
                <span className="text-sm text-secondary">{t('dashboard.totalPointsAwarded')}</span>
                <span className="font-semibold text-lg">{memberStats.total_points}</span>
              </div>
              <div className="flex justify-between border-b border-border pb-2.5">
                <span className="text-sm text-secondary">{t('dashboard.avgMembersPerServant')}</span>
                <span className="font-semibold text-lg">
                  {servantSummary.total_servants > 0 ? (servantSummary.total_members_managed / servantSummary.total_servants).toFixed(1) : '0'}
                </span>
              </div>
              <div className="flex justify-between pb-2.5">
                <span className="text-sm text-secondary">{t('dashboard.activeMemberRate')}</span>
                <span className="font-semibold text-lg">
                  {memberStats.total_members > 0 ? ((memberStats.active_members / memberStats.total_members) * 100).toFixed(0) + '%' : '0%'}
                </span>
              </div>
            </div>
          </div>

          <div className="glass-card-solid p-6">
            <div className="flex items-center gap-2 mb-4">
              <Trophy className="h-5 w-5 gold-text" />
              <h2 className="section-title">{t('dashboard.pointsLeaderboard')}</h2>
            </div>
            {leaderboard.length === 0 ? (
              <p className="text-sm text-muted">{t('common.noDataYet')}</p>
            ) : (
              <div className="space-y-3">
                {leaderboard.map((entry) => (
                  <div key={entry.rank} className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <span className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ${
                        entry.rank === 1 ? 'rank-1-circle' :
                        entry.rank === 2 ? 'rank-2-circle' :
                        entry.rank === 3 ? 'rank-3-circle' :
                        'bg-surface-tertiary text-secondary'
                      }`}>{entry.rank}</span>
                      <span className="font-medium">{entry.name}</span>
                    </div>
                    <span className="font-semibold gold-text">{entry.total_points} {t('common.points')}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </MotionDiv>
    </div>
  )
}
