import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  ArrowRight,
  Fingerprint,
  Calendar,
  Phone,
  MapPin,
  GraduationCap,
  Award,
  Clock,
  UserCheck,
  QrCode,
  Mail,
  MessageCircle,
  User as UserIcon,
  Sparkles,
  Church,
  BookOpen,
  TrendingUp,
} from 'lucide-react'
import Badge from '@/components/common/Badge'
import CopyButton from '@/components/common/CopyButton'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import StatCard from '@/components/common/StatCard'
import { useTheme } from '@/hooks/useTheme'
import type { User, MemberProfile, MemberProfileAttendance, MemberProfileSpiritual } from '@/types'
import { getMemberProfile } from '@/api/users'
import { getUserBalance } from '@/api/points'
import { logCatch } from '@/lib/debug'
import QRCodeLib from 'qrcode'

function InfoRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="flex items-start gap-3 py-2.5 border-b border-border last:border-b-0">
      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-muted">
        {icon}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium text-muted uppercase tracking-wide">{label}</p>
        <div className="mt-0.5 text-sm font-medium text-secondary break-words">{value}</div>
      </div>
    </div>
  )
}

function SectionCard({ title, icon, children }: { title: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="card overflow-hidden">
      <div className="border-b border-border bg-surface-secondary/50 px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
            {icon}
          </span>
          <h3 className="text-sm font-semibold text-secondary">{title}</h3>
        </div>
      </div>
      <div className="px-5 py-2">{children}</div>
    </div>
  )
}

function AttendanceSummaryCard({ attendance, t }: { attendance: MemberProfileAttendance; t: (key: string) => string }) {
  return (
    <SectionCard title={t('memberProfile.attendanceSummary')} icon={<TrendingUp className="h-4 w-4" />}>
      <div className="grid grid-cols-2 gap-3 py-2">
        <div className="rounded-xl bg-info-50 dark:bg-info-900/20 p-3 text-center">
          <p className="text-2xl font-bold text-info-600 dark:text-info-400">{attendance.total_attended}</p>
          <p className="text-xs font-medium text-info-700 dark:text-info-300 mt-0.5">{t('memberProfile.attended')}</p>
        </div>
        <div className="rounded-xl bg-danger-50 dark:bg-danger-900/20 p-3 text-center">
          <p className="text-2xl font-bold text-danger-600 dark:text-danger-400">{attendance.absences}</p>
          <p className="text-xs font-medium text-danger-700 dark:text-danger-300 mt-0.5">{t('memberProfile.absences')}</p>
        </div>
        <div className="rounded-xl bg-success-50 dark:bg-success-900/20 p-3 text-center">
          <p className="text-2xl font-bold text-success-600 dark:text-success-400">{attendance.percentage}%</p>
          <p className="text-xs font-medium text-success-700 dark:text-success-300 mt-0.5">{t('memberProfile.attendancePercentage')}</p>
        </div>
        <div className="rounded-xl bg-gold-50 dark:bg-gold-900/20 p-3 text-center">
          <p className="text-2xl font-bold text-gold-600 dark:text-gold-400">{attendance.this_month}</p>
          <p className="text-xs font-medium text-gold-700 dark:text-gold-300 mt-0.5">{t('memberProfile.thisMonth')}</p>
        </div>
      </div>
      <p className="text-xs text-muted text-center mt-1">
        {t('memberProfile.totalSessions')}: {attendance.total_sessions} | {t('memberProfile.thisMonthSessions')}: {attendance.this_month_total}
      </p>
    </SectionCard>
  )
}

function SpiritualSummaryCard({ spiritual, t }: { spiritual: MemberProfileSpiritual; t: (key: string) => string }) {
  return (
    <SectionCard title={t('memberProfile.spiritualSummary')} icon={<Sparkles className="h-4 w-4" />}>
      <div className="grid grid-cols-3 gap-3 py-2">
        <div className="rounded-xl bg-success-50 dark:bg-success-900/20 p-3 text-center">
          <div className="flex justify-center mb-1">
            <Church className="h-5 w-5 text-success-600 dark:text-success-400" />
          </div>
          <p className="text-2xl font-bold text-success-600 dark:text-success-400">{spiritual.mass_count}</p>
          <p className="text-xs font-medium text-success-700 dark:text-success-300 mt-0.5">{t('spiritualLog.mass')}</p>
        </div>
        <div className="rounded-xl bg-info-50 dark:bg-info-900/20 p-3 text-center">
          <div className="flex justify-center mb-1">
            <BookOpen className="h-5 w-5 text-info-600 dark:text-info-400" />
          </div>
          <p className="text-2xl font-bold text-info-600 dark:text-info-400">{spiritual.confession_count}</p>
          <p className="text-xs font-medium text-info-700 dark:text-info-300 mt-0.5">{t('spiritualLog.confession')}</p>
        </div>
        <div className="rounded-xl bg-gold-50 dark:bg-gold-900/20 p-3 text-center">
          <div className="flex justify-center mb-1">
            <Sparkles className="h-5 w-5 text-gold-500" />
          </div>
          <p className="text-2xl font-bold text-gold-600 dark:text-gold-400">{spiritual.communion_count}</p>
          <p className="text-xs font-medium text-gold-700 dark:text-gold-300 mt-0.5">{t('spiritualLog.communion')}</p>
        </div>
      </div>
      <div className="flex items-center justify-between text-xs text-muted mt-2 px-1">
        <span>{t('memberProfile.thisMonthMass')}: {spiritual.this_month_mass}</span>
        <span>{t('memberProfile.thisMonthConfession')}: {spiritual.this_month_confession}</span>
        <span>{t('memberProfile.thisMonthCommunion')}: {spiritual.this_month_communion}</span>
      </div>
    </SectionCard>
  )
}

export default function ServantMemberDetail() {
  const { t } = useTranslation()
  const { dir } = useTheme()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [member, setMember] = useState<User | null>(null)
  const [profile, setProfile] = useState<MemberProfile | null>(null)
  const [balance, setBalance] = useState(0)
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!id) return
    const userId = Number(id)
    Promise.all([
      getMemberProfile(userId).catch((e) => { logCatch('MemberDetail.getMemberProfile', e); return null }),
      getUserBalance(userId).catch((e) => { logCatch('MemberDetail.getBalance', e); return 0 }),
    ])
      .then(([mp, b]) => {
        if (mp) {
          setMember(mp.member)
          setProfile(mp)
          if (mp.member.attendance_qr_token) {
            QRCodeLib.toDataURL(mp.member.attendance_qr_token, { width: 300, margin: 2 })
              .then(setQrDataUrl)
              .catch((e) => logCatch('MemberDetail.qrCode', e))
          }
        }
        setBalance(b)
      })
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <LoadingSpinner className="py-20" />
  if (!member) return <p className="py-12 text-center text-muted">{t('users.notFound')}</p>

  return (
    <div className="max-w-4xl mx-auto space-y-6 pb-10">
      <button
        onClick={() => navigate('/servant/members')}
        className="inline-flex items-center gap-1.5 text-sm font-medium text-muted hover:text-secondary transition-colors"
      >
        {dir === 'rtl' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
        {t('common.back')}
      </button>

      {/* Profile header */}
      <div className="card overflow-hidden">
        <div className="bg-gradient-to-r from-primary-600 to-primary-800 px-6 py-8 sm:px-8">
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5">
            {member.avatar ? (
              <ImageWithFallback src={member.avatar} alt={member.name} className="h-20 w-20 shrink-0 rounded-full object-cover ring-4 ring-white/30" />
            ) : (
              <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-white/20 text-3xl font-bold text-white ring-4 ring-white/30">
                {member.name.charAt(0).toUpperCase()}
              </div>
            )}
            <div className="min-w-0 flex-1 text-white">
              <h1 className="text-2xl font-bold truncate">{member.name}</h1>
              <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/80">
                {member.member_id && (
                  <span className="flex items-center gap-1.5 font-mono">
                    <Fingerprint className="h-3.5 w-3.5" /> {member.member_id}
                    <CopyButton value={member.member_id} iconSize={12} />
                  </span>
                )}
                {member.classe && (
                    <span className="inline-flex items-center gap-1 text-sm text-secondary">
                      <GraduationCap className="h-3.5 w-3.5" /> {member.classe.name}
                    </span>
                  )}
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Badge variant="info">{t('users.roleMember')}</Badge>
                <Badge variant={member.is_active ? 'success' : 'danger'}>
                  {member.is_active ? t('common.active') : t('common.inactive')}
                </Badge>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Stats row */}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard title={t('attendance.attendanceHistory')} value={profile?.attendance.total_attended ?? 0} color="info" />
        <StatCard title={t('common.thisMonth')} value={profile?.attendance.this_month ?? 0} color="gold" />
        <StatCard title={t('dashboard.myPoints')} value={balance} color="primary" />
      </div>

      {/* QR Code */}
      {qrDataUrl && (
        <div className="card overflow-hidden">
          <div className="border-b border-border bg-surface-secondary/50 px-5 py-3.5">
            <div className="flex items-center gap-2.5">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
                <QrCode className="h-4 w-4" />
              </span>
              <h3 className="text-sm font-semibold text-secondary">{t('qr.attendanceQR')}</h3>
            </div>
          </div>
          <div className="flex justify-center p-5">
            <img src={qrDataUrl} alt="QR Code" className="h-48 w-48 max-w-full" />
          </div>
        </div>
      )}

      {/* Attendance + Spiritual Summary */}
      {profile && (
        <div className="grid gap-6 md:grid-cols-2">
          <AttendanceSummaryCard attendance={profile.attendance} t={t} />
          <SpiritualSummaryCard spiritual={profile.spiritual} t={t} />
        </div>
      )}

      {/* Details grid */}
      <div className="grid gap-6 md:grid-cols-2">
        <SectionCard title={t('auth.name')} icon={<UserCheck className="h-4 w-4" />}>
          {member.member_id && (
            <InfoRow icon={<Fingerprint className="h-4 w-4" />} label={t('users.memberIdLabel')} value={
              <span className="inline-flex items-center gap-1">
                {member.member_id}
                <CopyButton value={member.member_id} iconSize={12} />
              </span>
            } />
          )}
          <InfoRow icon={<Calendar className="h-4 w-4" />} label={t('auth.birthday')} value={member.birthday ?? '-'} />
          <InfoRow icon={<UserIcon className="h-4 w-4" />} label={t('users.age')} value={member.age !== null ? t('users.ageFormat', { age: member.age }) : '-'} />
          {member.created_at && (
            <InfoRow icon={<Clock className="h-4 w-4" />} label={t('common.createdAt')} value={member.created_at ? new Date(member.created_at).toLocaleDateString() : '-'} />
          )}
        </SectionCard>

        <SectionCard title={t('auth.email')} icon={<Mail className="h-4 w-4" />}>
          <InfoRow icon={<Mail className="h-4 w-4" />} label={t('auth.email')} value={member.email} />
          <InfoRow icon={<Phone className="h-4 w-4" />} label={t('auth.phone')} value={
            member.phone ? (
              <span className="inline-flex items-center gap-2">
                <span>{member.phone}</span>
                <a
                  href={`https://wa.me/${member.phone.replace(/[^0-9]/g, '')}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-xs font-medium text-success-dark hover:underline"
                >
                  <MessageCircle className="h-3.5 w-3.5" />
                  {t('common.whatsapp')}
                </a>
              </span>
            ) : '-'
          } />
          <InfoRow icon={<MapPin className="h-4 w-4" />} label={t('common.address')} value={member.address ?? '-'} />
          {member.member_address && (
            <InfoRow icon={<MapPin className="h-4 w-4" />} label={t('auth.memberAddress')} value={member.member_address} />
          )}
        </SectionCard>

        <SectionCard title={t('users.class')} icon={<GraduationCap className="h-4 w-4" />}>
          <InfoRow icon={<GraduationCap className="h-4 w-4" />} label={t('users.class')} value={member.classe ? member.classe.name : '-'} />
          <InfoRow icon={<Award className="h-4 w-4" />} label={t('common.points')} value={String(balance)} />
        </SectionCard>
      </div>
    </div>
  )
}
