import { useEffect } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useTranslation } from 'react-i18next'
import {
  LayoutDashboard, Users, Calendar, MessageSquare,
  ClipboardList, QrCode,
  BookMarked, Camera, Star, Trophy,
  Church, Moon, Sun, Languages, X, UserX, Layers, Trash2,
} from 'lucide-react'

interface NavItem {
  labelKey: string
  path: string
  icon: React.ReactNode
}

const platformNav: NavItem[] = [
  { labelKey: 'nav.dashboard', path: '/platform', icon: <LayoutDashboard className="h-5 w-5" /> },
  { labelKey: 'nav.churchManagement', path: '/platform/churches', icon: <Trash2 className="h-5 w-5" /> },
]

const adminNav: NavItem[] = [
  { labelKey: 'nav.dashboard', path: '/admin', icon: <LayoutDashboard className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.users', path: '/admin/users', icon: <Users className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.structure', path: '/admin/structure', icon: <Layers className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.events', path: '/admin/events', icon: <Calendar className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.leaderboard', path: '/admin/leaderboard', icon: <Trophy className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.attendance', path: '/admin/attendance', icon: <ClipboardList className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.qr', path: '/admin/qr', icon: <QrCode className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.feedback', path: '/admin/feedback', icon: <MessageSquare className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.dailyVerse', path: '/admin/verses', icon: <BookMarked className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.absentMembers', path: '/admin/absent-members', icon: <UserX className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.attendanceContexts', path: '/admin/attendance-contexts', icon: <Layers className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.passwordResetRequests', path: '/admin/password-reset-requests', icon: <ClipboardList className="h-5 w-5 text-gold-400" /> },
]

const servantNav: NavItem[] = [
  { labelKey: 'nav.dashboard', path: '/servant', icon: <LayoutDashboard className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.leaderboard', path: '/servant/leaderboard', icon: <Trophy className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.myMembers', path: '/servant/members', icon: <Users className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.events', path: '/servant/events', icon: <Calendar className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.scanQR', path: '/servant/scan', icon: <Camera className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.attendance', path: '/servant/attendance', icon: <ClipboardList className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.qrInvites', path: '/servant/qr', icon: <QrCode className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.feedback', path: '/servant/feedback', icon: <MessageSquare className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.absentMembers', path: '/servant/absent-members', icon: <UserX className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.attendanceContexts', path: '/servant/attendance-contexts', icon: <Layers className="h-5 w-5 text-gold-400" /> },
]

const memberNav: NavItem[] = [
  { labelKey: 'nav.dashboard', path: '/member', icon: <LayoutDashboard className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.leaderboard', path: '/member/leaderboard', icon: <Trophy className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.myQRCode', path: '/member/qr', icon: <QrCode className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.events', path: '/member/events', icon: <Calendar className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.myAttendance', path: '/member/attendance', icon: <ClipboardList className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.submitFeedback', path: '/member/feedback', icon: <MessageSquare className="h-5 w-5 text-gold-400" /> },
  { labelKey: 'nav.myPoints', path: '/member/points', icon: <Star className="h-5 w-5 text-gold-400" /> },
]

interface Props {
  isOpen: boolean
  onClose: () => void
}

export default function Sidebar({ isOpen, onClose }: Props) {
  const { user } = useAuth()
  const { theme, toggleTheme, language, setLanguage, dir } = useTheme()
  const { t } = useTranslation()
  const location = useLocation()

  const roleLabelKey: Record<string, string> = {
    platform_admin: 'users.rolePlatformAdmin',
    admin: 'users.roleAdmin',
    assistant_admin: 'users.roleAssistantAdmin',
    servant: 'users.roleServant',
    member: 'users.roleMember',
  }

  const navItems = user?.role === 'platform_admin' ? platformNav
    : (user?.role === 'admin' || user?.role === 'assistant_admin') ? adminNav
    : user?.role === 'servant' ? servantNav
    : memberNav

  useEffect(() => { onClose() }, [location.pathname])

  const toggleLang = () => setLanguage(language === 'en' ? 'ar' : 'en')

  return (
    <>
      {isOpen && <div className="sidebar-overlay" onClick={onClose} />}
      <aside className={`sidebar ${isOpen ? 'translate-x-0' : dir === 'rtl' ? 'translate-x-full' : '-translate-x-full'}`}>
        {/* Logo */}
        <div className="flex h-16 items-center justify-between border-b border-border px-4 shrink-0 bg-gradient-to-r from-navy-900/5 to-transparent dark:from-gold-900/10">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl gold-gradient shadow-lg">
              <Church className="h-5 w-5 text-navy-900" />
            </div>
            <div>
              <span className="text-lg font-bold gold-text">{t('app.name')}</span>
              <p className="text-[10px] text-muted leading-none mt-0.5">Church Management</p>
            </div>
          </div>
          <button onClick={onClose} className="btn-icon btn-ghost rounded-lg" aria-label={t('common.closeSidebar')}>
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 min-h-0 space-y-0.5 overflow-y-auto p-3">
          {navItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              end={['/admin', '/servant', '/member', '/platform'].includes(item.path)}
              onClick={onClose}
              className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
            >
              {item.icon}
              <span className="truncate">{t(item.labelKey)}</span>
            </NavLink>
          ))}
        </nav>

        {/* User + Controls */}
        <div className="border-t border-border p-3 space-y-2 shrink-0">
          <div className="flex items-center gap-3 px-3 py-2 rounded-lg bg-gold-50/30 dark:bg-gold-900/10">
            <div className="flex h-9 w-9 items-center justify-center rounded-full gold-gradient text-sm font-bold text-navy-900 shrink-0 shadow-md">
              {user?.name?.charAt(0).toUpperCase()}
            </div>
            <div className="flex-1 min-w-0">
              <p className="truncate text-sm font-semibold">{user?.name}</p>
              <p className="text-[11px] text-muted capitalize">{user?.role ? t(roleLabelKey[user.role] || 'common.unknown') : ''}</p>
            </div>
          </div>

          <div className="flex gap-2 px-1">
            <button onClick={toggleTheme} className="flex-1 btn-ghost btn-sm border rounded-lg">
              {theme === 'dark' ? <Sun className="h-3.5 w-3.5 text-gold-400" /> : <Moon className="h-3.5 w-3.5 text-navy-500" />}
              {theme === 'dark' ? t('theme.light') : t('theme.dark')}
            </button>
            <button onClick={toggleLang} className="flex-1 btn-ghost btn-sm border rounded-lg">
              <Languages className="h-3.5 w-3.5" />
              {language === 'en' ? 'AR' : 'EN'}
            </button>
          </div>
        </div>
      </aside>
    </>
  )
}
