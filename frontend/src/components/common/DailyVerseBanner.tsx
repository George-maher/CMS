import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useTranslation } from 'react-i18next'
import { BookOpen } from 'lucide-react'
import { getActiveVerse } from '@/api/dailyverse'
import type { DailyVerse } from '@/types'

export default function DailyVerseBanner() {
  const { user } = useAuth()
  const { t } = useTranslation()
  const [verse, setVerse] = useState<DailyVerse | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getActiveVerse().then(setVerse).finally(() => setLoading(false))
  }, [])

  if (loading || !verse) return null

  const isManager = user?.role === 'admin' || user?.role === 'servant'
  const managePath = user?.role === 'admin' ? '/admin/verses' : '/servant/verses'

  return (
    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 p-6 text-white">
      <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(212,175,55,0.1)_0%,transparent_60%)]" />
      <div className="absolute top-0 right-0 w-32 h-32 bg-gold-400/5 rounded-full blur-3xl" />

      <div className="relative flex items-start justify-between gap-4">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-3">
            <BookOpen className="h-4 w-4 text-gold-400" />
            <p className="text-xs font-semibold uppercase tracking-wider text-gold-300">
              {t('verse.dailyVerse')}
            </p>
          </div>
          <p className="text-base md:text-lg leading-relaxed font-serif italic text-white/90">
            &ldquo;{verse.verse_text}&rdquo;
          </p>
          <p className="mt-2 text-sm font-semibold text-gold-300">
            — {verse.reference}
          </p>
        </div>
        {isManager && (
          <Link to={managePath} className="shrink-0 rounded-lg gold-gradient px-3 py-1.5 text-xs font-bold text-navy-900 hover:shadow-lg transition-all">
            {t('common.manage')}
          </Link>
        )}
      </div>
    </div>
  )
}
