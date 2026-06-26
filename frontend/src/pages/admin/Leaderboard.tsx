import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trophy, Star, Users, Crown, Sparkles } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import MotionDiv from '@/components/common/MotionDiv'
import PodiumCard from '@/components/leaderboard/PodiumCard'
import LeaderboardRow from '@/components/leaderboard/LeaderboardRow'
import { getStagesLeaderboards, getGlobalLeaderboard } from '@/api/points'
import type { StageLeaderboard, LeaderboardEntry } from '@/types'

export default function AdminLeaderboard() {
  const { t } = useTranslation()
  const [stages, setStages] = useState<StageLeaderboard[]>([])
  const [global, setGlobal] = useState<LeaderboardEntry[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([
      getStagesLeaderboards().catch(() => []),
      getGlobalLeaderboard().catch(() => []),
    ]).then(([s, g]) => { setStages(s); setGlobal(g) }).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-10">
      <MotionDiv animation="fade-in-up">
        <div className="flex items-center gap-3">
          <Trophy className="h-8 w-8 gold-text" />
          <h1 className="text-2xl sm:text-3xl font-bold">{t('leaderboard.title')}</h1>
        </div>
      </MotionDiv>

      {/* ─── Global Top 5 (Podium Style) ─── */}
      <MotionDiv animation="fade-in-up" delay={50}>
        <section>
          <div className="mb-6 flex items-center gap-2">
            <Star className="h-6 w-6 text-gold-400" />
            <h2 className="text-xl font-bold gold-text">{t('leaderboard.globalTop5')}</h2>
            <Sparkles className="h-4 w-4 text-gold-400 animate-pulse-glow" />
          </div>
          {global.length === 0 ? (
            <div className="glass-card-solid p-12 text-center">
              <Users className="mx-auto h-16 w-16 text-muted" />
              <p className="mt-4 text-lg text-muted">{t('common.noDataYet')}</p>
            </div>
          ) : (
            <div className="flex flex-col lg:flex-row items-end justify-center gap-4 lg:gap-8">
              {global.slice(0, 3).map((entry, i) => (
                <PodiumCard key={entry.user_id} entry={entry} index={i} />
              ))}
            </div>
          )}
          {global.length > 3 && (
            <div className="mt-8 grid gap-3 sm:grid-cols-2">
              {global.slice(3).map((entry) => (
                <LeaderboardRow key={entry.user_id} entry={entry} />
              ))}
            </div>
          )}
        </section>
      </MotionDiv>

      {/* ─── Stages Leaderboards ─── */}
      {stages.map((stage, si) => (
        <MotionDiv key={stage.stage_id} animation="fade-in-up" delay={si * 80}>
          <section>
            <h2 className="mb-5 text-xl font-bold border-b border-gold-400/20 pb-3 gold-text flex items-center gap-2">
              <Crown className="h-5 w-5" />
              {stage.stage_name}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {stage.classes.map((cls) => (
                <div key={cls.id} className="glass-card-solid p-4">
                  <h3 className="mb-4 font-bold text-secondary text-sm uppercase tracking-wider flex items-center gap-2">
                    <Trophy className="h-4 w-4 gold-text" />
                    {cls.name}
                  </h3>
                  {cls.leaderboard.length === 0 ? (
                    <p className="text-sm text-muted py-3 text-center">{t('common.noDataYet')}</p>
                  ) : (
                    <div className="space-y-2">
                      {cls.leaderboard.map((entry) => (
                        <LeaderboardRow key={entry.user_id} entry={entry} />
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>
        </MotionDiv>
      ))}
    </div>
  )
}
