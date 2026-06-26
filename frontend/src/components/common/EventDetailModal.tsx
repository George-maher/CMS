import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MapPin, Calendar, Users, Eye, EyeOff, BarChart3, Search } from 'lucide-react'
import Badge from './Badge'
import LoadingSpinner from './LoadingSpinner'
import type { Event, EventViewer } from '@/types'
import Modal from './Modal'
import { useAuth } from '@/contexts/AuthContext'
import { getEventViewSummary, getEventViewedUsers, getEventNotViewedUsers } from '@/api/events'
import { listAllClasses, getMyClasses } from '@/api/structure'

interface Props {
  event: Event | null
  isOpen: boolean
  onClose: () => void
}

type Tab = 'overview' | 'analytics'

export default function EventDetailModal({ event, isOpen, onClose }: Props) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const isAdminOrServant = user && ['admin', 'assistant_admin', 'servant'].includes(user.role)
  const [tab, setTab] = useState<Tab>('overview')

  const [summary, setSummary] = useState<{ total_views: number; total_target_members: number; view_percentage: number; not_viewed_count: number } | null>(null)
  const [viewedUsers, setViewedUsers] = useState<EventViewer[]>([])
  const [notViewedUsers, setNotViewedUsers] = useState<EventViewer[]>([])
  const [analyticsLoading, setAnalyticsLoading] = useState(false)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [searchQuery, setSearchQuery] = useState('')
  const [classFilter, setClassFilter] = useState('')

  useEffect(() => {
    if (isOpen && event) {
      if (isAdminOrServant) {
        setTab('overview')
        setSummary(null)
        setViewedUsers([])
        setNotViewedUsers([])
        setSearchQuery('')
        setClassFilter('')
        ;(user?.role === 'servant' ? getMyClasses() : listAllClasses()).then(setClasses).catch(() => {})
      }
    }
  }, [isOpen, event?.id])

  const loadAnalytics = () => {
    if (!event) return
    setAnalyticsLoading(true)
    const params: { class_id?: number; search?: string } = {}
    if (classFilter) params.class_id = Number(classFilter)
    if (searchQuery.trim()) params.search = searchQuery.trim()

    Promise.all([
      getEventViewSummary(event.id),
      getEventViewedUsers(event.id, params),
      getEventNotViewedUsers(event.id, params),
    ])
      .then(([s, v, nv]) => {
        setSummary(s)
        setViewedUsers(v)
        setNotViewedUsers(nv)
      })
      .catch(() => {})
      .finally(() => setAnalyticsLoading(false))
  }

  useEffect(() => {
    if (isOpen && event && isAdminOrServant && tab === 'analytics') {
      loadAnalytics()
    }
  }, [isOpen, event?.id, tab])

  useEffect(() => {
    if (tab === 'analytics') {
      loadAnalytics()
    }
  }, [searchQuery, classFilter])

  if (!event) return null

  const tabs: Tab[] = isAdminOrServant ? ['overview', 'analytics'] : ['overview']

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={event.name} size={isAdminOrServant ? 'xl' : 'lg'}>
      {isAdminOrServant && (
        <div className="flex gap-1 border-b mb-4 pb-2">
          {tabs.map((tabItem) => (
            <button
              key={tabItem}
              onClick={() => setTab(tabItem)}
              className={`flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md font-medium transition-colors ${
                tab === tabItem ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300' : 'text-muted hover:text-foreground'
              }`}
            >
              {tabItem === 'overview' ? <Eye className="h-4 w-4" /> : <BarChart3 className="h-4 w-4" />}
              {tabItem === 'overview' ? t('common.overview') : t('events.analytics')}
            </button>
          ))}
        </div>
      )}

      {tab === 'overview' && (
        <div className="space-y-3">
          {event.image && (
            <img src={event.image} alt={event.name} className="w-full h-48 object-cover rounded-lg mb-4 bg-surface-tertiary" />
          )}

          <Badge variant="info">{event.type_label}</Badge>

          {event.event_date && (
            <p className="flex items-center gap-2 text-sm text-secondary">
              <Calendar className="h-4 w-4 shrink-0" />
              {new Date(event.event_date).toLocaleDateString(undefined, {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
              })}
            </p>
          )}

          {event.location && (
            <p className="flex items-center gap-2 text-sm text-secondary">
              <MapPin className="h-4 w-4 shrink-0" />
              {event.location}
            </p>
          )}

          <p className="flex items-center gap-2 text-sm text-muted">
            <Users className="h-4 w-4 shrink-0" />
            {event.classe?.name ?? t('events.allClasses')}
          </p>

          {event.description && (
            <p className="text-sm text-secondary whitespace-pre-wrap border-t pt-3">{event.description}</p>
          )}
        </div>
      )}

      {tab === 'analytics' && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted" />
              <input
                type="text"
                placeholder={t('events.searchMembers')}
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="input-field pl-9"
              />
            </div>
            {user?.role !== 'servant' && (
              <select
                value={classFilter}
                onChange={(e) => setClassFilter(e.target.value)}
                className="input-field w-full sm:w-48"
              >
                <option value="">{t('events.classFilter')}</option>
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            )}
          </div>

          {analyticsLoading ? (
            <LoadingSpinner className="py-12" />
          ) : summary ? (
            <>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div className="card p-3 text-center">
                  <p className="text-2xl font-bold text-gold-600">{summary.total_target_members}</p>
                  <p className="text-xs text-muted mt-1">{t('events.totalMembers')}</p>
                </div>
                <div className="card p-3 text-center">
                  <p className="text-2xl font-bold text-success-dark">{summary.total_views}</p>
                  <p className="text-xs text-muted mt-1">{t('events.viewedCount')}</p>
                </div>
                <div className="card p-3 text-center">
                  <p className="text-2xl font-bold text-warning-dark">{summary.not_viewed_count}</p>
                  <p className="text-xs text-muted mt-1">{t('events.notViewedCount')}</p>
                </div>
                <div className="card p-3 text-center">
                  <p className="text-2xl font-bold text-gold-600">{summary.view_percentage}%</p>
                  <p className="text-xs text-muted mt-1">{t('events.readRate')}</p>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <h4 className="text-sm font-semibold flex items-center gap-1.5 mb-2">
                    <Eye className="h-4 w-4 text-success-dark" />
                    {t('events.readersList')}
                  </h4>
                  <div className="max-h-64 overflow-y-auto space-y-1">
                    {viewedUsers.length === 0 ? (
                      <p className="text-sm text-muted">{t('events.noViewsYet')}</p>
                    ) : (
                      viewedUsers.map((u) => (
                        <div key={u.id} className="text-sm py-2 px-2 rounded bg-green-50 dark:bg-green-900/10">
                          <div className="flex items-center justify-between">
                            <span className="font-medium">{u.name}</span>
                            {u.viewed_at && (
                              <span className="text-[10px] text-muted">{new Date(u.viewed_at).toLocaleDateString()}</span>
                            )}
                          </div>
                          <div className="flex items-center gap-2 text-[11px] text-muted mt-0.5">
                            {u.member_id && <span className="font-mono">{u.member_id}</span>}
                            {u.classe && <span>{u.classe.name}</span>}
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </div>

                <div>
                  <h4 className="text-sm font-semibold flex items-center gap-1.5 mb-2">
                    <EyeOff className="h-4 w-4 text-gold-400" />
                    {t('events.nonReadersList')}
                  </h4>
                  <div className="max-h-64 overflow-y-auto space-y-1">
                    {notViewedUsers.length === 0 ? (
                      <p className="text-sm text-muted">{t('events.allViewed')}</p>
                    ) : (
                      notViewedUsers.map((u) => (
                        <div key={u.id} className="text-sm py-2 px-2 rounded bg-amber-50 dark:bg-amber-900/10">
                          <span className="font-medium">{u.name}</span>
                          <div className="flex items-center gap-2 text-[11px] text-muted mt-0.5">
                            {u.member_id && <span className="font-mono">{u.member_id}</span>}
                            {u.classe && <span>{u.classe.name}</span>}
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </div>
              </div>
            </>
          ) : (
            <p className="text-sm text-muted py-8 text-center">{t('common.loading')}</p>
          )}
        </div>
      )}
    </Modal>
  )
}
