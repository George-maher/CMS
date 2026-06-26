import { Crown } from 'lucide-react'
import MotionDiv from '@/components/common/MotionDiv'
import type { LeaderboardEntry } from '@/types'

const medals = ['🥇', '🥈', '🥉']

const heights = ['h-32', 'h-24', 'h-20']

const podiumClasses = ['podium-gold', 'podium-silver', 'podium-bronze']

const circleClasses = ['rank-1-circle', 'rank-2-circle', 'rank-3-circle']

interface Props {
  entry: LeaderboardEntry
  index: number
}

export default function PodiumCard({ entry, index }: Props) {
  return (
    <div className="flex flex-col items-center gap-3">
      <MotionDiv animation="fade-in-up" delay={index * 100}>
        <div className="glass-card-solid p-4 w-full min-w-[180px] text-center">
          <div className={`inline-flex items-center justify-center w-12 h-12 rounded-full text-2xl ${circleClasses[index]} mb-2`}>
            {index === 0 ? (
              <Crown className="h-6 w-6" />
            ) : (
              <span className="text-lg font-bold">{medals[index]}</span>
            )}
          </div>
          {entry.avatar ? (
            <img src={entry.avatar} alt="" className="mx-auto h-14 w-14 rounded-full object-cover ring-2 ring-gold-400/30 mb-2" />
          ) : (
            <div className={`mx-auto h-14 w-14 rounded-full flex items-center justify-center text-xl font-bold mb-2 ${circleClasses[index]}`}>
              {entry.name.charAt(0)}
            </div>
          )}
          <p className="font-bold text-sm truncate">{entry.name}</p>
          <p className="text-xs text-muted">{entry.class_name}</p>
          <p className="mt-2 text-2xl font-bold gold-text">{entry.total_points}</p>
          <p className="text-[10px] text-muted">points</p>
        </div>
      </MotionDiv>
      <div className={`w-full rounded-t-xl ${podiumClasses[index]} ${heights[index]} flex items-center justify-center`}>
        <span className="text-3xl font-bold text-white drop-shadow-lg">#{index + 1}</span>
      </div>
    </div>
  )
}
