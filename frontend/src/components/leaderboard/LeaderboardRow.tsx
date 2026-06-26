import MotionDiv from '@/components/common/MotionDiv'
import type { LeaderboardEntry } from '@/types'

const medals = ['🥇', '🥈', '🥉']

const circleClasses = ['rank-1-circle', 'rank-2-circle', 'rank-3-circle']

const avatarClasses = [
  'rank-1-circle',
  'rank-2-circle',
  'rank-3-circle',
]

interface Props {
  entry: LeaderboardEntry
  delay?: number
}

export default function LeaderboardRow({ entry, delay }: Props) {
  const isTop3 = entry.rank <= 3
  const d = delay ?? entry.rank * 50

  return (
    <MotionDiv animation="fade-in-up" delay={d}>
      <div className="flex items-center gap-3 rounded-xl border border-border p-3 transition-all hover:shadow-md hover:border-gold-300/50 bg-surface/50 backdrop-blur-sm">
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold ${isTop3 ? circleClasses[entry.rank - 1] : 'bg-surface-tertiary text-secondary'}`}>
          {isTop3 ? (
            <span className="text-base">{medals[entry.rank - 1]}</span>
          ) : (
            `#${entry.rank}`
          )}
        </div>
        {entry.avatar ? (
          <img src={entry.avatar} alt="" className="h-10 w-10 shrink-0 rounded-full object-cover ring-2 ring-gold-400/20" />
        ) : (
          <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold ${isTop3 ? avatarClasses[entry.rank - 1] : 'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300'}`}>
            {entry.name.charAt(0).toUpperCase()}
          </div>
        )}
        <div className="flex-1 min-w-0">
          <p className="truncate font-semibold text-sm">{entry.name}</p>
          <p className="text-xs text-muted">
            {entry.class_name}
            {entry.attendance_count !== undefined && entry.attendance_count !== null && ` · ${entry.attendance_count} attendances`}
          </p>
        </div>
        <div className="text-right shrink-0">
          <p className="font-bold text-lg gold-text">{entry.total_points}</p>
          <p className="text-[10px] text-muted">pts</p>
        </div>
      </div>
    </MotionDiv>
  )
}
