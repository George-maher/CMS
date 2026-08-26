import { useTranslation } from 'react-i18next'
import { isEventActive } from './eventStatus'

interface Props {
  event: { is_active?: boolean | null }
  size?: 'sm' | 'md'
}

/**
 * The ONE user-facing event status indicator: ● Active / ● Inactive.
 */
export default function EventActiveStatus({ event, size = 'sm' }: Props) {
  const { t } = useTranslation()
  const active = isEventActive(event)
  const dot = size === 'md' ? 'h-2.5 w-2.5' : 'h-2 w-2'

  return (
    <span
      className={`inline-flex items-center gap-1.5 font-medium ${size === 'md' ? 'text-sm' : 'text-xs'} ${
        active ? 'text-success' : 'text-muted'
      }`}
    >
      <span className={`shrink-0 rounded-full ${dot} ${active ? 'bg-success' : 'bg-gray-400 dark:bg-gray-500'}`} />
      {active ? t('common.active') : t('common.inactive')}
    </span>
  )
}
