import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import {
  Trash2, AlertTriangle, Building2, Users, Calendar, ClipboardList,
  MessageSquare, Target, Eye, Bell, BookOpen, UserPlus, Shield,
  Trophy, Layers, QrCode, FileText, Clock, RotateCcw, RefreshCw,
  Key, User, ShieldAlert,
} from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import Badge from '@/components/common/Badge'
import type { ChurchDeletionSummary } from '@/types'
import client from '@/api/client'
import {
  getDeletionSummary, softDeleteChurch, hardDeleteChurch, restoreChurch,
} from '@/api/churches'

interface ChurchItem {
  id: number
  name: string
  slug: string
  member_count: number
  is_active: boolean
  is_deleted: boolean
  deleted_at?: string
  is_recoverable?: boolean
  days_until_purge?: number | null
  recoverable_until?: string | null
  created_at: string
}

interface DeletedChurchInfo {
  id: number
  name: string
  deleted_at?: string
  is_recoverable?: boolean
  days_until_purge?: number | null
  recoverable_until?: string | null
}

type ModalMode = 'soft-delete' | 'hard-delete' | 'restore' | null

export default function ChurchDeletion() {
  const { t } = useTranslation()
  const [churches, setChurches] = useState<ChurchItem[]>([])
  const [deletedChurches, setDeletedChurches] = useState<DeletedChurchInfo[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [summary, setSummary] = useState<ChurchDeletionSummary | null>(null)
  const [deletedInfo, setDeletedInfo] = useState<DeletedChurchInfo | null>(null)
  const [summaryLoading, setSummaryLoading] = useState(false)
  const [confirmation, setConfirmation] = useState('')
  const [password, setPassword] = useState('')
  const [actionLoading, setActionLoading] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<ModalMode>(null)

  const fetchChurches = async () => {
    setLoading(true)
    try {
      const { data: active } = await client.get('/platform/churches', { params: { _t: Date.now() } })
      const items = active.data || []
      setChurches(items.filter((c: ChurchItem) => !c.is_deleted))
      setDeletedChurches(items.filter((c: ChurchItem) => c.is_deleted).map((c: ChurchItem) => ({
        id: c.id,
        name: c.name,
        deleted_at: c.deleted_at,
        is_recoverable: c.is_recoverable,
        days_until_purge: c.days_until_purge,
        recoverable_until: c.recoverable_until,
      })))
    } catch { /* ignore */ }
    finally { setLoading(false) }
  }

  useEffect(() => { fetchChurches() }, [])

  const openModal = async (churchId: number, mode: ModalMode) => {
    setSelectedId(churchId)
    setModalMode(mode)
    setConfirmation('')
    setPassword('')
    setSummaryLoading(true)
    setModalOpen(true)
    try {
      const data = await getDeletionSummary(churchId)
      setSummary(data)
      if (data.already_deleted) {
        setDeletedInfo({
          id: data.church_id,
          name: data.church_name,
          deleted_at: data.deleted_at,
          is_recoverable: data.is_recoverable,
          days_until_purge: data.days_until_purge,
          recoverable_until: data.recoverable_until,
        })
      } else {
        setDeletedInfo(null)
      }
    } catch {
      toast.error(t('common.failedToLoad'))
      setModalOpen(false)
    } finally {
      setSummaryLoading(false)
    }
  }

  const closeModal = () => {
    setModalOpen(false)
    setConfirmation('')
    setPassword('')
    setSelectedId(null)
    setModalMode(null)
    setSummary(null)
    setDeletedInfo(null)
  }

  const handleAction = async () => {
    if (!selectedId || confirmation !== 'DELETE CHURCH' || !password.trim() || !modalMode) return
    setActionLoading(true)
    try {
      if (modalMode === 'soft-delete') {
        await softDeleteChurch(selectedId, confirmation, password)
        toast.success(t('churchDeletion.softDeleted'))
      } else if (modalMode === 'hard-delete') {
        await hardDeleteChurch(selectedId, confirmation, password)
        toast.success(t('churchDeletion.hardDeleted'))
      } else if (modalMode === 'restore') {
        await restoreChurch(selectedId, confirmation, password)
        toast.success(t('churchDeletion.restored'))
      }
      closeModal()
      await fetchChurches()
    } catch (err: any) {
      const msg = err?.response?.data?.message || t('common.failedToSave')
      toast.error(msg)
    } finally {
      setActionLoading(false)
    }
  }

  const getModalTitle = () => {
    switch (modalMode) {
      case 'soft-delete': return t('churchDeletion.softDeleteTitle')
      case 'hard-delete': return t('churchDeletion.hardDeleteTitle')
      case 'restore': return t('churchDeletion.restoreTitle')
      default: return ''
    }
  }

  const getModalButtonLabel = () => {
    switch (modalMode) {
      case 'soft-delete': return t('churchDeletion.confirmSoftDelete')
      case 'hard-delete': return t('churchDeletion.confirmHardDelete')
      case 'restore': return t('churchDeletion.confirmRestore')
      default: return ''
    }
  }

  const summaryItem = (icon: React.ReactNode, label: string, count: number | undefined) => (
    <div key={label} className="flex items-center justify-between rounded-lg bg-surface-secondary px-4 py-2.5">
      <div className="flex items-center gap-2 text-sm">
        <span className="text-muted shrink-0">{icon}</span>
        <span>{label}</span>
      </div>
      <Badge variant={(count ?? 0) > 0 ? 'danger' : 'default'}>{(count ?? 0).toLocaleString()}</Badge>
    </div>
  )

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('churchDeletion.title')}</h1>
          <p className="text-sm text-secondary mt-1">{t('churchDeletion.description')}</p>
        </div>
      </div>

      {churches.length > 0 && (
        <div className="card">
          <div className="border-b border-border px-5 py-3">
            <h2 className="font-semibold flex items-center gap-2">
              <Building2 className="h-4 w-4 text-gold-500" />
              {t('churchDeletion.activeChurches')}
            </h2>
          </div>
          <div className="divide-y divide-border">
            {churches.map((church) => (
              <div key={church.id} className="flex items-center justify-between px-5 py-4">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <h3 className="font-semibold truncate">{church.name}</h3>
                    <Badge variant="success">{t('common.active')}</Badge>
                  </div>
                  <p className="text-sm text-muted mt-0.5">{church.member_count} {t('users.totalUsers')}</p>
                </div>
                <button
                  onClick={() => openModal(church.id, 'soft-delete')}
                  className="btn-danger btn-sm shrink-0"
                >
                  <Trash2 className="h-4 w-4" />
                  {t('churchDeletion.deleteChurch')}
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {deletedChurches.length > 0 && (
        <div className="card border border-danger/20">
          <div className="border-b border-border px-5 py-3 bg-danger-light/20">
            <h2 className="font-semibold flex items-center gap-2 text-danger">
              <Clock className="h-4 w-4" />
              {t('churchDeletion.deletedChurches')}
            </h2>
          </div>
          <div className="divide-y divide-border">
            {deletedChurches.map((church) => (
              <div key={church.id} className="flex items-center justify-between px-5 py-4">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-muted line-through">{church.name}</p>
                  <div className="flex items-center gap-2 mt-1">
                    <Badge variant="danger">{t('churchDeletion.deleted')}</Badge>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={() => openModal(church.id, 'restore')}
                    className="btn-primary btn-sm"
                  >
                    <RefreshCw className="h-4 w-4" />
                    {t('churchDeletion.restore')}
                  </button>
                  <button
                    onClick={() => openModal(church.id, 'hard-delete')}
                    className="btn-ghost btn-sm text-danger"
                  >
                    <RotateCcw className="h-4 w-4" />
                    {t('churchDeletion.permanentPurge')}
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {churches.length === 0 && deletedChurches.length === 0 && (
        <div className="card py-12 text-center">
          <Building2 className="h-12 w-12 mx-auto text-muted mb-3" />
          <p className="text-muted">{t('churchDeletion.noChurches')}</p>
        </div>
      )}

      <Modal
        isOpen={modalOpen}
        onClose={closeModal}
        title={getModalTitle()}
        size="lg"
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={closeModal} className="flex-1 btn-secondary btn-md">
              {t('common.cancel')}
            </button>
            <button
              onClick={handleAction}
              disabled={actionLoading || confirmation !== 'DELETE CHURCH' || !password.trim()}
              className={`flex-1 btn-md ${modalMode === 'restore' ? 'btn-primary' : 'btn-danger'}`}
            >
              {actionLoading ? t('common.saving') : getModalButtonLabel()}
            </button>
          </div>
        }
      >
        {summaryLoading ? (
          <LoadingSpinner className="py-10" />
        ) : summary ? (
          <div className="space-y-4">
            {modalMode === 'soft-delete' && (
              <div className="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger-light/50 p-4">
                <AlertTriangle className="h-6 w-6 text-danger shrink-0" />
                <div>
                  <p className="font-semibold text-danger">{t('churchDeletion.warningTitle')}</p>
                  <p className="text-sm text-danger-dark">{t('churchDeletion.warningDescription')}</p>
                </div>
              </div>
            )}

            {modalMode === 'hard-delete' && (
              <div className="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger-light/50 p-4">
                <ShieldAlert className="h-6 w-6 text-danger shrink-0" />
                <div>
                  <p className="font-semibold text-danger">{t('churchDeletion.hardWarningTitle')}</p>
                  <p className="text-sm text-danger-dark">{t('churchDeletion.hardWarningDescription')}</p>
                </div>
              </div>
            )}

            {modalMode === 'restore' && deletedInfo?.is_recoverable && (
              <div className="flex items-center gap-3 rounded-lg border border-success/20 bg-success-light/50 p-4">
                <RefreshCw className="h-6 w-6 text-success shrink-0" />
                <div>
                  <p className="font-semibold text-success">{t('churchDeletion.restoreInfo')}</p>
                  <p className="text-sm text-success-dark">
                    {t('churchDeletion.recoveryExpires')}: {deletedInfo.days_until_purge != null ? t('churchDeletion.daysRemaining', { count: deletedInfo.days_until_purge }) : '-'}
                  </p>
                </div>
              </div>
            )}

            {modalMode === 'restore' && deletedInfo && !deletedInfo.is_recoverable && (
              <div className="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger-light/50 p-4">
                <Clock className="h-6 w-6 text-danger shrink-0" />
                <div>
                  <p className="font-semibold text-danger">{t('churchDeletion.recoveryExpired')}</p>
                  <p className="text-sm text-danger-dark">{t('churchDeletion.recoveryExpiredDescription')}</p>
                </div>
              </div>
            )}

            <div className="rounded-lg border border-border p-4">
              <h3 className="flex items-center gap-2 font-semibold text-lg mb-3">
                <Building2 className="h-5 w-5 text-gold-500" />
                {summary.church_name}
                <span className="text-xs text-muted font-normal">ID: {summary.church_id}</span>
              </h3>

              <div className="grid gap-2 grid-cols-1 xs:grid-cols-2">
                {summaryItem(<Users className="h-4 w-4" />, t('churchDeletion.totalUsers'), summary.total_users)}
                {summaryItem(<User className="h-4 w-4" />, t('churchDeletion.totalMembers'), summary.total_members)}
                {summaryItem(<Shield className="h-4 w-4" />, t('churchDeletion.totalServants'), summary.total_servants)}
                {summaryItem(<Shield className="h-4 w-4" />, t('churchDeletion.totalAdmins'), summary.total_admins)}
                {summaryItem(<Calendar className="h-4 w-4" />, t('churchDeletion.totalEvents'), summary.total_events)}
                {summaryItem(<ClipboardList className="h-4 w-4" />, t('churchDeletion.totalAttendances'), summary.total_attendances)}
                {summaryItem(<Layers className="h-4 w-4" />, t('churchDeletion.totalAttendanceContexts'), summary.total_attendance_contexts)}
                {summaryItem(<QrCode className="h-4 w-4" />, t('churchDeletion.totalQrInvites'), summary.total_qr_invites)}
                {summaryItem(<Trophy className="h-4 w-4" />, t('churchDeletion.totalPoints'), summary.total_points)}
                {summaryItem(<MessageSquare className="h-4 w-4" />, t('churchDeletion.totalFeedback'), summary.total_feedback)}
                {summaryItem(<MessageSquare className="h-4 w-4" />, t('churchDeletion.totalFeedbackReplies'), summary.total_feedback_replies)}
                {summaryItem(<Eye className="h-4 w-4" />, t('churchDeletion.totalEventViews'), summary.total_event_views)}
                {summaryItem(<Target className="h-4 w-4" />, t('churchDeletion.totalEventTargets'), summary.total_event_targets)}
                {summaryItem(<Bell className="h-4 w-4" />, t('churchDeletion.totalNotifications'), summary.total_notifications)}
                {summaryItem(<BookOpen className="h-4 w-4" />, t('churchDeletion.totalDailyVerses'), summary.total_daily_verses)}
                {summaryItem(<UserPlus className="h-4 w-4" />, t('churchDeletion.totalMembershipRequests'), summary.total_membership_requests)}
                {summaryItem(<Layers className="h-4 w-4" />, t('churchDeletion.totalStages'), summary.total_stages)}
                {summaryItem(<Layers className="h-4 w-4" />, t('churchDeletion.totalClasses'), summary.total_classes)}
                {summaryItem(<FileText className="h-4 w-4" />, t('churchDeletion.totalPasswordResetRequests'), summary.total_password_reset_requests)}
                {summaryItem(<FileText className="h-4 w-4" />, t('churchDeletion.totalAuditLogs'), summary.total_audit_logs)}
              </div>

              <div className="mt-4 rounded-lg bg-surface-secondary px-4 py-3 flex items-center justify-between">
                <span className="font-semibold">{t('churchDeletion.totalRecords')}</span>
                <Badge variant="danger" className="text-base font-bold">{(summary.total_records ?? 0).toLocaleString()}</Badge>
              </div>
            </div>

            {deletedInfo?.recoverable_until && (
              <div className="rounded-lg bg-surface-secondary px-4 py-3 flex items-center justify-between">
                <span className="text-sm text-muted">{t('churchDeletion.recoverableUntil')}</span>
                <span className="text-sm font-medium">{new Date(deletedInfo.recoverable_until).toLocaleDateString()}</span>
              </div>
            )}

            <div className="rounded-lg border border-border p-4 space-y-4">
              <div>
                <label className="text-sm font-medium text-danger flex items-center gap-2">
                  <Key className="h-4 w-4" />
                  {t('churchDeletion.confirmPassword')} <span className="text-danger">*</span>
                </label>
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder={t('churchDeletion.passwordPlaceholder')}
                  className="input-field mt-1"
                  autoComplete="off"
                  autoFocus
                />
              </div>
              <div>
                <label className="text-sm font-medium text-danger">
                  {t('churchDeletion.typeConfirm')} <span className="text-danger">*</span>
                </label>
                <input
                  type="text"
                  value={confirmation}
                  onChange={(e) => setConfirmation(e.target.value)}
                  placeholder="DELETE CHURCH"
                  className="input-field mt-1 font-mono text-sm"
                />
                {confirmation && confirmation !== 'DELETE CHURCH' && (
                  <p className="mt-1 text-xs text-danger">{t('churchDeletion.confirmationMismatch')}</p>
                )}
              </div>
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
