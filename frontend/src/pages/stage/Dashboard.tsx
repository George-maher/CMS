import { fmtDate } from '@/lib/dates'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CalendarDays, Layers, Users, Cross, Trophy, BookOpen, ShieldCheck, Settings } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { useAuth } from '@/hooks/useAuth'
import { getDashboardStats } from '@/api/dashboard'
import { listStages } from '@/api/stages'
import { getStageClasses } from '@/api/stages'
import { listEvents } from '@/api/events'
import { getLeaderboard } from '@/api/points'
import type { Classe } from '@/types'
import { logCatch } from '@/lib/debug'

interface UpcomingEvent { id: number; name: string; event_date: string }

export default function StageAdminDashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [stats, setStats] = useState({ total_members: 0, active_members: 0, total_servants: 0, total_attendances: 0, total_points: 0, total_members_managed: 0 })
  const [classes, setClasses] = useState<Classe[]>([])
  const [classesCount, setClassesCount] = useState<number>(0)
  const [stageName, setStageName] = useState<string>('')
  const [upcomingEvents, setUpcomingEvents] = useState<UpcomingEvent[]>([])
  const [leaderboard, setLeaderboard] = useState<{ rank: number; name: string; total_points: number }[]>([])
  const [loading, setLoading] = useState(true)
  const [noStage, setNoStage] = useState(false)

  useEffect(() => {
    if (!user?.stage_id) {
      setNoStage(true)
      setLoading(false)
      return
    }

    const scope = user.stage_id

    Promise.all([
      getDashboardStats().catch((e) => { logCatch('StageDashboard.getStats', e); return { total_members: 0, active_members: 0, total_servants: 0, total_attendances: 0, total_points: 0, total_members_managed: 0 } }),
      listStages().catch((e) => { logCatch('StageDashboard.listStages', e); return [] }),
      getStageClasses(scope).catch((e) => { logCatch('StageDashboard.getStageClasses', e); return [] }),
      listEvents({ upcoming: true, per_page: 3 }).catch((e) => { logCatch('StageDashboard.listEvents', e); return { data: [] } }),
      getLeaderboard(5).catch((e) => { logCatch('StageDashboard.getLeaderboard', e); return { data: [] } }),
    ]).then(([s, stages, classes, ev, lb]) => {
      setStats(s)
      const ownStage = stages.find((st) => st.id === user?.stage_id)
      setStageName(ownStage?.name ?? '')
      const stageClasses = Array.isArray(classes) ? classes : []
      setClasses(stageClasses)
      setClassesCount(stageClasses.length)
      setUpcomingEvents(ev.data)
      setLeaderboard(lb.data)
    }).finally(() => setLoading(false))
  }, [user?.stage_id])

  if (loading) return <LoadingSpinner className="py-20" />

  if (noStage) {
    return (
      <div className="space-y-4">
        <DailyVerseBanner />
        <div className="glass-card-solid p-8 text-center">
          <Layers className="h-12 w-12 mx-auto text-muted mb-4" />
          <h2 className="text-lg font-semibold mb-2">{t('dashboard.noStageAssigned')}</h2>
          <p className="text-sm text-secondary">{t('dashboard.noStageAssignedDesc')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <DailyVerseBanner />

      <div className="glass-card-solid p-5">
        <h2 className="text-lg font-semibold">{t('dashboard.welcome')}, {user?.name}</h2>
        <p className="mt-1 text-sm text-secondary">
          {stageName ? `${stageName} · ` : ''}{t('dashboard.stageAdminDashboard')}
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard title={t('dashboard.totalMembers')} value={stats.total_members} subtitle={t('dashboard.registered')} icon={<Users className="h-5 w-5" />} color="gold" />
        <StatCard title={t('dashboard.activeMembers')} value={stats.active_members} subtitle={t('dashboard.currentlyActive')} icon={<Cross className="h-5 w-5" />} color="navy" />
        <StatCard title={t('dashboard.totalServants')} value={stats.total_servants} subtitle={`${stats.total_members_managed} ${t('dashboard.myMembers')}`} icon={<Users className="h-5 w-5" />} color="primary" />
        <StatCard title={t('dashboard.stageClasses')} value={classesCount} subtitle={stageName || t('structure.selectStage')} icon={<Layers className="h-5 w-5" />} color="info" />
      </div>

      <div className="glass-card-solid p-5">
        <div className="mb-4 flex items-center justify-between gap-3">
          <h2 className="flex items-center gap-2 text-lg font-semibold">
            <BookOpen className="h-5 w-5 gold-text" />
            {t('structure.classes')}
          </h2>
          {user?.stage_id && (
            <button
              onClick={() => navigate(`/stage/stages/${user.stage_id}`)}
              className="btn-sm btn-ghost gap-1.5 shrink-0"
            >
              <Settings className="h-3.5 w-3.5" /> {t('structure.manageClasses')}
            </button>
          )}
        </div>
        {classes.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">{t('structure.noClasses')}</p>
        ) : (
          <div className="space-y-2">
            {classes.map((classe) => (
              <button
                key={classe.id}
                onClick={() => navigate(`/stage/classes/${classe.id}`)}
                className="flex w-full flex-col gap-1 rounded-lg border border-border px-4 py-3 text-start transition-colors hover:bg-surface-secondary sm:flex-row sm:items-center sm:justify-between"
              >
                <span className="flex items-center gap-2 font-medium">
                  <BookOpen className="h-4 w-4 text-success-dark" />
                  {classe.name}
                </span>
                <span className="flex items-center gap-1.5 text-xs text-secondary">
                  <ShieldCheck className="h-3.5 w-3.5 text-gold-500" />
                  {classe.secretary ? classe.secretary.name : t('structure.noClassSecretary')}
                  <span className="mx-1 text-muted">·</span>
                  {classe.member_count} {t('structure.members')}
                  <span className="mx-1 text-muted">·</span>
                  {classe.servant_count} {t('structure.servants')}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="glass-card-solid p-6">
          <h2 className="section-title mb-4 flex items-center gap-2">
            <Cross className="h-5 w-5 gold-text" />
            {t('dashboard.systemOverview')}
          </h2>
          <div className="space-y-4">
            <div className="flex justify-between border-b border-border pb-2.5">
              <span className="text-sm text-secondary">{t('dashboard.totalAttendances')}</span>
              <span className="font-semibold text-lg">{stats.total_attendances}</span>
            </div>
            <div className="flex justify-between border-b border-border pb-2.5">
              <span className="text-sm text-secondary">{t('dashboard.totalPointsAwarded')}</span>
              <span className="font-semibold text-lg">{stats.total_points}</span>
            </div>
            <div className="flex justify-between border-b border-border pb-2.5">
              <span className="text-sm text-secondary">{t('dashboard.avgMembersPerServant')}</span>
              <span className="font-semibold text-lg">
                {stats.total_servants > 0 ? (stats.total_members_managed / stats.total_servants).toFixed(1) : '0'}
              </span>
            </div>
            <div className="flex justify-between pb-2.5">
              <span className="text-sm text-secondary">{t('dashboard.activeMemberRate')}</span>
              <span className="font-semibold text-lg">
                {stats.total_members > 0 ? ((stats.active_members / stats.total_members) * 100).toFixed(0) + '%' : '0%'}
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
