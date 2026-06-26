import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useTranslation } from 'react-i18next'
import { Menu, Moon, Sun, Languages, LogOut, Home, Bell, BellRing, Eye, Calendar, Church } from 'lucide-react'
import { getUnreadCount, listNotifications, markAsRead, markAllAsRead } from '@/api/notifications'
import type { NotificationItem } from '@/types'
import { getEvent } from '@/api/events'
import Modal from '@/components/common/Modal'

interface Props {
  onMenuClick: () => void
}

const routeTitles: Record<string, string> = {
  '/platform': 'nav.dashboard',
  '/admin': 'nav.dashboard',
  '/admin/users': 'nav.users',
  '/admin/structure': 'nav.structure',
  '/admin/events': 'nav.events',
  '/admin/feedback': 'nav.feedback',
  '/admin/leaderboard': 'nav.leaderboard',
  '/admin/qr': 'nav.qr',
  '/admin/verses': 'nav.dailyVerse',
  '/admin/attendance': 'nav.attendance',
  '/admin/absent-members': 'nav.absentMembers',
  '/admin/attendance-contexts': 'nav.attendanceContexts',
  '/assistant-admin': 'nav.dashboard',
  '/assistant-admin/users': 'nav.users',
  '/assistant-admin/structure': 'nav.structure',
  '/assistant-admin/events': 'nav.events',
  '/assistant-admin/feedback': 'nav.feedback',
  '/assistant-admin/leaderboard': 'nav.leaderboard',
  '/assistant-admin/qr': 'nav.qr',
  '/assistant-admin/verses': 'nav.dailyVerse',
  '/assistant-admin/attendance': 'nav.attendance',
  '/assistant-admin/absent-members': 'nav.absentMembers',
  '/assistant-admin/attendance-contexts': 'nav.attendanceContexts',
  '/servant': 'nav.dashboard',
  '/servant/leaderboard': 'nav.leaderboard',
  '/servant/members': 'nav.myMembers',
  '/servant/events': 'nav.events',
  '/servant/scan': 'nav.scanQR',
  '/servant/attendance': 'nav.attendance',
  '/servant/qr': 'nav.qrInvites',
  '/servant/feedback': 'nav.feedback',
  '/servant/absent-members': 'nav.absentMembers',
  '/servant/attendance-contexts': 'nav.attendanceContexts',
  '/member': 'nav.dashboard',
  '/member/leaderboard': 'nav.leaderboard',
  '/member/qr': 'nav.myQRCode',
  '/member/events': 'nav.events',
  '/member/attendance': 'nav.myAttendance',
  '/member/feedback': 'nav.submitFeedback',
  '/member/points': 'nav.myPoints',
}

export default function Header({ onMenuClick }: Props) {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const { theme, toggleTheme, language, setLanguage } = useTheme()
  const location = useLocation()
  const navigate = useNavigate()

  const [unreadCount, setUnreadCount] = useState(0)
  const [notifications, setNotifications] = useState<NotificationItem[]>([])
  const [showPanel, setShowPanel] = useState(false)
  const [viewingEvent, setViewingEvent] = useState<{ id: number; name: string; description: string | null; event_date: string | null; location: string | null } | null>(null)
  const [eventModalOpen, setEventModalOpen] = useState(false)
  const panelRef = useRef<HTMLDivElement>(null)

  const notifTitle = (n: NotificationItem): string => {
    if (n.type === 'feedback_reply') return t('notifications.feedbackReplyTitle')
    if (n.type === 'bonus_points') return t('notifications.bonusPointsTitle')
    if (n.type === 'event') return t('notifications.newEvent')
    return n.title
  }

  const notifBody = (n: NotificationItem): string | null => {
    if (n.type === 'feedback_reply') return t('notifications.feedbackReplyBody')
    if (n.type === 'bonus_points') return t('notifications.bonusPointsBody', { points: n.point?.points ?? '' })
    if (n.type === 'event') return n.body
    return n.body
  }

  const fetchUnreadCount = useCallback(async () => {
    try {
      const count = await getUnreadCount()
      setUnreadCount(count)
    } catch {}
  }, [])

  const fetchNotifications = useCallback(async () => {
    try {
      const res = await listNotifications({ per_page: 10 })
      setNotifications(res.data ?? [])
    } catch {}
  }, [])

  useEffect(() => {
    fetchUnreadCount()
    const interval = setInterval(fetchUnreadCount, 15000)
    return () => clearInterval(interval)
  }, [fetchUnreadCount])

  useEffect(() => {
    if (showPanel) fetchNotifications()
  }, [showPanel, fetchNotifications])

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) setShowPanel(false)
    }
    if (showPanel) document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [showPanel])

  const handleBellClick = () => setShowPanel((prev) => !prev)

  const handleNotificationClick = async (notif: NotificationItem) => {
    if (!notif.is_read) {
      try {
        await markAsRead(notif.id)
        setUnreadCount((prev) => Math.max(0, prev - 1))
        setNotifications((prev) =>
          prev.map((n) => (n.id === notif.id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n)),
        )
      } catch {}
    }
    setShowPanel(false)

    if (notif.type === 'feedback_reply' && notif.feedback_id) {
      navigate(`/${user?.role === 'member' ? 'member' : 'admin'}/feedback?feedbackId=${notif.feedback_id}`)
      return
    }
    if (notif.type === 'bonus_points') {
      navigate(`/member/points`)
      return
    }
    if (notif.event_id) {
      if (user?.role === 'member') {
        navigate(`/member/events/${notif.event_id}`)
      } else {
        try {
          const event = await getEvent(notif.event_id)
          setViewingEvent({ id: event.id, name: event.name, description: event.description, event_date: event.event_date, location: event.location })
          setEventModalOpen(true)
        } catch {}
      }
    }
  }

  const handleMarkAllRead = async () => {
    try {
      await markAllAsRead()
      setUnreadCount(0)
      setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true, read_at: new Date().toISOString() })))
    } catch {}
  }

  const getHomePath = () => {
    if (!user) return '/'
    const roleMap: Record<string, string> = {
      platform_admin: '/platform', admin: '/admin', assistant_admin: '/assistant-admin', servant: '/servant', member: '/member',
    }
    return roleMap[user.role] || '/'
  }

  const title = useMemo(() => {
    const path = Object.keys(routeTitles).find(p => location.pathname === p) ?? ''
    const key = routeTitles[path]
    return key ? t(key) : ''
  }, [location.pathname, t])

  const isUnread = (notif: NotificationItem) => !notif.is_read

  return (
    <>
      <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b bg-surface/80 backdrop-blur-xl px-4 shrink-0">
        <button onClick={onMenuClick} className="btn-icon btn-ghost rounded-lg" aria-label={t('common.toggleMenu')}>
          <Menu className="h-5 w-5" />
        </button>

        <button onClick={() => navigate(getHomePath())} className="btn-icon btn-ghost rounded-lg hidden sm:flex" title={t('nav.home')}>
          <Home className="h-5 w-5" />
        </button>

        <div className="flex items-center gap-2 flex-1 min-w-0">
          <div className="flex h-7 w-7 items-center justify-center rounded-lg gold-gradient shrink-0">
            <Church className="h-4 w-4 text-navy-900" />
          </div>
          <h1 className="text-lg font-semibold truncate gold-text">{title || t('app.name')}</h1>
        </div>

        <div className="flex items-center gap-1">
          {user?.role === 'member' && (
            <div className="relative" ref={panelRef}>
              <button onClick={handleBellClick} className="btn-icon btn-ghost rounded-lg relative" title={t('notifications.title')}>
                {unreadCount > 0 ? (
                  <>
                    <BellRing className="h-5 w-5 text-gold-400" />
                    <span className="absolute -top-0.5 -end-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white px-1">
                      {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                  </>
                ) : (
                  <Bell className="h-5 w-5" />
                )}
              </button>

              {showPanel && (
                <div className="absolute end-0 top-full mt-2 w-[calc(100vw-1rem)] sm:w-96 rounded-xl border border-glass-border bg-surface/95 backdrop-blur-xl shadow-xl overflow-hidden z-50 animate-scale-in">
                  <div className="flex items-center justify-between px-4 py-3 border-b border-border">
                    <h3 className="font-semibold text-sm">{t('notifications.title')}</h3>
                    {unreadCount > 0 && (
                      <button onClick={handleMarkAllRead} className="text-xs gold-text hover:underline font-medium">
                        {t('notifications.markAllRead')}
                      </button>
                    )}
                  </div>
                  <div className="max-h-96 overflow-y-auto">
                    {notifications.length === 0 ? (
                      <div className="px-4 py-8 text-center text-sm text-muted">{t('notifications.empty')}</div>
                    ) : (
                      notifications.map((notif) => (
                        <button
                          key={notif.id}
                          onClick={() => handleNotificationClick(notif)}
                          className={`w-full text-start px-4 py-3 hover:bg-gold-50/30 dark:hover:bg-gold-900/10 transition-colors flex items-start gap-3 border-b border-border last:border-0 ${
                            isUnread(notif) ? 'border-s-2 border-s-gold-400 bg-gold-50/20 dark:bg-gold-900/10' : ''
                          }`}
                        >
                          <div className={`mt-0.5 h-2 w-2 shrink-0 rounded-full ${isUnread(notif) ? 'bg-gold-400' : 'bg-transparent'}`} />
                          <div className="min-w-0 flex-1">
                            <p className={`text-sm ${isUnread(notif) ? 'font-semibold' : ''}`}>{notifTitle(notif)}</p>
                            {notifBody(notif) && (
                              <p className="text-xs text-secondary mt-0.5 line-clamp-2">{notifBody(notif)}</p>
                            )}
                            <p className="text-[10px] text-muted mt-1">
                              {new Date(notif.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                            </p>
                          </div>
                          {notif.event && <Eye className="h-4 w-4 shrink-0 text-muted mt-1" />}
                        </button>
                      ))
                    )}
                  </div>
                </div>
              )}
            </div>
          )}

          <button onClick={() => setLanguage(language === 'en' ? 'ar' : 'en')} className="btn-ghost btn-sm border hidden sm:flex">
            <Languages className="h-3.5 w-3.5 text-gold-400" />
            {language === 'en' ? 'AR' : 'EN'}
          </button>
          <button onClick={toggleTheme} className="btn-icon btn-ghost rounded-lg" title={t('theme.toggleTheme')}>
            {theme === 'dark' ? <Sun className="h-5 w-5 text-gold-400" /> : <Moon className="h-5 w-5 text-navy-500" />}
          </button>
          <button onClick={logout} className="btn-icon btn-ghost rounded-lg text-danger hover:bg-danger-light" title={t('nav.signOut')}>
            <LogOut className="h-5 w-5" />
          </button>
        </div>
      </header>

      <Modal isOpen={eventModalOpen} onClose={() => setEventModalOpen(false)} title={viewingEvent?.name ?? ''}>
        {viewingEvent && (
          <div className="space-y-3">
            {viewingEvent.event_date && (
              <p className="flex items-center gap-2 text-sm text-secondary">
                <Calendar className="h-4 w-4 shrink-0" />
                {new Date(viewingEvent.event_date).toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
              </p>
            )}
            {viewingEvent.location && <p className="text-sm text-secondary">{viewingEvent.location}</p>}
            {viewingEvent.description && <p className="text-sm text-secondary whitespace-pre-wrap">{viewingEvent.description}</p>}
            <div className="pt-3">
              <button onClick={() => { setEventModalOpen(false); const rolePath = user?.role === 'member' ? '/member' : user?.role === 'servant' ? '/servant' : '/admin'; navigate(`${rolePath}/events`) }} className="btn-primary btn-sm w-full">
                <Eye className="h-4 w-4" />
                {t('events.seeMore')}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </>
  )
}
