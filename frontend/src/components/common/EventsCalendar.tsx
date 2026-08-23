import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Badge from '@/components/common/Badge'
import type { Event } from '@/types'

interface Props {
  events: Event[]
  onOpenEvent: (id: number) => void
}

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

export default function EventsCalendar({ events, onOpenEvent }: Props) {
  const { t, i18n } = useTranslation()
  const [cursor, setCursor] = useState(() => {
    const d = new Date()
    return new Date(d.getFullYear(), d.getMonth(), 1)
  })

  const locale = i18n.language === 'ar' ? 'ar' : 'en'

  const days = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
    const startOffset = first.getDay()
    const gridStart = new Date(first)
    gridStart.setDate(gridStart.getDate() - startOffset)

    const cells: { date: Date; inMonth: boolean }[] = []
    for (let i = 0; i < 42; i++) {
      const d = new Date(gridStart)
      d.setDate(gridStart.getDate() + i)
      cells.push({ date: d, inMonth: d.getMonth() === cursor.getMonth() })
    }
    return cells
  }, [cursor])

  const eventsByDay = useMemo(() => {
    const map = new Map<string, Event[]>()
    for (const e of events) {
      if (!e.event_date) continue
      const d = new Date(e.event_date)
      const key = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
      const list = map.get(key) ?? []
      list.push(e)
      map.set(key, list)
    }
    return map
  }, [events])

  const monthLabel = cursor.toLocaleDateString(locale, { month: 'long', year: 'numeric' })
  // Weekday labels starting on Sunday.
  const weekdayLabels = Array.from({ length: 7 }, (_, i) =>
    new Date(2024, 0, 7 + i).toLocaleDateString(locale, { weekday: 'short' }),
  )
  void t

  return (
    <div className="rounded-xl border border-border bg-surface p-3 sm:p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <button onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} className="btn-secondary btn-sm">
          ‹
        </button>
        <p className="text-sm font-semibold">{monthLabel}</p>
        <button onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} className="btn-secondary btn-sm">
          ›
        </button>
      </div>

      <div className="grid grid-cols-7 gap-1 text-center">
        {weekdayLabels.map((w) => (
          <div key={w} className="py-1 text-[10px] font-medium text-secondary sm:text-xs">{w}</div>
        ))}
        {days.map(({ date, inMonth }) => {
          const key = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`
          const dayEvents = eventsByDay.get(key) ?? []
          const isToday = sameDay(date, new Date())
          return (
            <div
              key={key}
              className={`min-h-14 rounded-lg border p-1 sm:min-h-20 ${
                inMonth ? 'border-border bg-surface' : 'border-transparent opacity-40'
              } ${isToday ? 'ring-1 ring-primary' : ''}`}
            >
              <p className={`text-[10px] sm:text-xs ${isToday ? 'font-bold text-primary' : 'text-secondary'}`}>{date.getDate()}</p>
              <div className="mt-0.5 space-y-0.5">
                {dayEvents.slice(0, 2).map((e) => (
                  <button
                    key={e.id}
                    onClick={() => onOpenEvent(e.id)}
                    className="block w-full truncate rounded px-1 py-0.5 text-start text-[9px] sm:text-[10px] bg-primary/10 text-primary hover:bg-primary/20"
                  >
                    {e.name}
                  </button>
                ))}
                {dayEvents.length > 2 ? (
                  <Badge variant="default">+{dayEvents.length - 2}</Badge>
                ) : null}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
