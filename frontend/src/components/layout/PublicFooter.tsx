import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Church } from 'lucide-react'

export default function PublicFooter() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <footer className="border-t bg-surface py-12">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <button onClick={() => navigate('/')} className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600">
                <Church className="h-4 w-4 text-white" />
              </div>
              <span className="font-bold">{t('app.name')}</span>
            </button>
            <p className="mt-3 text-sm text-secondary">{t('landing.footerTag')}</p>
          </div>
          <div>
            <h4 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted">{t('landing.footerAbout')}</h4>
            <p className="text-sm text-secondary">{t('landing.footerAboutText')}</p>
          </div>
          <div>
            <h4 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted">{t('landing.footerQuickLinks')}</h4>
            <ul className="space-y-2 text-sm">
              <li><button onClick={() => navigate('/')} className="text-secondary hover:text-primary-600 transition-colors">{t('nav.home')}</button></li>
              <li><button onClick={() => navigate('/join')} className="text-secondary hover:text-primary-600 transition-colors">{t('landing.heroCta')}</button></li>
              <li><button onClick={() => navigate('/login')} className="text-secondary hover:text-primary-600 transition-colors">{t('auth.signIn')}</button></li>
            </ul>
          </div>
          <div>
            <h4 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted">{t('landing.footerContact')}</h4>
            <p className="text-sm text-secondary">{t('landing.footerContactEmail')}</p>
          </div>
        </div>
        <div className="mt-10 border-t border-border pt-6 text-center text-xs text-muted">
          &copy; {new Date().getFullYear()} {t('app.name')}. {t('landing.footerRights')}
        </div>
      </div>
    </footer>
  )
}
