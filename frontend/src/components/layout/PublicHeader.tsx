import { useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useTheme } from '@/contexts/ThemeContext'
import { useTranslation } from 'react-i18next'
import { Church, Menu, X, Sun, Moon, Languages, LogIn, Home } from 'lucide-react'

export default function PublicHeader() {
  const { t } = useTranslation()
  const { theme, toggleTheme, language, setLanguage } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const [mobileOpen, setMobileOpen] = useState(false)

  const isRtl = language === 'ar'
  const isHome = location.pathname === '/'
  const toggleLang = () => setLanguage(isRtl ? 'en' : 'ar')

  return (
    <header className="sticky top-0 z-50 border-b border-glass-border bg-surface/80 backdrop-blur-xl">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
        <button onClick={() => navigate('/')} className="flex items-center gap-2.5 group">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl gold-gradient shadow-lg group-hover:shadow-xl transition-all">
            <Church className="h-5 w-5 text-navy-900" />
          </div>
          <span className="text-lg font-bold gold-text">{t('app.name')}</span>
        </button>

        <nav className="hidden items-center gap-1 md:flex" aria-label="Main navigation">
          {!isHome && (
            <button onClick={() => navigate('/')} className="btn-ghost btn-sm" title={t('nav.home')}>
              <Home className="h-4 w-4" />
            </button>
          )}

          <button onClick={toggleLang} className="btn-ghost btn-sm border" aria-label={t('language.toggle')}>
            <Languages className="h-3.5 w-3.5" />
            {language === 'en' ? 'AR' : 'EN'}
          </button>

          <button onClick={toggleTheme} className="btn-icon btn-ghost rounded-lg" aria-label={t('theme.toggleTheme')}>
            {theme === 'dark' ? <Sun className="h-5 w-5 text-gold-400" /> : <Moon className="h-5 w-5" />}
          </button>

          <div className="mr-2 flex items-center gap-2">
            <button onClick={() => navigate('/login')} className="btn-ghost btn-sm border">
              <LogIn className="h-3.5 w-3.5" />
              {t('landing.heroLogin')}
            </button>
            <button onClick={() => navigate('/join')} className="btn-gold btn-sm">
              <Church className="h-3.5 w-3.5" />
              {t('landing.heroCta')}
            </button>
          </div>
        </nav>

        <button onClick={() => setMobileOpen(!mobileOpen)} className="btn-icon btn-ghost rounded-lg md:hidden" aria-label={t('common.toggleMenu')} aria-expanded={mobileOpen}>
          {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
        </button>
      </div>

      {mobileOpen && (
        <div className="border-t border-glass-border bg-surface/95 backdrop-blur-xl md:hidden animate-fade-in">
          <nav className="space-y-1 px-4 py-3" aria-label="Mobile navigation">
            <button onClick={() => { navigate('/'); setMobileOpen(false) }} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-secondary hover:bg-gold-50/30 hover:text-gold-600 transition-colors">
              <Home className="h-4 w-4" /> {t('nav.home')}
            </button>
            <button onClick={() => { navigate('/login'); setMobileOpen(false) }} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-secondary hover:bg-gold-50/30 hover:text-gold-600 transition-colors">
              <LogIn className="h-4 w-4" /> {t('landing.heroLogin')}
            </button>
            <button onClick={() => { navigate('/join'); setMobileOpen(false) }} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-white gold-gradient shadow-md">
              <Church className="h-4 w-4" /> {t('landing.heroCta')}
            </button>
            <hr className="border-border my-2" />
            <button onClick={() => { toggleLang(); setMobileOpen(false) }} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-secondary hover:bg-gold-50/30 transition-colors">
              <Languages className="h-4 w-4" /> {language === 'en' ? 'العربية' : 'English'}
            </button>
            <button onClick={() => { toggleTheme(); setMobileOpen(false) }} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-secondary hover:bg-gold-50/30 transition-colors">
              {theme === 'dark' ? <Sun className="h-4 w-4 text-gold-400" /> : <Moon className="h-4 w-4" />}
              {theme === 'dark' ? t('theme.light') : t('theme.dark')}
            </button>
          </nav>
        </div>
      )}
    </header>
  )
}
