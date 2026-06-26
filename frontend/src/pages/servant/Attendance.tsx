import { useEffect, useState, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { ctxName } from '@/lib/contextLabels'
import { getFilteredAttendances } from '@/api/attendance'
import { getMyClasses } from '@/api/structure'
import AttendanceFilter from '@/components/common/AttendanceFilter'
import type { AttendanceFilterValues } from '@/components/common/AttendanceFilter'
import Badge from '@/components/common/Badge'
import type { Attendance, PaginationMeta } from '@/types'
import { ChevronLeft, ChevronRight } from 'lucide-react'

export default function AttendancePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { language } = useTheme()
  const isServant = user?.role === 'servant'

  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [loading, setLoading] = useState(false)

  const [filters, setFilters] = useState<AttendanceFilterValues>({
    contextId: '',
    classId: '',
    date: '',
    dateFrom: '',
    dateTo: '',
    search: '',
  })

  const [committed, setCommitted] = useState<AttendanceFilterValues>(filters)
  const [data, setData] = useState<Attendance[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [page, setPage] = useState(1)

  const titleSuffix = useMemo(() => {
    if (!isServant) return ''
    if (classes.length === 0) return ''
    if (classes.length === 1) return ` - ${classes[0]?.name}`
    return ` - ${t('attendance.myClasses')}`
  }, [isServant, classes, t])

  useEffect(() => {
    getMyClasses()
      .then((c) => {
        setClasses(c ?? [])
        if (isServant && c?.length > 0) {
          const firstId = c[0]?.id ?? ''
          setFilters((prev) => ({ ...prev, classId: firstId }))
          setCommitted((prev) => ({ ...prev, classId: firstId }))
        }
      })
      .catch(() => {})
  }, [])

  const fetchData = useCallback(async (p: number, filtersToUse: AttendanceFilterValues) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { per_page: 15, page: p }
      if (!isServant && filtersToUse.classId !== '') params.class_id = filtersToUse.classId
      if (filtersToUse.contextId !== '') params.attendance_context_id = filtersToUse.contextId
      if (filtersToUse.dateFrom) params.date_from = filtersToUse.dateFrom
      if (filtersToUse.dateTo) params.date_to = filtersToUse.dateTo
      if (filtersToUse.date) params.date_from = filtersToUse.date
      if (filtersToUse.search.trim()) params.search = filtersToUse.search.trim()
      const res = await getFilteredAttendances(params)
      setData(res.data)
      setMeta(res.meta)
    } catch {
      setData([])
      setMeta(null)
    } finally {
      setLoading(false)
    }
  }, [isServant])

  useEffect(() => {
    fetchData(page, committed)
  }, [page, committed, fetchData])

  const handleApply = () => {
    setCommitted(filters)
    setPage(1)
  }

  const handleReset = () => {
    const reset: AttendanceFilterValues = {
      contextId: '',
      classId: isServant && classes.length > 0 ? (classes[0]?.id ?? '') : '',
      date: '',
      dateFrom: '',
      dateTo: '',
      search: '',
    }
    setFilters(reset)
    setCommitted(reset)
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold">{t('attendance.attendance')}{titleSuffix}</h1>
        <p className="mt-1 text-sm text-secondary">{isServant ? '' : t('attendance.viewByClass')}</p>
      </div>

      <AttendanceFilter
        values={filters}
        onChange={setFilters}
        onApply={handleApply}
        onReset={handleReset}
        showContext
        showClass={!isServant}
        showRange
        showSearch
        loading={loading}
        applyLabel={t('context.apply')}
      />

      {loading ? (
        <div className="card p-12 text-center">
          <p className="text-sm text-secondary">{t('common.loading')}</p>
        </div>
      ) : data.length === 0 ? (
        <div className="card p-12 text-center">
          <p className="text-sm text-muted">{t('attendance.noResults')}</p>
        </div>
      ) : (
        <>
          {/* Desktop table */}
          <div className="hidden sm:block overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-border">
                <thead className="bg-surface-secondary">
                  <tr>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('auth.name')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('users.class')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('context.context')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('attendance.date')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('attendance.time')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('attendance.method')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('attendance.recordedBy')}</th>
                    <th className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-secondary">{t('common.status')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {data.map((a) => (
                    <tr key={a.id} className="hover:bg-surface-secondary transition-colors">
                      <td className="whitespace-nowrap px-4 py-3 text-sm font-medium">{a.user?.name || t('common.unknown')}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">{a.classe?.name || '-'}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">{a.attendance_context ? ctxName(a.attendance_context, language) : '-'}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">{new Date(a.attended_at).toLocaleDateString()}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">{new Date(a.attended_at).toLocaleTimeString()}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">
                        {a.method === 'qr' ? t('attendance.methodQR') : a.method === 'token' ? t('attendance.methodToken') : a.method === 'id' ? t('attendance.methodID') : '-'}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-secondary">{a.recorder?.name || '-'}</td>
                      <td className="whitespace-nowrap px-4 py-3"><Badge variant="success">{t('attendance.present')}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Mobile card view */}
          <div className="sm:hidden space-y-3">
            {data.map((a) => (
              <div key={a.id} className="rounded-xl border border-border bg-surface p-4 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="font-medium text-sm">{a.user?.name || t('common.unknown')}</span>
                  <Badge variant="success">{t('attendance.present')}</Badge>
                </div>
                <div className="grid grid-cols-2 gap-1 text-xs text-secondary">
                  <span>{t('users.class')}: {a.classe?.name || '-'}</span>
                  <span>{t('attendance.date')}: {new Date(a.attended_at).toLocaleDateString()}</span>
                  <span>{t('attendance.time')}: {new Date(a.attended_at).toLocaleTimeString()}</span>
                  <span>{t('attendance.method')}: {a.method === 'qr' ? t('attendance.methodQR') : a.method === 'token' ? t('attendance.methodToken') : a.method === 'id' ? t('attendance.methodID') : '-'}</span>
                </div>
                <div className="text-xs text-muted">
                  <span>{a.attendance_context ? ctxName(a.attendance_context, language) : '-'}</span>
                  {a.recorder?.name && <span className="ml-2">· {a.recorder.name}</span>}
                </div>
              </div>
            ))}
          </div>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between gap-4">
              <p className="text-sm text-secondary">
                {t('common.page')} {meta.current_page} {t('common.of')} {meta.last_page} ({meta.total} {t('attendance.total')})
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={meta.current_page <= 1}
                  className="btn-ghost btn-sm border disabled:opacity-50"
                >
                  <ChevronLeft className="h-4 w-4" />
                  <span className="hidden sm:inline">{t('common.prev')}</span>
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={meta.current_page >= meta.last_page}
                  className="btn-ghost btn-sm border disabled:opacity-50"
                >
                  <span className="hidden sm:inline">{t('common.next')}</span>
                  <ChevronRight className="h-4 w-4" />
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
