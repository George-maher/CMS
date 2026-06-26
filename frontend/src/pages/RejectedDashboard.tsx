import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { getPendingStatus } from '@/api/churchApplications'
import toast from 'react-hot-toast'
import { XCircle, LogOut, Loader2, User, Building2, Mail, AlertTriangle } from 'lucide-react'
import type { ChurchApplication } from '@/types'

export default function RejectedDashboard() {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [application, setApplication] = useState<ChurchApplication | null>(null)
  const [userInfo, setUserInfo] = useState<{ name: string; email: string } | null>(null)

  useEffect(() => {
    fetchStatus()
  }, [])

  const fetchStatus = async () => {
    try {
      const data = await getPendingStatus()
      setApplication(data.application)
      setUserInfo(data.user)
    } catch {
      toast.error(t('common.error'))
    } finally {
      setLoading(false)
    }
  }

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary">
        <Loader2 className="h-8 w-8 animate-spin text-primary-500" />
      </div>
    )
  }

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-surface-secondary p-4">
      <div className="absolute top-4 right-4 z-10 flex items-center gap-2">
        <button onClick={handleLogout} className="btn-ghost btn-sm border">
          <LogOut className="h-3.5 w-3.5" /> {t('auth.signOut')}
        </button>
      </div>

      <div className="w-full max-w-lg text-center">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-red-100 dark:bg-red-900/50 mb-6">
          <XCircle className="h-8 w-8 text-red-600 dark:text-red-400" />
        </div>

        <h1 className="text-3xl font-bold">{t('rejectedDashboard.title')}</h1>
        <p className="mt-3 text-lg text-secondary">{t('rejectedDashboard.subtitle')}</p>

        <div className="mt-8 card p-6 text-left">
          {userInfo && (
            <div className="flex items-center gap-3 mb-4 pb-4 border-b border-border">
              <User className="h-5 w-5 text-muted" />
              <div>
                <p className="text-sm text-muted">{t('pendingDashboard.applicant')}</p>
                <p className="font-medium">{userInfo.name}</p>
              </div>
            </div>
          )}

          {userInfo && (
            <div className="flex items-center gap-3 mb-4">
              <Mail className="h-5 w-5 text-muted" />
              <div>
                <p className="text-sm text-muted">{t('auth.email')}</p>
                <p className="font-medium">{userInfo.email}</p>
              </div>
            </div>
          )}

          {application && (
            <div className="flex items-center gap-3 mb-4">
              <Building2 className="h-5 w-5 text-muted" />
              <div>
                <p className="text-sm text-muted">{t('pendingDashboard.church')}</p>
                <p className="font-medium">{application.church_name}</p>
              </div>
            </div>
          )}

          <div className="flex items-center gap-3 mb-4">
            <XCircle className="h-5 w-5 text-red-500" />
            <div>
              <p className="text-sm text-muted">{t('pendingDashboard.status')}</p>
              <p className="font-medium text-red-600 dark:text-red-400">{t('rejectedDashboard.rejectedStatus')}</p>
            </div>
          </div>

          {application?.rejection_reason ? (
            <div className="mt-4 rounded-xl bg-danger-light border border-danger/20 p-4">
              <div className="flex items-center gap-2 mb-2">
                <AlertTriangle className="h-4 w-4 text-danger" />
                <p className="text-xs font-medium uppercase tracking-wider text-danger">{t('rejectedDashboard.description')}</p>
              </div>
              <p className="text-sm font-medium">{application.rejection_reason}</p>
            </div>
          ) : (
            <div className="mt-4 rounded-xl bg-surface-tertiary p-4">
              <p className="text-sm text-secondary">{t('rejectedDashboard.noReason')}</p>
            </div>
          )}
        </div>

        <p className="mt-6 text-sm text-muted">{t('rejectedDashboard.footer')}</p>

        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          <button onClick={() => navigate('/join')} className="btn-primary btn-md">
            {t('rejectedDashboard.reapply')}
          </button>
          <button onClick={() => navigate('/login')} className="btn-ghost btn-md border">
            {t('join.signIn')}
          </button>
        </div>
      </div>
    </div>
  )
}
