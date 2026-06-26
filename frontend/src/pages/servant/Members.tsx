import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import Badge from '@/components/common/Badge'
import CopyButton from '@/components/common/CopyButton'
import DataTable from '@/components/common/DataTable'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import type { Column } from '@/components/common/DataTable'
import type { User } from '@/types'
import { getMembers } from '@/api/users'
import { getTodayAttendance } from '@/api/attendance'
import { MessageCircle, Search } from 'lucide-react'

function getWhatsAppUrl(phone: string | null): string | null {
  if (!phone) return null
  const cleaned = phone.replace(/[\s\-\+\(\)]/g, '')
  const digits = cleaned.replace(/\D/g, '')
  if (digits.length < 7) return null
  return `https://wa.me/${digits}`
}

export default function ServantMembers() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [members, setMembers] = useState<User[]>([])
  const [filtered, setFiltered] = useState<User[]>([])
  const [todayAttended, setTodayAttended] = useState<Set<number>>(new Set())
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    getMembers()
      .then((m) => { setMembers(m); setFiltered(m) })
      .catch(() => { setMembers([]); setFiltered([]) })
    getTodayAttendance()
      .then((today) => setTodayAttended(new Set(today.data.map((a) => a.user.id))))
      .catch(() => setTodayAttended(new Set()))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    const term = search.toLowerCase().trim()
    if (!term) {
      setFiltered(members)
      return
    }
    setFiltered(
      members.filter(
        (m) =>
          m.name.toLowerCase().includes(term) ||
          (m.member_id?.toLowerCase() ?? '').includes(term) ||
          (m.phone ?? '').includes(term),
      ),
    )
  }, [search, members])

  const columns: Column<User>[] = [
    { key: 'member_id', header: t('users.memberIdLabel'), render: (u) => u.member_id ? (
      <span className="inline-flex items-center gap-1 font-mono text-xs">
        {u.member_id}
        <CopyButton value={u.member_id} iconSize={12} />
      </span>
    ) : <span className="font-mono text-xs">{t('common.unknown')}</span> },
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'phone', header: t('auth.phone'), render: (u) => {
      const waUrl = getWhatsAppUrl(u.phone)
      return (
        <span className="flex items-center gap-2 text-sm">
          <span>{u.phone || t('common.unknown')}</span>
          {waUrl && (
            <a href={waUrl} target="_blank" rel="noopener noreferrer"
              className="inline-flex items-center justify-center rounded-full bg-green-100 p-1.5 text-green-600 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50"
              title={t('common.whatsapp')}
              onClick={(e) => e.stopPropagation()}>
              <MessageCircle className="h-4 w-4" />
            </a>
          )}
        </span>
      )
    } },
    { key: 'age', header: t('users.age'), render: (u) =>
      <span className="text-sm">{u.age !== null && u.age !== undefined ? t('users.ageFormat', { age: u.age }) : t('common.unknown')}</span>
    },
    { key: 'classe', header: t('users.class'), render: (u) => u.classe?.name ?? t('common.unknown') },
    { key: 'attended_today', header: t('common.today'), render: (u) => <Badge variant={todayAttended.has(u.id) ? 'success' : 'default'}>{todayAttended.has(u.id) ? t('attendance.present') : t('attendance.absent')}</Badge> },
    { key: 'total_points', header: t('common.points'), render: (u) => <span className="font-semibold gold-text">{u.total_points}</span> },
  ]

  if (loading) return <LoadingSpinner className="py-20" />

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm text-secondary">{filtered.length} / {members.length} {t('dashboard.myMembers')}</p>
          <div className="relative ms-auto w-full sm:w-auto">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('attendance.searchMembers')}
            className="input-field text-sm w-full sm:w-56 ps-9"
          />
        </div>
      </div>
      <DataTable
        columns={columns}
        data={filtered}
        emptyMessage={t('attendance.noMembersAssigned')}
        onRowClick={(u) => navigate(`/servant/members/${u.id}`)}
      />
    </div>
  )
}
