import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trophy, Star, Sparkles } from 'lucide-react'
import DataTable from '@/components/common/DataTable'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import MotionDiv from '@/components/common/MotionDiv'
import LeaderboardRow from '@/components/leaderboard/LeaderboardRow'
import type { Column } from '@/components/common/DataTable'
import type { Point, LeaderboardEntry } from '@/types'
import { getBalance, getPointsHistory, getMyClassLeaderboard, getGlobalLeaderboard } from '@/api/points'

export default function MemberPoints() {
  const { t } = useTranslation()

  const columns: Column<Point>[] = [
    { key: 'type_label', header: t('points.type') },
    { key: 'points', header: t('points.points') },
    { key: 'created_at', header: t('points.date'), render: (p) => new Date(p.created_at).toLocaleDateString() },
    { key: 'description', header: t('points.description') },
  ]
  const [points, setPoints] = useState<Point[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [balance, setBalance] = useState(0)
  const [loading, setLoading] = useState(true)
  const [classData, setClassData] = useState<{ class: { id: number; name: string } | null; stage: { id: number; name: string } | null; leaderboard: LeaderboardEntry[] } | null>(null)
  const [global, setGlobal] = useState<LeaderboardEntry[]>([])
  const [lbLoading, setLbLoading] = useState(true)

  const fetch = async (page = 1) => {
    setLoading(true)
    try {
      const [b, res] = await Promise.all([getBalance(), getPointsHistory({ page, per_page: 15 })])
      setBalance(b); setPoints(res.data); setMeta(res.meta)
    } finally { setLoading(false) }
  }

  useEffect(() => {
    fetch()
    Promise.all([
      getMyClassLeaderboard().catch(() => null),
      getGlobalLeaderboard().catch(() => []),
    ]).then(([c, g]) => {
      setClassData(c)
      setGlobal(g)
    }).finally(() => setLbLoading(false))
  }, [])

  if (lbLoading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-6">
      <MotionDiv animation="fade-in-up">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <StatCard title={t('dashboard.myPoints')} value={balance} icon={<Sparkles className="h-5 w-5" />} color="gold" />
        </div>
      </MotionDiv>

      {/* My Class Leaderboard */}
      {classData?.class && classData.leaderboard.length > 0 && (
        <MotionDiv animation="fade-in-up" delay={50}>
          <section>
            <div className="mb-3 flex items-center gap-2">
              <Star className="h-5 w-5 text-gold-400" />
              <h2 className="text-lg font-semibold gold-text">
                {classData.stage ? `${classData.stage.name} — ` : ''}{classData.class.name} {t('leaderboard.classTop3')}
              </h2>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
              {classData.leaderboard.map((entry) => (
                <LeaderboardRow key={entry.user_id} entry={entry} />
              ))}
            </div>
          </section>
        </MotionDiv>
      )}

      {/* Global Top 5 */}
      {global.length > 0 && (
        <MotionDiv animation="fade-in-up" delay={80}>
          <section>
            <div className="mb-3 flex items-center gap-2">
              <Trophy className="h-5 w-5 text-gold-400" />
              <h2 className="text-lg font-semibold gold-text">{t('leaderboard.globalTop5')}</h2>
            </div>
            <div className="space-y-2">
              {global.map((entry) => (
                <LeaderboardRow key={entry.user_id} entry={entry} />
              ))}
            </div>
          </section>
        </MotionDiv>
      )}

      <MotionDiv animation="fade-in-up" delay={100}>
        <DataTable columns={columns} data={points} meta={meta} isLoading={loading} onPageChange={fetch} />
      </MotionDiv>
    </div>
  )
}
