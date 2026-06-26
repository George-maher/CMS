import { useCallback, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Phone, Copy, MessageCircle, MessageSquare, Users, UserCheck, UserX, AlertTriangle } from 'lucide-react'
import toast from 'react-hot-toast'
import { getAbsentMembers, type AbsentMembersResponse } from '@/api/attendance'
import { getMyClasses } from '@/api/structure'
import { useAuth } from '@/contexts/AuthContext'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Badge from '@/components/common/Badge'
import StatCard from '@/components/common/StatCard'
import AttendanceFilter, { type AttendanceFilterValues } from '@/components/common/AttendanceFilter'

export default function AbsentMembers() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const isServant = user?.role === 'servant'

  const [result, setResult] = useState<AbsentMembersResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [filterValues, setFilterValues] = useState<AttendanceFilterValues>({
    classId: '',
    contextId: '',
    date: '',
    dateFrom: '',
    dateTo: '',
  })

  const [myClasses, setMyClasses] = useState<{ id: number; name: string }[]>([])

  const effectiveClassId = useMemo(() => {
    if (!isServant) return filterValues.classId
    return myClasses.length > 0 ? myClasses[0].id : ''
  }, [isServant, myClasses, filterValues.classId])

  const fetchAbsentMembers = useCallback(async (classId?: number | string) => {
    const id = classId ?? effectiveClassId
    if (!id) {
      if (!isServant) toast.error(t('absentMembers.selectClassFirst'))
      return
    }
    setLoading(true)
    try {
      const res = await getAbsentMembers({
        class_id: id as number,
        context_id: filterValues.contextId ? (filterValues.contextId as number) : undefined,
        date: filterValues.date || undefined,
        date_from: filterValues.dateFrom || undefined,
        date_to: filterValues.dateTo || undefined,
      })
      setResult(res)
    } catch {
      toast.error(t('absentMembers.fetchFailed'))
    } finally {
      setLoading(false)
      setLoaded(true)
    }
  }, [isServant, effectiveClassId, filterValues.contextId, filterValues.date, filterValues.dateFrom, filterValues.dateTo])

  useEffect(() => {
    if (!isServant) return
    getMyClasses()
      .then((classes) => {
        setMyClasses(classes ?? [])
        if (classes?.length > 0) {
          const firstId = classes[0].id
          setFilterValues((prev) => ({ ...prev, classId: firstId }))
          fetchAbsentMembers(firstId)
        }
      })
      .catch(() => toast.error(t('common.failedToLoad')))
  }, [])

  const handleCopyPhone = (phone: string) => {
    navigator.clipboard.writeText(phone).then(() => toast.success(t('common.copied')))
  }

  const handleCall = (phone: string) => {
    window.open(`tel:${phone}`, '_blank')
  }

  const handleWhatsApp = (phone: string) => {
    const cleaned = phone.replace(/[^0-9]/g, '')
    window.open(`https://wa.me/${cleaned}`, '_blank')
  }

  const handleSms = (phone: string) => {
    window.open(`sms:${phone}`, '_blank')
  }

  const absentMembers = useMemo(() => result?.absent_members ?? [], [result])

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="text-2xl font-bold">
            {t('absentMembers.title')}
            {isServant && myClasses.length === 1 && <span> — {myClasses[0].name}</span>}
          </h1>
          <p className="mt-1 text-sm text-secondary">{t('absentMembers.description')}</p>
        </div>
      </div>

      <AttendanceFilter
        values={filterValues}
        onChange={setFilterValues}
        onApply={fetchAbsentMembers}
        showClass={!isServant}
        showContext={true}
        showDate={true}
        showRange={true}
        loading={loading}
      />

      {loading && <LoadingSpinner className="py-8" />}

      {result && !loading && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <StatCard
              title={t('absentMembers.totalMembers')}
              value={result.summary.total_members}
              icon={<Users className="h-5 w-5" />}
            />
            <StatCard
              title={t('attendance.present')}
              value={result.summary.present_count}
              icon={<UserCheck className="h-5 w-5" />}
            />
            <StatCard
              title={t('absentMembers.absentCount')}
              value={result.summary.absent_count}
              icon={<UserX className="h-5 w-5" />}
            />
          </div>

          {result.summary.absent_count > 0 && (
            <div className="card p-4">
              <div className="flex items-center gap-2 mb-4">
                <AlertTriangle className="h-5 w-5 text-warning-500" />
                <h2 className="text-lg font-semibold">{t('absentMembers.absentList')} ({result.summary.absent_count})</h2>
              </div>
              {/* Desktop table */}
              <div className="hidden sm:block overflow-x-auto">
                <table className="w-full min-w-[900px] text-sm">
                  <thead>
                    <tr className="border-b border-border text-left text-xs font-medium text-secondary uppercase tracking-wider">
                      <th className="px-4 py-3">{t('auth.name')}</th>
                      <th className="px-4 py-3">{t('auth.phone')}</th>
                      <th className="px-4 py-3">{t('users.class')}</th>
                      <th className="px-4 py-3">{t('absentMembers.lastAttendance')}</th>
                      <th className="px-4 py-3">{t('absentMembers.attendanceRate')}</th>
                      <th className="px-4 py-3">{t('absentMembers.consecutiveAbsences')}</th>
                      <th className="px-4 py-3">{t('absentMembers.monthAbsences')}</th>
                      <th className="px-4 py-3">{t('absentMembers.followUp')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {absentMembers.map((member) => (
                      <tr key={member.id} className="hover:bg-surface-secondary transition-colors">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/30 text-xs font-bold text-primary-700 dark:text-primary-300">
                              {member.name?.charAt(0)?.toUpperCase() ?? '?'}
                            </div>
                            <span className="font-medium">{member.name}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-secondary">{member.phone ?? '—'}</td>
                        <td className="px-4 py-3">{member.classe?.name ?? '—'}</td>
                        <td className="px-4 py-3 text-secondary">
                          {member.last_attendance_date
                            ? new Date(member.last_attendance_date).toLocaleDateString()
                            : <span className="text-danger-500">{t('absentMembers.never')}</span>}
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <div className="h-1.5 w-16 rounded-full bg-surface-secondary overflow-hidden">
                              <div
                                className={`h-full rounded-full transition-all ${
                                  member.attendance_percentage >= 75 ? 'bg-success-500'
                                    : member.attendance_percentage >= 50 ? 'bg-warning-500'
                                      : 'bg-danger-500'
                                }`}
                                style={{ width: `${Math.min(member.attendance_percentage, 100)}%` }}
                              />
                            </div>
                            <span className={`text-xs font-medium ${
                              member.attendance_percentage >= 75 ? 'text-success-600'
                                : member.attendance_percentage >= 50 ? 'text-warning-600'
                                  : 'text-danger-600'
                            }`}>
                              {member.attendance_percentage}%
                            </span>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={member.consecutive_absences >= 3 ? 'danger' : member.consecutive_absences >= 1 ? 'warning' : 'success'}>
                            {member.consecutive_absences}x
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-secondary">{member.month_absences}</td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1">
                            {member.phone && (
                              <>
                                <button onClick={() => handleCall(member.phone!)}
                                  className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-primary-100 hover:text-primary-600"
                                  title={t('absentMembers.call')}>
                                  <Phone className="h-4 w-4" />
                                </button>
                                <button onClick={() => handleCopyPhone(member.phone!)}
                                  className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-surface-secondary"
                                  title={t('common.copy')}>
                                  <Copy className="h-4 w-4" />
                                </button>
                                <button onClick={() => handleWhatsApp(member.phone!)}
                                  className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-emerald-100 hover:text-emerald-600"
                                  title="WhatsApp">
                                  <MessageCircle className="h-4 w-4" />
                                </button>
                                <button onClick={() => handleSms(member.phone!)}
                                  className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-blue-100 hover:text-blue-600"
                                  title="SMS">
                                  <MessageSquare className="h-4 w-4" />
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Mobile card view */}
              <div className="sm:hidden space-y-3">
                {absentMembers.map((member) => (
                  <div key={member.id} className="rounded-xl border border-border bg-surface p-4 space-y-2">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2 min-w-0">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/30 text-xs font-bold text-primary-700 dark:text-primary-300">
                          {member.name?.charAt(0)?.toUpperCase() ?? '?'}
                        </div>
                        <span className="font-medium truncate">{member.name}</span>
                      </div>
                      <Badge variant={member.consecutive_absences >= 3 ? 'danger' : member.consecutive_absences >= 1 ? 'warning' : 'success'}>
                        {member.consecutive_absences}x
                      </Badge>
                    </div>
                    <div className="grid grid-cols-2 gap-1 text-xs text-secondary">
                      <span>{t('auth.phone')}: {member.phone ?? '—'}</span>
                      <span>{t('users.class')}: {member.classe?.name ?? '—'}</span>
                      <span>{t('absentMembers.lastAttendance')}: {member.last_attendance_date ? new Date(member.last_attendance_date).toLocaleDateString() : t('absentMembers.never')}</span>
                      <span>{t('absentMembers.attendanceRate')}: {member.attendance_percentage}%</span>
                      <span>{t('absentMembers.monthAbsences')}: {member.month_absences}</span>
                    </div>
                    {member.phone && (
                      <div className="flex items-center gap-1 pt-1 border-t border-border">
                        <button onClick={() => handleCall(member.phone!)}
                          className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-primary-100 hover:text-primary-600" title={t('absentMembers.call')}>
                          <Phone className="h-4 w-4" />
                        </button>
                        <button onClick={() => handleCopyPhone(member.phone!)}
                          className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-surface-secondary" title={t('common.copy')}>
                          <Copy className="h-4 w-4" />
                        </button>
                        <button onClick={() => handleWhatsApp(member.phone!)}
                          className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-emerald-100 hover:text-emerald-600" title="WhatsApp">
                          <MessageCircle className="h-4 w-4" />
                        </button>
                        <button onClick={() => handleSms(member.phone!)}
                          className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-blue-100 hover:text-blue-600" title="SMS">
                          <MessageSquare className="h-4 w-4" />
                        </button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {result.summary.absent_count === 0 && loaded && (
            <div className="card p-8 text-center">
              <UserCheck className="h-12 w-12 mx-auto text-success-500 mb-3" />
              <p className="text-lg font-medium text-success-600">{t('absentMembers.allPresent')}</p>
              <p className="text-sm text-secondary mt-1">{t('absentMembers.noAbsentMessage')}</p>
            </div>
          )}
        </>
      )}

      {!result && !loading && (
        <div className="card p-8 text-center">
          <Users className="h-12 w-12 mx-auto text-muted mb-3" />
          <p className="text-lg font-medium text-secondary">{t('absentMembers.selectFilterPrompt')}</p>
          <p className="text-sm text-muted mt-1">{t('absentMembers.selectFilterDescription')}</p>
        </div>
      )}
    </div>
  )
}