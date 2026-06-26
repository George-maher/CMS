interface Props {
  title: string
  value: string | number
  subtitle?: string
  icon?: React.ReactNode
  color?: 'primary' | 'gold' | 'navy' | 'success' | 'info'
  delay?: number
}

const iconBg: Record<string, string> = {
  primary: 'bg-primary-100 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400',
  gold: 'bg-gold-100 text-gold-600 dark:bg-gold-900/30 dark:text-gold-400',
  navy: 'bg-navy-100 text-navy-600 dark:bg-navy-900/30 dark:text-navy-400',
  success: 'bg-success-light text-success dark:bg-success-dark/30 dark:text-success',
  info: 'bg-info-light text-info dark:bg-info-dark/30 dark:text-info',
}

export default function StatCard({ title, value, subtitle, icon, color = 'primary', delay = 0 }: Props) {
  return (
    <div
      className="stat-card stagger-item"
      style={{ animationDelay: `${delay}ms` }}
    >
      <div className="flex items-start justify-between">
        <div className="flex-1 min-w-0">
          <p className="stat-label truncate">{title}</p>
          <p className="stat-value">{value}</p>
          {subtitle && <p className="mt-1 text-xs text-muted">{subtitle}</p>}
        </div>
        {icon && (
          <div className={`shrink-0 ml-3 p-2.5 rounded-xl ${iconBg[color] || iconBg.primary}`}>
            {icon}
          </div>
        )}
      </div>
    </div>
  )
}
