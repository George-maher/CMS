import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/hooks/useAuth'
import { getApplicationStatus } from '@/api/churchApplications'
import toast from 'react-hot-toast'
import {
  Clock, XCircle, LogOut, Loader2, User, Building2, Mail, AlertTriangle,
  Calendar, Phone, MapPin, ChevronRight, Headphones, Edit3, RefreshCw, Church,
  Sun, Moon,
} from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'
import type { ChurchApplication } from '@/types'

export default function ApplicationStatus() {
  const { t } = useTranslation()
  const { theme, toggleTheme, language, setLanguage } = useTheme()
  const { user: authUser, logout, isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [application, setApplication] = useState<ChurchApplication | null>(null)
  const [userInfo, setUserInfo] = useState<{ name: string; email: string } | null>(null)
  const [status, setStatus] = useState<'pending' | 'approved' | 'rejected'>(authUser?.application_status || 'pending')

  useEffect(() => {
    if (!isAuthenticated) {
      navigate('/login', { replace: true })
      return
    }
    getApplicationStatus().then(data => {
      setApplication(data.application)
      setUserInfo(data.user)
      setStatus(data.application_status as 'pending' | 'approved' | 'rejected')
    }).catch(() => {
      toast.error(t('common.failedToLoad'))
    }).finally(() => {
      setLoading(false)
    })
  }, [isAuthenticated, navigate, t])

  useEffect(() => {
    if (!loading && status === 'approved' && authUser) {
      const roleRedirect: Record<string, string> = {
        platform_admin: '/platform',
        admin: '/admin',
        assistant_admin: '/assistant-admin',
        servant: '/servant',
        member: '/member',
      }
      const target = roleRedirect[authUser.role] || '/login'
      navigate(target, { replace: true })
    }
  }, [loading, status, authUser, navigate])

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const handleEditOrResubmit = () => {
    const email = userInfo?.email || application?.contact_email || authUser?.email
    if (email) {
      navigate(`/join?email=${encodeURIComponent(email)}`)
    } else {
      navigate('/join')
    }
  }

  const formatDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '—'
    try {
      return new Intl.DateTimeFormat(navigator.language || 'en-US', {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
      }).format(new Date(dateStr))
    } catch {
      return dateStr
    }
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary">
        <div className="text-center">
          <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary-500" />
          <p className="mt-4 text-sm text-muted">{t('common.loading')}</p>
        </div>
      </div>
    )
  }

  const isPending = status === 'pending'
  const isRejected = status === 'rejected'

  return (
    <div className="flex min-h-screen flex-col bg-surface-secondary">
      {/* Top bar */}
      <header className="sticky top-0 z-30 border-b bg-surface/80 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-3xl items-center justify-between px-4">
          <div className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-100 dark:bg-primary-900/50">
              <Church className="h-5 w-5 text-primary-600 dark:text-primary-400" />
            </div>
            <span className="text-base font-bold">{t('app.name')}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button
              onClick={toggleTheme}
              className="btn-ghost btn-icon rounded-lg"
              aria-label={t('theme.toggleTheme')}
            >
              {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </button>
            <button
              onClick={() => setLanguage(language === 'en' ? 'ar' : 'en')}
              className="btn-ghost btn-sm min-w-[40px] border border-border"
            >
              {language === 'en' ? 'AR' : 'EN'}
            </button>
            <button onClick={handleLogout} className="btn-ghost btn-sm gap-1.5 border border-border">
              <LogOut className="h-3.5 w-3.5" />
              <span className="hidden sm:inline">{t('auth.signOut')}</span>
            </button>
          </div>
        </div>
      </header>

      {/* Main content */}
      <div className="flex-1 px-4 py-8 sm:py-12">
        <div className="mx-auto max-w-2xl">

          {/* Status badge + heading */}
          <div className="text-center">
            {isPending ? (
              <div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-[2rem] bg-amber-100 shadow-lg shadow-amber-200/50 dark:bg-amber-900/40 dark:shadow-amber-900/20">
                <Clock className="h-10 w-10 text-amber-600 dark:text-amber-400" />
              </div>
            ) : (
              <div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-[2rem] bg-red-100 shadow-lg shadow-red-200/50 dark:bg-red-900/40 dark:shadow-red-900/20">
                <XCircle className="h-10 w-10 text-red-600 dark:text-red-400" />
              </div>
            )}

            <h1 className="text-2xl font-bold sm:text-3xl">
              {isPending ? t('applicationStatus.pendingTitle') : t('applicationStatus.rejectedTitle')}
            </h1>
            <p className="mt-2 text-secondary">
              {isPending ? t('applicationStatus.pendingSubtitle') : t('applicationStatus.rejectedSubtitle')}
            </p>
          </div>

          {/* Status badge */}
          <div className="mt-6 flex justify-center">
            {isPending ? (
              <span className="inline-flex items-center gap-2 rounded-full bg-amber-100 px-5 py-2 text-sm font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                <span className="relative flex h-2.5 w-2.5">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                  <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                </span>
                {t('applicationStatus.pendingBadge')}
              </span>
            ) : (
              <span className="inline-flex items-center gap-2 rounded-full bg-red-100 px-5 py-2 text-sm font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                <span className="inline-flex h-2.5 w-2.5 rounded-full bg-red-500" />
                {t('applicationStatus.rejectedBadge')}
              </span>
            )}
          </div>

          {/* Rejection reason */}
          {isRejected && application?.rejection_reason && (
            <div className="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900/50 dark:bg-red-950/30">
              <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-red-600 dark:text-red-400">
                    {t('applicationStatus.rejectionReason')}
                  </p>
                  <p className="mt-1.5 text-sm font-medium text-red-700 dark:text-red-300">
                    {application.rejection_reason}
                  </p>
                </div>
              </div>
            </div>
          )}

          {isRejected && !application?.rejection_reason && (
            <div className="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900/50 dark:bg-red-950/30">
              <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                <div>
                  <p className="text-sm text-red-700 dark:text-red-300">
                    {t('applicationStatus.noRejectionReason')}
                  </p>
                </div>
              </div>
            </div>
          )}

          {/* Application info card */}
          <div className="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
            {/* Header */}
            <div className="border-b border-border bg-surface-secondary/50 px-6 py-4">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-muted">
                {t('applicationStatus.applicationDetails')}
              </h2>
            </div>

            {/* Content */}
            <div className="divide-y divide-border">
              {/* Church name */}
              <div className="flex items-start gap-4 px-6 py-4">
                <Building2 className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('join.churchName')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {application?.church_name || '—'}
                  </p>
                </div>
              </div>

              {/* Priest name */}
              <div className="flex items-start gap-4 px-6 py-4">
                <User className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('join.priestName')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {application?.priest_name || '—'}
                  </p>
                </div>
              </div>

              {/* Service name */}
              <div className="flex items-start gap-4 px-6 py-4">
                <Building2 className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('join.serviceName')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {application?.service_name || application?.main_servant_name || '—'}
                  </p>
                </div>
              </div>

              {/* Email */}
              <div className="flex items-start gap-4 px-6 py-4">
                <Mail className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('auth.email')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {userInfo?.email || application?.contact_email || '—'}
                  </p>
                </div>
              </div>

              {/* Phone */}
              <div className="flex items-start gap-4 px-6 py-4">
                <Phone className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('join.phone')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {application?.priest_phone || application?.phone || '—'}
                  </p>
                </div>
              </div>

              {/* Address */}
              {application?.address && (
                <div className="flex items-start gap-4 px-6 py-4">
                  <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium uppercase tracking-wider text-muted">
                      {t('common.address')}
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">
                      {application.address}
                    </p>
                  </div>
                </div>
              )}

              {/* Submission date */}
              <div className="flex items-start gap-4 px-6 py-4">
                <Calendar className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium uppercase tracking-wider text-muted">
                    {t('applicationStatus.submissionDate')}
                  </p>
                  <p className="mt-0.5 text-sm font-semibold">
                    {formatDate(application?.created_at)}
                  </p>
                </div>
              </div>

              {/* Rejection date */}
              {isRejected && application?.rejected_at && (
                <div className="flex items-start gap-4 px-6 py-4">
                  <Calendar className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium uppercase tracking-wider text-muted">
                      {t('applicationStatus.rejectionDate')}
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">
                      {formatDate(application.rejected_at)}
                    </p>
                  </div>
                </div>
              )}

              {/* Reviewer info (if rejected) */}
              {isRejected && application?.reviewed_by && (
                <div className="flex items-start gap-4 px-6 py-4">
                  <ChevronRight className="mt-0.5 h-5 w-5 shrink-0 text-muted" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium uppercase tracking-wider text-muted">
                      {t('platform.reviewedBy')}
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">
                      {application.reviewed_by.name}
                    </p>
                    {application.reviewed_at && (
                      <p className="mt-0.5 text-xs text-muted">
                        {formatDate(application.reviewed_at)}
                      </p>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Status message */}
          {isPending && (
            <div className="mt-6 rounded-2xl bg-primary-50 p-5 dark:bg-primary-950/30">
              <p className="text-sm leading-relaxed text-primary-700 dark:text-primary-300">
                {t('applicationStatus.pendingMessage')}
              </p>
              <p className="mt-2 text-xs text-primary-500 dark:text-primary-400">
                {t('applicationStatus.estimatedReview')}
              </p>
            </div>
          )}

          {isRejected && (
            <div className="mt-6 rounded-2xl bg-surface-tertiary p-5">
              <p className="text-sm leading-relaxed text-secondary">
                {t('applicationStatus.rejectedMessage')}
              </p>
            </div>
          )}

          {/* Action buttons */}
          <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-center">
            {/* Contact Support */}
            <a
              href={`tel:${import.meta.env.VITE_SUPPORT_PHONE || '+201012345678'}`}
              className="btn-secondary btn-md inline-flex items-center gap-2"
            >
              <Headphones className="h-4 w-4" />
              {t('applicationStatus.contactSupport')}
            </a>

            {isPending && (
              <button
                onClick={handleEditOrResubmit}
                className="btn-ghost btn-md inline-flex items-center gap-2 border border-border"
              >
                <Edit3 className="h-4 w-4" />
                {t('applicationStatus.editApplication')}
              </button>
            )}

            {isRejected && (
              <>
                <button
                  onClick={handleEditOrResubmit}
                  className="btn-primary btn-md inline-flex items-center gap-2"
                >
                  <RefreshCw className="h-4 w-4" />
                  {t('applicationStatus.resubmitApplication')}
                </button>
                <button
                  onClick={handleEditOrResubmit}
                  className="btn-ghost btn-md inline-flex items-center gap-2 border border-border"
                >
                  <Edit3 className="h-4 w-4" />
                  {t('applicationStatus.editApplication')}
                </button>
              </>
            )}

            <button
              onClick={handleLogout}
              className="btn-ghost btn-md inline-flex items-center gap-2 border border-border text-muted sm:hidden"
            >
              <LogOut className="h-4 w-4" />
              {t('auth.signOut')}
            </button>
          </div>

          {/* Footer */}
          <p className="mt-10 text-center text-xs text-muted">
            {t('applicationStatus.footer')}
          </p>
        </div>
      </div>
    </div>
  )
}