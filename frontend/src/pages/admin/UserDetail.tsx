import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import {
  ArrowLeft,
  ArrowRight,
  Shield,
  ShieldOff,
  Trash2,
  ToggleLeft,
  ToggleRight,
  Mail,
  Phone,
  Calendar,
  MapPin,
  Award,
  Clock,
  UserCheck,
  GraduationCap,
  BookOpen,
  BadgeInfo,
  Fingerprint,
  QrCode,
  MessageCircle,
  User as UserIcon,
} from 'lucide-react'
import Badge from '@/components/common/Badge'
import CopyButton from '@/components/common/CopyButton'
import ImageWithFallback from '@/components/common/ImageWithFallback'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import StatCard from '@/components/common/StatCard'
import { useTheme } from '@/contexts/ThemeContext'
import type { User } from '@/types'
import { getUser, updateUser, deleteUser, promoteToAdmin, demoteFromAdmin } from '@/api/users'
import { getAttendanceStats } from '@/api/attendance'
import { getUserBalance, addBonusPoints } from '@/api/points'
import { roleBadgeVariant, roleTranslationKey } from '@/lib/roles'
import QRCodeLib from 'qrcode'

function InfoRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: React.ReactNode | string | null | undefined }) {
  if (!value) return null
  return (
    <div className="flex items-start gap-3 py-2.5 border-b border-border last:border-b-0">
      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-muted">
        {icon}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium text-muted uppercase tracking-wide">{label}</p>
        <p className="mt-0.5 text-sm font-medium text-secondary break-words">{value}</p>
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
      <div className="px-5 py-2">
        {children}
      </div>
    </div>
  )
}

export default function AdminUserDetail() {
  const { t } = useTranslation()
  const { dir } = useTheme()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [user, setUser] = useState<User | null>(null)
  const [stats, setStats] = useState({ total_attendances: 0, this_month: 0 })
  const [balance, setBalance] = useState(0)
  const [loading, setLoading] = useState(true)
  const [showDemote, setShowDemote] = useState(false)
  const [demoteRole, setDemoteRole] = useState<'servant' | 'member'>('servant')
  const [error, setError] = useState('')
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)
  const [showBonusModal, setShowBonusModal] = useState(false)
  const [bonusPoints, setBonusPoints] = useState<number | ''>('')
  const [bonusReason, setBonusReason] = useState('')
  const [awardingBonus, setAwardingBonus] = useState(false)
  const [bonusError, setBonusError] = useState('')

  useEffect(() => {
    if (!id) return
    const userId = Number(id)
    setLoading(true)
    Promise.all([
      getUser(userId).catch(() => null),
      getAttendanceStats(userId).catch(() => ({ total_attendances: 0, this_month: 0 })),
      getUserBalance(userId).catch(() => 0),
    ]).then(([u, s, b]) => {
      if (u) {
        setUser(u)
        if (u.attendance_qr_token) {
          QRCodeLib.toDataURL(u.attendance_qr_token, { width: 300, margin: 2 }).then(setQrDataUrl).catch(() => {})
        }
      }
      if (s) setStats(s)
      if (b !== undefined) setBalance(b)
    }).finally(() => setLoading(false))
  }, [id])

  if (loading) return <LoadingSpinner className="py-20" />
  if (!user) return <p className="py-12 text-center text-muted">{t('users.notFound')}</p>

  const isAdmin = user.role === 'admin' || user.role === 'platform_admin'

  const handleToggleActive = async () => {
    await updateUser(user.id, { is_active: !user.is_active })
    setUser({ ...user, is_active: !user.is_active })
    toast.success(user.is_active ? t('users.deactivated') : t('users.activated'))
  }

  const handleDelete = async () => {
    if (window.confirm(t('users.deleteConfirm', { name: user.name }))) {
      await deleteUser(user.id)
      toast.success(t('users.deleted'))
      navigate('/admin/users')
    }
  }

  const handleAddBonus = async () => {
    setBonusError('')
    if (!bonusPoints || Number(bonusPoints) < 1) {
      setBonusError(t('points.bonusMinError', 'Points must be at least 1.'))
      return
    }
    if (!window.confirm(t('points.bonusConfirm', { points: bonusPoints, name: user.name }))) return
    setAwardingBonus(true)
    try {
      const result = await addBonusPoints({
        user_id: user.id,
        points: Number(bonusPoints),
        reason: bonusReason || undefined,
      })
      setBalance(result.balance)
      setShowBonusModal(false)
      setBonusPoints('')
      setBonusReason('')
      toast.success(t('points.bonusAwarded', { points: bonusPoints, name: user.name }))
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      setBonusError(Object.values(axiosErr?.response?.data?.errors || {}).flat().join(', ') || axiosErr?.response?.data?.message || t('common.saving'))
    } finally { setAwardingBonus(false) }
  }

  const handlePromote = async () => {
    setError('')
    try {
      const updated = await promoteToAdmin(user.id)
      setUser(updated)
      toast.success(t('users.promoted'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors
      setError(msg ? Object.values(msg).flat().join(', ') : t('users.promoteFailed'))
    }
  }

  const handleDemote = async () => {
    setError('')
    try {
      const updated = await demoteFromAdmin(user.id, demoteRole)
      setUser(updated)
      setShowDemote(false)
      toast.success(t('users.demoted'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors
      setError(msg ? Object.values(msg).flat().join(', ') : t('users.demoteFailed'))
    }
  }

  return (
    <div className="max-w-4xl mx-auto space-y-6 pb-10">
      {/* Back button */}
      <button onClick={() => navigate('/admin/users')} className="inline-flex items-center gap-1.5 text-sm font-medium text-muted hover:text-secondary transition-colors">
        {dir === 'rtl' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
        {t('common.back')}
      </button>

      {/* Profile header card */}
      <div className="card overflow-hidden">
        <div className="bg-gradient-to-r from-primary-600 to-primary-800 px-6 py-8 sm:px-8">
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5">
            {user.avatar ? (
              <ImageWithFallback src={user.avatar} alt={user.name} className="h-20 w-20 shrink-0 rounded-full object-cover ring-4 ring-white/30" />
            ) : (
              <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-white/20 text-3xl font-bold text-white ring-4 ring-white/30">
                {user.name.charAt(0).toUpperCase()}
              </div>
            )}
            <div className="min-w-0 flex-1 text-white">
              <h1 className="text-2xl font-bold truncate">{user.name}</h1>
              <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/80">
                {user.email && <span className="flex items-center gap-1.5"><Mail className="h-3.5 w-3.5" /> {user.email}</span>}
                {user.phone && <span className="flex items-center gap-1.5"><Phone className="h-3.5 w-3.5" /> {user.phone}</span>}
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Badge variant={roleBadgeVariant(user.role)}>{t(roleTranslationKey(user.role))}</Badge>
                <Badge variant={user.is_active ? 'success' : 'danger'}>
                  {user.is_active ? t('common.active') : t('common.inactive')}
                </Badge>
              </div>
            </div>
            <div className="flex shrink-0 flex-wrap gap-2">
              {isAdmin ? (
                <button onClick={() => { setDemoteRole('servant'); setShowDemote(true) }} className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2 text-xs font-semibold text-white hover:bg-white/25 transition-colors">
                  <ShieldOff className="h-3.5 w-3.5" /> {t('users.demote')}
                </button>
              ) : (
                <button onClick={handlePromote} className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2 text-xs font-semibold text-white hover:bg-white/25 transition-colors">
                  <Shield className="h-3.5 w-3.5" /> {t('users.promoteToAdmin')}
                </button>
              )}
              <button onClick={handleToggleActive} className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3.5 py-2 text-xs font-semibold text-white hover:bg-white/25 transition-colors">
                {user.is_active ? <ToggleRight className="h-3.5 w-3.5" /> : <ToggleLeft className="h-3.5 w-3.5" />}
                {user.is_active ? t('users.deactivate') : t('users.activate')}
              </button>
              <button onClick={handleDelete} className="inline-flex items-center gap-1.5 rounded-lg bg-danger/30 px-3.5 py-2 text-xs font-semibold text-white hover:bg-danger/50 transition-colors">
                <Trash2 className="h-3.5 w-3.5" /> {t('common.delete')}
              </button>
            </div>
          </div>
        </div>
      </div>

      {error && (
        <div className="rounded-xl border border-danger/30 bg-danger-light px-5 py-3.5 text-sm text-danger-dark">
          {error}
        </div>
      )}

      {/* Stats row */}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard title={t('attendance.attendanceHistory')} value={stats.total_attendances} color="info" />
        <StatCard title={t('common.thisMonth')} value={stats.this_month} color="gold" />
        <StatCard title={t('dashboard.myPoints')} value={balance} color="primary" />
      </div>

      <div className="flex flex-wrap gap-2">
        <button onClick={() => { setBonusPoints(''); setBonusReason(''); setBonusError(''); setShowBonusModal(true) }}
          className="btn-primary btn-md">
          <Award className="h-4 w-4" /> {t('points.addBonus')}
        </button>
      </div>

      {/* QR Code card — only shown if user has an attendance QR token */}
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

      {/* Details grid: 2 columns on desktop, 1 on mobile */}
      <div className="grid gap-6 md:grid-cols-2">
        {/* Personal Information */}
        <SectionCard title={t('auth.name')} icon={<UserCheck className="h-4 w-4" />}>
          {user.member_id && (
            <InfoRow icon={<Fingerprint className="h-4 w-4" />} label={t('users.memberIdLabel')} value={
              <span className="inline-flex items-center gap-1">
                {user.member_id}
                <CopyButton value={user.member_id} iconSize={12} />
              </span>
            } />
          )}
          <InfoRow icon={<Calendar className="h-4 w-4" />} label={t('auth.birthday')} value={user.birthday ?? '-'} />
          <InfoRow icon={<UserIcon className="h-4 w-4" />} label={t('users.age')} value={user.age !== null ? t('users.ageFormat', { age: user.age }) : '-'} />
          {user.created_at && (
            <InfoRow icon={<Clock className="h-4 w-4" />} label={t('common.createdAt')} value={user.created_at ? new Date(user.created_at).toLocaleDateString() : '-'} />
          )}
        </SectionCard>

        {/* Contact Information */}
        <SectionCard title={t('auth.email')} icon={<Mail className="h-4 w-4" />}>
          <InfoRow icon={<Mail className="h-4 w-4" />} label={t('auth.email')} value={user.email} />
          <InfoRow icon={<Phone className="h-4 w-4" />} label={t('auth.phone')} value={
            user.phone ? (
              <span className="inline-flex items-center gap-2">
                <span>{user.phone}</span>
                <a
                  href={`https://wa.me/${user.phone.replace(/[^0-9]/g, '')}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400 hover:underline"
                >
                  <MessageCircle className="h-3.5 w-3.5" />
                  {t('common.whatsapp')}
                </a>
              </span>
            ) : '-'
          } />
        </SectionCard>

        {/* Address Information */}
        <SectionCard title={t('common.address')} icon={<MapPin className="h-4 w-4" />}>
          <InfoRow icon={<MapPin className="h-4 w-4" />} label={t('common.address')} value={user.address} />
          {user.member_address && (
            <InfoRow icon={<MapPin className="h-4 w-4" />} label={t('auth.memberAddress')} value={user.member_address} />
          )}
        </SectionCard>

        {/* Role & Class Information */}
        <SectionCard title={t('users.role')} icon={<BadgeInfo className="h-4 w-4" />}>
          <InfoRow
            icon={<Award className="h-4 w-4" />}
            label={t('users.role')}
            value={t(roleTranslationKey(user.role))}
          />
          <InfoRow
            icon={<GraduationCap className="h-4 w-4" />}
            label={t('users.class')}
            value={user.classe ? user.classe.name : '-'}
          />
          <InfoRow
            icon={<BookOpen className="h-4 w-4" />}
            label={t('structure.stages')}
            value={user.classe?.stage?.name ?? user.stage?.name ?? '-'}
          />
          {user.servant && (
            <InfoRow
              icon={<BookOpen className="h-4 w-4" />}
              label={t('dashboard.yourServant')}
              value={user.servant.name}
            />
          )}
        </SectionCard>
      </div>

      <Modal isOpen={showBonusModal} onClose={() => setShowBonusModal(false)} title={t('points.addBonusTitle')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowBonusModal(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleAddBonus} disabled={awardingBonus} className="flex-1 btn-primary btn-md">
              {awardingBonus ? t('common.saving') : t('common.confirm')}
            </button>
          </div>
        }>
        <div className="space-y-4">
          <p className="text-sm text-secondary">{t('points.bonusTarget', { name: user.name })}</p>
          {bonusError && <div className="rounded-lg bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-600 dark:text-red-400">{bonusError}</div>}
          <div>
            <label className="label">{t('points.points')}</label>
            <input type="number" min="1" max="999999" value={bonusPoints} onChange={(e) => setBonusPoints(e.target.value ? Number(e.target.value) : '')}
              placeholder="10" className="input-field w-full" />
          </div>
          <div>
            <label className="label">{t('points.reason')} <span className="text-muted">({t('common.optional')})</span></label>
            <input type="text" value={bonusReason} onChange={(e) => setBonusReason(e.target.value)}
              placeholder={t('points.reasonPlaceholder')} className="input-field w-full" />
          </div>
        </div>
      </Modal>

      {/* Demote Modal */}
      <Modal isOpen={showDemote} onClose={() => setShowDemote(false)} title={t('users.demoteAdmin')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowDemote(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleDemote} className="flex-1 btn-danger btn-md">{t('common.confirm')}</button>
          </div>
        }>
        <div className="space-y-4">
          <p className="text-sm text-secondary">
            {t('users.selectNewRole', { name: user.name })}
          </p>
          <select value={demoteRole} onChange={(e) => setDemoteRole(e.target.value as 'servant' | 'member')} className="input-field">
            <option value="servant">{t('users.roleServant')}</option>
            <option value="member">{t('users.roleMember')}</option>
          </select>
        </div>
      </Modal>
    </div>
  )
}