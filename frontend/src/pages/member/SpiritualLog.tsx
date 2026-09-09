import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Calendar, Church, BookOpen, Sparkles, ChevronLeft, ChevronRight, Save, Trash2 } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import { useTheme } from '@/hooks/useTheme'
import { getSpiritualRecords, saveSpiritualRecord, deleteSpiritualRecord } from '@/api/dailySpiritualRecords'
import type { DailySpiritualRecord } from '@/types'

function formatDate(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function getMonthDays(year: number, month: number): Date[] {
  const days: Date[] = []
  const date = new Date(year, month, 1)
  while (date.getMonth() === month) {
    days.push(new Date(date))
    date.setDate(date.getDate() + 1)
  }
  return days
}

export default function SpiritualLog() {
  const { t } = useTranslation()
  const { language } = useTheme()

  const today = new Date()
  const [currentMonth, setCurrentMonth] = useState(today.getMonth())
  const [currentYear, setCurrentYear] = useState(today.getFullYear())
  const [records, setRecords] = useState<DailySpiritualRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedDate, setSelectedDate] = useState<string | null>(null)
  const [selectedRecord, setSelectedRecord] = useState<DailySpiritualRecord | null>(null)
  const [attendedMass, setAttendedMass] = useState(false)
  const [confessed, setConfessed] = useState(false)
  const [receivedCommunion, setReceivedCommunion] = useState(false)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    const dateFrom = formatDate(new Date(currentYear, currentMonth, 1))
    const dateTo = formatDate(new Date(currentYear, currentMonth + 1, 0))
    getSpiritualRecords({ date_from: dateFrom, date_to: dateTo })
      .then(res => { setRecords(res.data) })
      .catch(() => { setRecords([]) })
      .finally(() => { setLoading(false) })
  }, [currentMonth, currentYear])

  const handlePrevMonth = () => {
    if (currentMonth === 0) {
      setCurrentMonth(11)
      setCurrentYear(currentYear - 1)
    } else {
      setCurrentMonth(currentMonth - 1)
    }
    setSelectedDate(null)
    setSelectedRecord(null)
  }

  const handleNextMonth = () => {
    if (currentMonth === 11) {
      setCurrentMonth(0)
      setCurrentYear(currentYear + 1)
    } else {
      setCurrentMonth(currentMonth + 1)
    }
    setSelectedDate(null)
    setSelectedRecord(null)
  }

  const handleDateSelect = (dateStr: string) => {
    setSelectedDate(dateStr)
    const existing = records.find(r => r.activity_date === dateStr)
    setSelectedRecord(existing ?? null)
    setAttendedMass(existing?.attended_mass ?? false)
    setConfessed(existing?.confessed ?? false)
    setReceivedCommunion(existing?.received_communion ?? false)
  }

  const handleSave = async () => {
    if (! selectedDate) return
    setSaving(true)
    try {
      const record = await saveSpiritualRecord({
        activity_date: selectedDate,
        attended_mass: attendedMass,
        confessed: confessed,
        received_communion: receivedCommunion,
      })
      setRecords(prev => {
        const idx = prev.findIndex(r => r.activity_date === selectedDate)
        if (idx >= 0) {
          const updated = [...prev]
          updated[idx] = record
          return updated
        }
        return [...prev, record]
      })
      setSelectedRecord(record)
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (! selectedDate) return
    setDeleting(true)
    try {
      await deleteSpiritualRecord(selectedDate)
      setRecords(prev => prev.filter(r => r.activity_date !== selectedDate))
      setSelectedRecord(null)
      setAttendedMass(false)
      setConfessed(false)
      setReceivedCommunion(false)
    } finally {
      setDeleting(false)
    }
  }

  const monthName = new Date(currentYear, currentMonth).toLocaleString(
    language === 'ar' ? 'ar-EG' : 'en-US',
    { month: 'long', year: 'numeric' },
  )

  const days = getMonthDays(currentYear, currentMonth)
  const firstDayOfWeek = days[0]?.getDay() ?? 0

  const dayNames = language === 'ar'
    ? ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت']
    : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

  const getRecordForDate = (dateStr: string) => records.find(r => r.activity_date === dateStr)

  const isToday = (dateStr: string) => formatDate(today) === dateStr

  const isFuture = (date: Date) => date > today

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl gold-gradient shadow-md">
          <Sparkles className="h-5 w-5 text-navy-900" />
        </div>
        <h1 className="text-xl font-bold">{t('spiritualLog.title')}</h1>
      </div>

      {/* Month Navigator */}
      <div className="card p-4">
        <div className="flex items-center justify-between">
          <button
            onClick={handlePrevMonth}
            className="btn-ghost btn-sm rounded-lg p-2"
            aria-label={t('common.prev')}
          >
            <ChevronLeft className="h-5 w-5" />
          </button>
          <h2 className="text-lg font-semibold">{monthName}</h2>
          <button
            onClick={handleNextMonth}
            className="btn-ghost btn-sm rounded-lg p-2"
            aria-label={t('common.next')}
          >
            <ChevronRight className="h-5 w-5" />
          </button>
        </div>

        {/* Day names header */}
        <div className="mt-3 grid grid-cols-7 gap-1">
          {dayNames.map(name => (
            <div key={name} className="text-center text-xs font-medium text-muted py-1">
              {name}
            </div>
          ))}
        </div>

        {/* Calendar grid */}
        {loading ? (
          <LoadingSpinner className="py-8" />
        ) : (
          <div className="grid grid-cols-7 gap-1">
            {/* Empty cells for offset */}
            {Array.from({ length: firstDayOfWeek }).map((_, i) => (
              <div key={`empty-${i}`} />
            ))}

            {days.map(day => {
              const dateStr = formatDate(day)
              const record = getRecordForDate(dateStr)
              const future = isFuture(day)
              const todayMark = isToday(dateStr)
              const selected = selectedDate === dateStr

              return (
                <button
                  key={dateStr}
                  onClick={() => ! future && handleDateSelect(dateStr)}
                  disabled={future}
                  className={`relative flex flex-col items-center rounded-lg p-1.5 text-sm transition-all
                    ${future ? 'cursor-not-allowed opacity-30' : 'cursor-pointer hover:bg-primary/10'}
                    ${selected ? 'ring-2 ring-primary bg-primary/10' : ''}
                    ${todayMark ? 'font-bold' : ''}
                  `}
                >
                  <span className={`${todayMark ? 'text-primary' : ''}`}>
                    {day.getDate()}
                  </span>
                  {/* Activity indicators */}
                  {record && (
                    <div className="flex gap-0.5 mt-0.5">
                      {record.attended_mass && (
                        <div className="h-1.5 w-1.5 rounded-full bg-success" title={t('spiritualLog.attendedMass')} />
                      )}
                      {record.confessed && (
                        <div className="h-1.5 w-1.5 rounded-full bg-info" title={t('spiritualLog.confessed')} />
                      )}
                      {record.received_communion && (
                        <div className="h-1.5 w-1.5 rounded-full bg-gold-400" title={t('spiritualLog.receivedCommunion')} />
                      )}
                    </div>
                  )}
                </button>
              )
            })}
          </div>
        )}

        {/* Legend */}
        <div className="mt-3 flex flex-wrap gap-3 text-xs text-muted">
          <div className="flex items-center gap-1">
            <div className="h-2 w-2 rounded-full bg-success" />
            <span>{t('spiritualLog.attendedMass')}</span>
          </div>
          <div className="flex items-center gap-1">
            <div className="h-2 w-2 rounded-full bg-info" />
            <span>{t('spiritualLog.confessed')}</span>
          </div>
          <div className="flex items-center gap-1">
            <div className="h-2 w-2 rounded-full bg-gold-400" />
            <span>{t('spiritualLog.receivedCommunion')}</span>
          </div>
        </div>
      </div>

      {/* Selected Date Form */}
      {selectedDate && (
        <div className="card p-4 space-y-4">
          <h3 className="text-base font-semibold flex items-center gap-2">
            <Calendar className="h-4 w-4" />
            {new Date(selectedDate + 'T00:00:00').toLocaleDateString(
              language === 'ar' ? 'ar-EG' : 'en-US',
              { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' },
            )}
          </h3>

          {/* Activity toggles */}
          <div className="space-y-3">
            <label className="flex items-center justify-between rounded-lg border border-border p-3 cursor-pointer hover:bg-primary/5 transition-colors">
              <div className="flex items-center gap-3">
                <Church className="h-5 w-5 text-success" />
                <span className="text-sm font-medium">{t('spiritualLog.attendedMass')}</span>
              </div>
              <div className="relative">
                <input
                  type="checkbox"
                  checked={attendedMass}
                  onChange={e => setAttendedMass(e.target.checked)}
                  className="peer sr-only"
                />
                <div className="h-6 w-11 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-success transition-colors" />
                <div className="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-md peer-checked:translate-x-5 transition-transform" />
              </div>
            </label>

            <label className="flex items-center justify-between rounded-lg border border-border p-3 cursor-pointer hover:bg-primary/5 transition-colors">
              <div className="flex items-center gap-3">
                <BookOpen className="h-5 w-5 text-info" />
                <span className="text-sm font-medium">{t('spiritualLog.confessed')}</span>
              </div>
              <div className="relative">
                <input
                  type="checkbox"
                  checked={confessed}
                  onChange={e => setConfessed(e.target.checked)}
                  className="peer sr-only"
                />
                <div className="h-6 w-11 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-success transition-colors" />
                <div className="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-md peer-checked:translate-x-5 transition-transform" />
              </div>
            </label>

            <label className="flex items-center justify-between rounded-lg border border-border p-3 cursor-pointer hover:bg-primary/5 transition-colors">
              <div className="flex items-center gap-3">
                <Sparkles className="h-5 w-5 text-gold-400" />
                <span className="text-sm font-medium">{t('spiritualLog.receivedCommunion')}</span>
              </div>
              <div className="relative">
                <input
                  type="checkbox"
                  checked={receivedCommunion}
                  onChange={e => setReceivedCommunion(e.target.checked)}
                  className="peer sr-only"
                />
                <div className="h-6 w-11 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-success transition-colors" />
                <div className="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-md peer-checked:translate-x-5 transition-transform" />
              </div>
            </label>
          </div>

          {/* Actions */}
          <div className="flex gap-2">
            <button
              onClick={handleSave}
              disabled={saving}
              className="btn-primary btn-md flex-1 flex items-center justify-center gap-2"
            >
              <Save className="h-4 w-4" />
              {saving ? t('common.saving') : t('common.save')}
            </button>
            {selectedRecord && (
              <button
                onClick={handleDelete}
                disabled={deleting}
                className="btn-ghost btn-md flex items-center justify-center gap-2 rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 px-4"
              >
                <Trash2 className="h-4 w-4" />
                {deleting ? t('common.saving') : t('common.delete')}
              </button>
            )}
          </div>
        </div>
      )}

      {/* Empty state */}
      {!loading && !selectedDate && records.length === 0 && (
        <div className="card p-8 text-center">
          <Sparkles className="h-12 w-12 mx-auto text-muted mb-3" />
          <p className="text-muted">{t('spiritualLog.noRecordsYet')}</p>
          <p className="text-xs text-muted mt-1">{t('spiritualLog.selectDateHint')}</p>
        </div>
      )}

      {/* Recent records list */}
      {!loading && records.length > 0 && ! selectedDate && (
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-muted mb-3">{t('spiritualLog.recentRecords')}</h3>
          <div className="space-y-2">
            {records.slice(0, 10).map(record => (
              <button
                key={record.id}
                onClick={() => handleDateSelect(record.activity_date)}
                className="w-full flex items-center justify-between rounded-lg border border-border p-3 hover:bg-primary/5 transition-colors text-left"
              >
                <div>
                  <p className="text-sm font-medium">
                    {new Date(record.activity_date + 'T00:00:00').toLocaleDateString(
                      language === 'ar' ? 'ar-EG' : 'en-US',
                      { weekday: 'long', month: 'long', day: 'numeric' },
                    )}
                  </p>
                  <div className="flex gap-2 mt-1 text-xs text-muted">
                    {record.attended_mass && <span className="text-success">{t('spiritualLog.mass')}</span>}
                    {record.confessed && <span className="text-info">{t('spiritualLog.confession')}</span>}
                    {record.received_communion && <span className="text-gold-400">{t('spiritualLog.communion')}</span>}
                  </div>
                </div>
                <ChevronRight className="h-4 w-4 text-muted" />
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
