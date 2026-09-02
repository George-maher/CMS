import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/hooks/useAuth'
import { User, Save, Clock, CheckCircle, XCircle, AlertCircle, Send } from 'lucide-react'
import toast from 'react-hot-toast'
import { logCatch } from '@/lib/debug'
import { updateOwnProfile, submitProfileUpdateRequest, listMyProfileUpdateRequests } from '@/api/profileUpdateRequests'
import type { ProfileUpdateRequest } from '@/types'
import Badge from '@/components/common/Badge'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'

export default function Profile() {
  const { t } = useTranslation()
  const { user, refreshUser } = useAuth()

  const isMember = user?.role === 'member'
  const isDirectEditor = user?.role === 'admin' || user?.role === 'assistant_admin' || user?.role === 'servant'

  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [address, setAddress] = useState('')
  const [saving, setSaving] = useState(false)

  const [myRequests, setMyRequests] = useState<ProfileUpdateRequest[]>([])
  const [requestsLoading, setRequestsLoading] = useState(false)
  const [pendingRequest, setPendingRequest] = useState<ProfileUpdateRequest | null>(null)

  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<ProfileUpdateRequest | null>(null)

  useEffect(() => {
    if (user) {
      Promise.resolve().then(() => {
        setName(user.name || '')
        setPhone(user.phone || '')
        setEmail(user.email || '')
        setAddress(user.address || '')
      })
    }
  }, [user])

  useEffect(() => {
    if (isMember) {
      Promise.resolve().then(() => setRequestsLoading(true))
      listMyProfileUpdateRequests({ per_page: 10 })
        .then((res) => {
          setMyRequests(res.data)
          const pending = res.data.find((r) => r.status === 'pending')
          setPendingRequest(pending ?? null)
        })
        .catch((e) => logCatch('Profile.fetchMyRequests', e))
        .finally(() => setRequestsLoading(false))
    }
  }, [isMember])

  const handleDirectSave = async () => {
    setSaving(true)
    try {
      await updateOwnProfile({ name, phone, email, address })
      toast.success(t('profile.updateSuccess'))
      await refreshUser()
    } catch (e: unknown) {
      logCatch('Profile.updateOwnProfile', e)
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('profile.updateFailed'))
    } finally {
      setSaving(false)
    }
  }

  const handleSubmitRequest = async () => {
    setSaving(true)
    try {
      await submitProfileUpdateRequest({ name, phone, email, address })
      toast.success(t('profile.requestSubmitted'))
      // Refresh pending requests
      const res = await listMyProfileUpdateRequests({ per_page: 10 })
      setMyRequests(res.data)
      const pending = res.data.find((r) => r.status === 'pending')
      setPendingRequest(pending ?? null)
    } catch (e: unknown) {
      logCatch('Profile.submitRequest', e)
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('profile.requestFailed'))
    } finally {
      setSaving(false)
    }
  }

  const openDetail = (req: ProfileUpdateRequest) => {
    setDetail(req)
    setDetailOpen(true)
  }

  const statusBadge = (status: string) => {
    switch (status) {
      case 'pending': return <Badge variant="warning"><Clock className="h-3 w-3 mr-1 inline" />{t('common.pending')}</Badge>
      case 'approved': return <Badge variant="success"><CheckCircle className="h-3 w-3 mr-1 inline" />{t('common.approved')}</Badge>
      case 'rejected': return <Badge variant="danger"><XCircle className="h-3 w-3 mr-1 inline" />{t('common.rejected')}</Badge>
      default: return <Badge>{status}</Badge>
    }
  }

  const fieldLabel = (field: string) => {
    const map: Record<string, string> = {
      name: t('auth.name'),
      phone: t('auth.phone'),
      email: t('auth.email'),
      address: t('common.address'),
    }
    return map[field] || field
  }

  return (
    <div className="space-y-6 max-w-2xl mx-auto">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl gold-gradient shadow-lg">
          <User className="h-5 w-5 text-navy-900" />
        </div>
        <div>
          <h1 className="text-2xl font-bold">{t('profile.title')}</h1>
          <p className="text-sm text-muted">{isMember ? t('profile.memberDescription') : t('profile.directDescription')}</p>
        </div>
      </div>

      {/* Profile Form */}
      <div className="card p-6 space-y-4">
        <div>
          <label className="label">{t('auth.name')}</label>
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="input"
            disabled={pendingRequest !== null && isMember}
          />
        </div>
        <div>
          <label className="label">{t('auth.phone')}</label>
          <input
            type="tel"
            value={phone}
            onChange={(e) => setPhone(e.target.value.slice(0, 11))}
            className="input"
            disabled={pendingRequest !== null && isMember}
          />
        </div>
        <div>
          <label className="label">{t('auth.email')}</label>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="input"
            disabled={pendingRequest !== null && isMember}
          />
        </div>
        <div>
          <label className="label">{t('common.address')}</label>
          <input
            type="text"
            value={address}
            onChange={(e) => setAddress(e.target.value)}
            className="input"
            disabled={pendingRequest !== null && isMember}
          />
        </div>

        {pendingRequest && isMember && (
          <div className="flex items-center gap-2 p-3 rounded-lg bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800">
            <AlertCircle className="h-5 w-5 text-warning-600 dark:text-warning-400 shrink-0" />
            <p className="text-sm text-warning-700 dark:text-warning-300">{t('profile.pendingRequestNote')}</p>
          </div>
        )}

        <div className="flex justify-end">
          {isDirectEditor ? (
            <button
              onClick={handleDirectSave}
              disabled={saving}
              className="btn-primary"
            >
              {saving ? t('common.saving') : <><Save className="h-4 w-4 mr-2" />{t('common.save')}</>}
            </button>
          ) : (
            <button
              onClick={handleSubmitRequest}
              disabled={saving || pendingRequest !== null}
              className="btn-primary"
            >
              {saving ? t('common.saving') : <><Send className="h-4 w-4 mr-2" />{t('profile.submitRequest')}</>}
            </button>
          )}
        </div>
      </div>

      {/* Member Request History */}
      {isMember && (
        <div className="card p-6 space-y-4">
          <h2 className="text-lg font-semibold">{t('profile.requestHistory')}</h2>
          {requestsLoading ? (
            <LoadingSpinner />
          ) : myRequests.length === 0 ? (
            <p className="text-muted text-sm">{t('common.noData')}</p>
          ) : (
            <div className="space-y-3">
              {myRequests.map((req) => (
                <div
                  key={req.id}
                  onClick={() => openDetail(req)}
                  className="flex items-center justify-between p-3 rounded-lg border border-border hover:bg-surface-hover cursor-pointer transition-colors"
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      {statusBadge(req.status)}
                      <span className="text-sm text-muted">{new Date(req.created_at).toLocaleDateString()}</span>
                    </div>
                    {req.rejection_reason && (
                      <p className="text-sm text-danger-600 mt-1 truncate">{req.rejection_reason}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Request Detail Modal */}
      <Modal isOpen={detailOpen} onClose={() => setDetailOpen(false)} title={t('profile.requestDetail')}>
        {detail && (
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              {statusBadge(detail.status)}
              <span className="text-sm text-muted">{new Date(detail.created_at).toLocaleString()}</span>
            </div>

            <div className="space-y-3">
              {Object.entries(detail.changes || {}).map(([field, change]) => (
                <div key={field} className="p-3 rounded-lg bg-surface border border-border">
                  <p className="text-sm font-medium mb-1">{fieldLabel(field)}</p>
                  <div className="grid grid-cols-2 gap-2 text-sm">
                    <div>
                      <span className="text-muted">{t('profile.currentValue')}: </span>
                      <span className="line-through text-muted">{change.old || '-'}</span>
                    </div>
                    <div>
                      <span className="text-muted">{t('profile.newValue')}: </span>
                      <span className="font-medium text-success-600">{change.new || '-'}</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            {detail.rejection_reason && (
              <div className="p-3 rounded-lg bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800">
                <p className="text-sm font-medium text-danger-700 dark:text-danger-300">{t('profile.rejectionReason')}:</p>
                <p className="text-sm text-danger-600 dark:text-danger-400 mt-1">{detail.rejection_reason}</p>
              </div>
            )}

            {detail.reviewer && (
              <p className="text-sm text-muted">
                {t('profile.reviewedBy')}: {detail.reviewer.name}
              </p>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}
