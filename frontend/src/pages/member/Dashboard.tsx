import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { BookOpen, CalendarDays, MessageCircle, Phone, Shield, ShieldCheck, User, Church, Sparkles } from 'lucide-react'
import StatCard from '@/components/common/StatCard'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import MotionDiv from '@/components/common/MotionDiv'
import DailyVerseBanner from '@/components/common/DailyVerseBanner'
import { useAuth } from '@/contexts/AuthContext'
import { getAttendanceStats, getAttendanceHistory } from '@/api/attendance'
import { getBalance } from '@/api/points'
import { getMyClassServants } from '@/api/structure'
import type { ClassContact } from '@/api/structure'
import QRCodeLib from 'qrcode'
import { listEvents } from '@/api/events'
import type { Attendance, Event } from '@/types'

export default function MemberDashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [balance, setBalance] = useState(0)
  const [recentAttendances, setRecentAttendances] = useState<Attendance[]>([])
  const [upcomingEvents, setUpcomingEvents] = useState<Event[]>([])
  const [classContacts, setClassContacts] = useState<ClassContact[]>([])
  const [loading, setLoading] = useState(true)
  const [tokenCopied, setTokenCopied] = useState(false)
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)

  useEffect(() => {
    Promise.all([
      getAttendanceStats().catch(() => ({ total_attendances: 0, this_month: 0 })),
      getBalance().catch(() => 0),
      getAttendanceHistory(undefined, { page: 1, per_page: 5 }).catch(() => ({ data: [], meta: null })),
      listEvents({ upcoming: true, active_only: true, per_page: 3 }).catch(() => ({ data: [] })),
      getMyClassServants().then(setClassContacts).catch(() => setClassContacts([])),
    ]).then(([s, b, att, ev]) => { setStats(s); setBalance(b); setRecentAttendances(att.data); setUpcomingEvents(ev.data) }).finally(() => setLoading(false))

    if (user?.attendance_qr_token) {
      QRCodeLib.toDataURL(user.attendance_qr_token, { width: 400, margin: 2 }).then(setQrDataUrl).catch(() => {})
    }
  }, [])

  if (loading) return <LoadingSpinner className="py-20" />

  const handleCopyToken = () => {
    if (user?.attendance_qr_token) {
      navigator.clipboard.writeText(user.attendance_qr_token)
      setTokenCopied(true)
      setTimeout(() => setTokenCopied(false), 2000)
    }
  }

  return (
    <div className="space-y-6">
      <MotionDiv animation="fade-in-up">
        <DailyVerseBanner />
      </MotionDiv>

      {/* Welcome Card */}
      <MotionDiv animation="fade-in-up" delay={50}>
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 p-6 text-white">
          <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,rgba(212,175,55,0.08)_0%,transparent_60%)]" />
          <div className="relative flex items-center gap-4">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl gold-gradient shadow-xl">
              <Church className="h-7 w-7 text-navy-900" />
            </div>
            <div>
              <h2 className="text-xl font-bold gold-text">{t('dashboard.welcome')}, {user?.name}</h2>
              <p className="mt-1 text-sm text-white/60">{user?.classe ? `${user.classe.name} · ` : ''}{t('dashboard.memberDashboard')}</p>
            </div>
          </div>
        </div>
      </MotionDiv>

      {/* Contact Cards */}
      {classContacts.length > 0 && (
        <MotionDiv animation="fade-in-up" delay={80}>
          <div className="glass-card-solid p-5">
            <h3 className="mb-3 text-sm font-semibold text-secondary flex items-center gap-2">
              <Sparkles className="h-4 w-4 gold-text" />
              {t('dashboard.classServantsAdmin')} ({classContacts.length})
            </h3>
            <div className="space-y-3">
              {classContacts.map((contact, i) => {
  const roleIcon = contact.type === 'admin' ? <ShieldCheck className="h-4 w-4 gold-text" />
    : contact.type === 'assistant_admin' ? <Shield className="h-4 w-4 text-gold-400" />
    : <User className="h-4 w-4 text-gold-400" />

                const roleBadgeClass = contact.type === 'admin' ? 'gold-gradient text-navy-900'
    : contact.type === 'assistant_admin' ? 'bg-gold-100 text-navy-800 dark:bg-gold-900/30 dark:text-gold-400'
    : 'bg-gold-100 text-gold-600 dark:bg-gold-900/30 dark:text-gold-400'

                return (
                  <MotionDiv key={contact.id} animation="fade-in-up" delay={i * 50}>
                    <div className="flex items-center justify-between rounded-xl border border-border p-3 hover:border-gold-300/50 transition-all bg-surface/50 backdrop-blur-sm">
                      <div className="flex items-center gap-3 min-w-0">
                          {contact.avatar ? (
                          <img src={contact.avatar} alt="" loading="lazy" className="h-11 w-11 shrink-0 rounded-full object-cover ring-2 ring-gold-400/20" />
                        ) : (
                          <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold ${roleBadgeClass}`}>
                            {contact.name.charAt(0).toUpperCase()}
                          </div>
                        )}
                        <div className="min-w-0">
                          <p className="font-medium truncate flex items-center gap-1.5">{contact.name} {roleIcon}</p>
                          <span className={`inline-block mt-0.5 text-[11px] font-medium px-2 py-0.5 rounded-full ${roleBadgeClass}`}>{contact.role_label}</span>
                          {contact.phone && <p className="text-sm text-secondary truncate mt-0.5">{contact.phone}</p>}
                        </div>
                      </div>
                      {contact.phone && (
                        <div className="flex items-center gap-2 shrink-0">
                          <a href={`tel:${contact.phone}`} className="btn-icon btn-ghost rounded-lg p-2 hover:bg-gold-100 hover:text-gold-600" title={t('absentMembers.call')}>
                            <Phone className="h-4 w-4" />
                          </a>
                          <a href={`https://wa.me/${contact.phone.replace(/[^0-9]/g, '')}`} target="_blank" rel="noopener noreferrer" className="btn-icon btn-ghost rounded-lg p-2 hover:bg-emerald-100 hover:text-emerald-600" title={t('common.whatsapp')}>
                            <MessageCircle className="h-4 w-4" />
                          </a>
                        </div>
                      )}
                    </div>
                  </MotionDiv>
                )
              })}
            </div>
          </div>
        </MotionDiv>
      )}

      {/* Class Info */}
      {user?.classe && (
        <MotionDiv animation="fade-in-up" delay={100}>
          <div className="relative overflow-hidden rounded-2xl border border-gold-400/20 bg-gradient-to-br from-gold-50/50 to-gold-100/30 p-5 dark:from-gold-900/10 dark:to-gold-900/5">
            <div className="absolute top-0 right-0 w-24 h-24 bg-gold-400/10 rounded-full blur-2xl" />
            <div className="relative">
              <p className="text-xs font-semibold uppercase tracking-wider gold-text">{t('users.class')}</p>
              <p className="mt-1 text-xl font-bold gold-text">{user.classe.name}</p>
            </div>
          </div>
        </MotionDiv>
      )}

      {/* Stats Grid */}
      <MotionDiv animation="fade-in-up" delay={120}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 stagger-children">
          <StatCard title={t('dashboard.myPoints')} value={balance} icon={<Sparkles className="h-5 w-5" />} color="gold" />
          <StatCard title={t('dashboard.myAttendances')} value={stats.total_attendances} subtitle={t('common.total')} icon={<CalendarDays className="h-5 w-5" />} color="navy" />
          <StatCard title={t('common.thisMonth')} value={stats.this_month} subtitle={t('attendance.attendance')} icon={<BookOpen className="h-5 w-5" />} color="primary" />
        </div>
      </MotionDiv>

      {/* QR Code */}
      {user?.attendance_qr_token && (
        <MotionDiv animation="fade-in-up" delay={140}>
          <div className="glass-card-solid p-5">
            <div className="flex items-center justify-between mb-2">
              <h3 className="text-sm font-semibold text-secondary">{t('qr.myQRCode')}</h3>
              <Link to="/member/qr" className="text-sm gold-text hover:underline font-medium">{t('common.viewAll')}</Link>
            </div>
            <p className="mb-3 text-xs text-muted">{t('qr.qrDescription')}</p>
            <div className="flex flex-col items-center gap-4">
              {qrDataUrl && <img src={qrDataUrl} alt="QR" className="h-48 w-48 max-w-full rounded-xl shadow-lg" />}
              <div className="flex w-full items-center gap-2">
                <code className="flex-1 truncate rounded-xl bg-surface-tertiary/50 backdrop-blur-sm border border-border px-3 py-2.5 font-mono text-xs text-secondary">
                  {user.attendance_qr_token}
                </code>
                <button onClick={handleCopyToken} className="btn-gold btn-md">
                  {tokenCopied ? t('common.copied') : t('common.copy')}
                </button>
              </div>
            </div>
          </div>
        </MotionDiv>
      )}

      {/* Recent Attendance */}
      {recentAttendances.length > 0 && (
        <MotionDiv animation="fade-in-up" delay={160}>
          <div className="glass-card-solid p-5">
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-lg font-semibold">{t('dashboard.recentAttendance')}</h2>
              <Link to="/member/attendance" className="text-sm gold-text hover:underline font-medium">{t('common.viewAll')}</Link>
            </div>
            <div className="space-y-2">
              {recentAttendances.map((att) => (
                <div key={att.id} className="flex items-center justify-between border-b border-border pb-2 last:border-0">
                  <div>
                    <p className="text-sm font-medium">{att.attended_at ? new Date(att.attended_at).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : '-'}</p>
                    <p className="text-xs text-muted">{t('attendance.recordedBy')} {att.recorder?.name ?? '-'}</p>
                  </div>
                  <span className="text-sm font-semibold gold-text">+{att.points_earned} {t('common.points')}</span>
                </div>
              ))}
            </div>
          </div>
        </MotionDiv>
      )}

      {/* Upcoming Events */}
      <MotionDiv animation="fade-in-up" delay={180}>
        <div className="glass-card-solid p-5">
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <CalendarDays className="h-5 w-5 gold-text" />
              <h2 className="text-lg font-semibold">{t('dashboard.upcomingEvents')}</h2>
            </div>
            <Link to="/member/events" className="text-sm gold-text hover:underline font-medium">{t('common.viewAll')}</Link>
          </div>
          {upcomingEvents.length === 0 ? (
            <p className="text-sm text-muted">{t('dashboard.noUpcomingEvents')}</p>
          ) : (
            <div className="space-y-3">
              {upcomingEvents.map((event) => (
                <div key={event.id} className="flex items-center justify-between border-b border-border pb-2 last:border-0">
                  <div>
                    <p className="font-medium text-sm">{event.name}</p>
                    {event.location && <p className="text-xs text-muted">{event.location}</p>}
                  </div>
                  <span className="text-xs text-secondary">
                    {event.event_date ? new Date(event.event_date).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : t('common.dateTbd') || 'TBD'}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </MotionDiv>
    </div>
  )
}
