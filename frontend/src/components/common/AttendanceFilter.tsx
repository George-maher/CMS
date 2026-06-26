import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Filter, RotateCcw, Search } from 'lucide-react'
import { listAllClasses } from '@/api/structure'
import { getActiveContexts } from '@/api/attendanceContexts'
import { useTheme } from '@/contexts/ThemeContext'
import { ctxOptionLabel } from '@/lib/contextLabels'
import type { AttendanceContext } from '@/types'
import toast from 'react-hot-toast'

export interface AttendanceFilterValues {
  classId: number | ''
  contextId: number | ''
  date: string
  dateFrom: string
  dateTo: string
  search: string
}

interface Props {
  values: AttendanceFilterValues
  onChange: (values: AttendanceFilterValues) => void
  onApply: () => void
  onReset?: () => void
  showClass?: boolean
  showContext?: boolean
  showDate?: boolean
  showRange?: boolean
  showSearch?: boolean
  classDisabled?: boolean
  classList?: { id: number; name: string }[]
  loading?: boolean
  applyLabel?: string
  resetLabel?: string
}

export default function AttendanceFilter({
  values,
  onChange,
  onApply,
  onReset,
  showClass = true,
  showContext = false,
  showDate = true,
  showRange = true,
  showSearch = false,
  classDisabled = false,
  classList,
  loading = false,
  applyLabel,
  resetLabel,
}: Props) {
  const { t } = useTranslation()
  const { language } = useTheme()
  const [fetchedClasses, setFetchedClasses] = useState<{ id: number; name: string }[]>([])
  const [contexts, setContexts] = useState<AttendanceContext[]>([])
  const classes = classList ?? fetchedClasses

  useEffect(() => {
    if (!classList) {
      listAllClasses().then((cl) => {
        setFetchedClasses(cl)
      }).catch(() => [])
    }
  }, [classList])

  useEffect(() => {
    getActiveContexts().then(setContexts).catch(() => toast.error(t('common.failedToLoad')))
  }, [])

  const update = (partial: Partial<AttendanceFilterValues>) => {
    onChange({ ...values, ...partial })
  }

  const hasAnyFilter = showClass || showContext || showDate || showRange || showSearch

  if (!hasAnyFilter) return null

  return (
    <div className="card overflow-hidden border border-border bg-surface shadow-sm">
      <div className="border-b border-border bg-surface-secondary px-5 py-3">
        <div className="flex items-center gap-2 text-sm font-medium text-secondary">
          <Filter className="h-4 w-4" />
          {t('common.filter')}
        </div>
      </div>

      <div className="space-y-4 p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {showContext && (
            <div>
              <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
                {t('context.context')}
              </label>
              <select
                value={values.contextId}
                onChange={(e) => update({ contextId: e.target.value ? Number(e.target.value) : '' })}
                className="input-field"
              >
                <option value="">{t('attendance.allContexts')}</option>
                {contexts.map((c) => (
                  <option key={c.id} value={c.id}>{ctxOptionLabel(c, language)}</option>
                ))}
              </select>
            </div>
          )}

          {showClass && (
            <div>
              <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
                {t('users.class')}
              </label>
              <select
                value={values.classId}
                onChange={(e) => update({ classId: e.target.value ? Number(e.target.value) : '' })}
                className="input-field"
                disabled={classDisabled}
              >
                <option value="">{t('attendance.allClasses')}</option>
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
          )}
        </div>

        {(showDate || showRange) && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {showDate && (
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
                  {t('absentMembers.date')}
                </label>
                <input
                  type="date"
                  value={values.date}
                  onChange={(e) => update({ date: e.target.value })}
                  className="input-field"
                />
              </div>
            )}
            {showRange && (
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
                  {t('context.from')}
                </label>
                <input
                  type="date"
                  value={values.dateFrom}
                  onChange={(e) => update({ dateFrom: e.target.value })}
                  className="input-field"
                />
              </div>
            )}
            {showRange && (
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
                  {t('context.to')}
                </label>
                <input
                  type="date"
                  value={values.dateTo}
                  onChange={(e) => update({ dateTo: e.target.value })}
                  className="input-field"
                />
              </div>
            )}
          </div>
        )}

        {showSearch && (
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
              {t('attendance.searchMembers')}
            </label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-secondary" />
              <input
                type="text"
                value={values.search}
                onChange={(e) => update({ search: e.target.value })}
                placeholder={t('attendance.searchMembers')}
                className="input-field pl-10"
                onKeyDown={(e) => { if (e.key === 'Enter') onApply() }}
              />
            </div>
          </div>
        )}

        <div className="flex flex-col gap-2 pt-1 sm:flex-row">
          <button onClick={() => onApply()} disabled={loading} className="btn-primary btn-md flex-1 sm:flex-none">
            <Filter className="h-4 w-4" />
            {applyLabel || t('context.apply')}
          </button>
          {onReset && (
            <button onClick={() => onReset()} disabled={loading} className="btn-ghost btn-md border flex-1 sm:flex-none">
              <RotateCcw className="h-4 w-4" />
              {resetLabel || t('attendance.resetFilters')}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
