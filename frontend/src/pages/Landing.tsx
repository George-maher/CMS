import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Church, QrCode, Users, UserCheck, Calendar,
  BarChart3, MessageSquare, ChevronRight,
  ClipboardCheck, ShieldCheck, Rocket, BookMarked, Lock,
  LayoutDashboard, TrendingUp, Globe, Sparkles, Cross,
} from 'lucide-react'
import PublicHeader from '@/components/layout/PublicHeader'
import PublicFooter from '@/components/layout/PublicFooter'

const features = [
  { icon: QrCode, key: 'attendanceManagement' },
  { icon: Users, key: 'memberManagement' },
  { icon: UserCheck, key: 'servantManagement' },
  { icon: Calendar, key: 'eventsManagement' },
  { icon: ClipboardCheck, key: 'qrAttendance' },
  { icon: BarChart3, key: 'analytics' },
  { icon: MessageSquare, key: 'feedback' },
  { icon: BookMarked, key: 'dailyVerse' },
  { icon: Lock, key: 'roleAccess' },
]

const steps = [
  { icon: Church, key: 'step1' },
  { icon: ShieldCheck, key: 'step2' },
  { icon: Rocket, key: 'step3' },
]

const benefits = [
  { icon: LayoutDashboard, key: 'benefitOrganized' },
  { icon: QrCode, key: 'benefitTracking' },
  { icon: Globe, key: 'benefitDigital' },
  { icon: TrendingUp, key: 'benefitEngagement' },
]

export default function Landing() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()

  return (
    <div className="min-h-screen bg-surface-secondary">
      <PublicHeader />

      {/* ─── Hero Section ─── */}
      <section className="relative overflow-hidden bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(212,175,55,0.12)_0%,transparent_60%)]" />
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_bottom,rgba(30,58,138,0.2)_0%,transparent_50%)]" />
        <div className="absolute top-1/4 left-1/4 w-96 h-96 rounded-full bg-gold-400/5 blur-3xl" />
        <div className="absolute bottom-1/4 right-1/4 w-64 h-64 rounded-full bg-navy-400/10 blur-3xl" />

        <div className="relative mx-auto max-w-7xl px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
          <div className="mx-auto max-w-4xl text-center stagger-children">
            <div className="inline-flex items-center gap-2 rounded-full border border-glass-border bg-glass-bg backdrop-blur-sm px-4 py-1.5 text-sm text-gold-300 mb-8">
              <Sparkles className="h-4 w-4" />
              {t('app.tagline')}
            </div>

            <h1 className="text-3xl sm:text-5xl lg:text-7xl font-bold tracking-tight leading-tight">
              <span className="gold-text">{t('landing.heroTitle')}</span>
            </h1>
            <p className="mt-6 text-lg sm:text-xl text-white/70 max-w-2xl mx-auto leading-relaxed">
              {t('landing.heroSubtitle')}
            </p>

            <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
              <button onClick={() => navigate('/join')} className="btn-gold btn-lg px-8 py-3.5 text-base shadow-2xl shadow-gold-400/25">
                <Church className="h-5 w-5" />
                {t('landing.heroCta')}
              </button>
              <button onClick={() => navigate('/login')} className="btn-lg rounded-xl border border-white/20 px-8 py-3.5 text-base font-semibold text-white hover:bg-white/10 transition-all backdrop-blur-sm">
                {t('landing.heroLogin')} <ChevronRight className="h-4 w-4 rtl-flip" />
              </button>
            </div>
          </div>
        </div>

        <div className="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-gold-400/30 to-transparent" />
      </section>

      {/* ─── Features ─── */}
      <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
        <div className="text-center stagger-children">
          <div className="inline-flex items-center gap-2 rounded-full border border-glass-border bg-glass-bg backdrop-blur-sm px-4 py-1.5 text-sm gold-text mb-4">
            <Sparkles className="h-4 w-4" />
            {t('landing.features')}
          </div>
          <h2 className="text-3xl sm:text-4xl font-bold">{t('landing.featuresTitle')}</h2>
          <p className="mt-3 text-lg text-secondary max-w-2xl mx-auto">{t('landing.featuresSubtitle')}</p>
        </div>
        <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 stagger-children">
          {features.map((f) => {
            const Icon = f.icon
            return (
              <div key={f.key} className="glass-card-solid p-6">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl gold-gradient shadow-md">
                  <Icon className="h-6 w-6 text-navy-900" />
                </div>
                <h3 className="mt-4 font-semibold text-lg">{t(`landing.${f.key}`)}</h3>
                <p className="mt-2 text-sm text-secondary leading-relaxed">{t(`landing.${f.key}Desc`)}</p>
              </div>
            )
          })}
        </div>
      </section>

      {/* ─── How It Works ─── */}
      <section className="relative overflow-hidden bg-surface py-24">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(212,175,55,0.03)_0%,transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center stagger-children">
            <div className="inline-flex items-center gap-2 rounded-full border border-glass-border bg-glass-bg backdrop-blur-sm px-4 py-1.5 text-sm gold-text mb-4">
              <Cross className="h-4 w-4" />
              {t('landing.howItWorks')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold">{t('landing.howItWorksTitle')}</h2>
          </div>
          <div className="mt-12 grid gap-8 md:grid-cols-3 stagger-children">
            {steps.map((s, i) => {
              const Icon = s.icon
              return (
                <div key={s.key} className="text-center">
                  <div className="relative mx-auto">
                    <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl gold-gradient shadow-xl">
                      <Icon className="h-10 w-10 text-navy-900" />
                    </div>
                    <div className="absolute -top-2 -end-2 flex h-8 w-8 items-center justify-center rounded-full bg-navy-800 text-sm font-bold text-gold-400 shadow-lg ring-2 ring-gold-400/30">
                      {i + 1}
                    </div>
                  </div>
                  <h3 className="mt-6 text-xl font-bold">{t(`landing.${s.key}Title`)}</h3>
                  <p className="mt-3 text-sm text-secondary leading-relaxed max-w-xs mx-auto">{t(`landing.${s.key}Desc`)}</p>
                </div>
              )
            })}
          </div>
          <div className="mt-12 text-center">
            <button onClick={() => navigate('/join')} className="btn-gold btn-lg px-8 shadow-2xl shadow-gold-400/25">
              {t('landing.startNow')}
            </button>
          </div>
        </div>
      </section>

      {/* ─── Statistics ─── */}
      <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 stagger-children">
          {[
            { value: '10k+', label: 'Active Members' },
            { value: '500+', label: 'Churches Served' },
            { value: '50k+', label: 'Events Tracked' },
            { value: '100k+', label: 'Attendance Recorded' },
          ].map((stat) => (
            <div key={stat.label} className="glass-card text-center p-8">
              <p className="text-3xl sm:text-4xl font-bold gold-text">{stat.value}</p>
              <p className="mt-2 text-sm text-secondary">{stat.label}</p>
            </div>
          ))}
        </div>
      </section>

      {/* ─── Benefits ─── */}
      <section className="relative overflow-hidden bg-surface py-24">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_bottom,rgba(212,175,55,0.03)_0%,transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="text-center stagger-children">
            <h2 className="text-3xl sm:text-4xl font-bold">{t('landing.benefits')}</h2>
            <p className="mt-3 text-lg text-secondary">{t('landing.benefitsSubtitle')}</p>
          </div>
          <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4 stagger-children">
            {benefits.map((b) => {
              const Icon = b.icon
              return (
                <div key={b.key} className="glass-card-solid text-center p-8">
                  <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gold-50/50 dark:bg-gold-900/20 gold-text">
                    <Icon className="h-8 w-8" />
                  </div>
                  <h3 className="mt-5 text-lg font-bold">{t(`landing.${b.key}`)}</h3>
                  <p className="mt-3 text-sm text-secondary leading-relaxed">{t(`landing.${b.key}Desc`)}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>

      {/* ─── CTA ─── */}
      <section className="relative overflow-hidden bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 py-20">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(212,175,55,0.08)_0%,transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 text-center sm:px-6 stagger-children">
          <div className="inline-flex items-center gap-2 rounded-full border border-glass-border bg-glass-bg backdrop-blur-sm px-4 py-1.5 text-sm text-gold-300 mb-6">
            <Sparkles className="h-4 w-4" />
            {t('landing.startNow')}
          </div>
          <h2 className="text-2xl sm:text-5xl font-bold gold-text">{t('landing.heroCta')} — {t('app.name')}</h2>
          <p className="mt-4 text-lg text-white/70 max-w-xl mx-auto">{t('landing.heroSubtitle')}</p>
          <button onClick={() => navigate('/join')} className="btn-gold btn-lg px-10 py-3.5 mt-8 text-base shadow-2xl shadow-gold-400/30">
            <Church className="h-5 w-5" />
            {t('landing.startNow')}
          </button>
        </div>
      </section>

      <PublicFooter />
    </div>
  )
}
