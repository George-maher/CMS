import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Plus, Copy, Info, Eye, Search, X } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { QRInvite, QRInviteType } from '@/types'
import { listQRInvites, createQRInvite, revokeQRInvite } from '@/api/qr'
import { getMyClasses } from '@/api/structure'
import QRCodeLib from 'qrcode'

const statusBadge: Record<string, 'success' | 'danger' | 'warning' | 'info'> = {
  unused: 'info',
  partial: 'warning',
  used: 'success',
  expired: 'warning',
  revoked: 'danger',
}

const typeBadge: Record<string, 'info' | 'success' | 'warning'> = {
  admin_to_servant_invite: 'warning', servant_to_member_invite: 'info',
}

const roleLabel = (role?: string): string => {
  const map: Record<string, string> = { member: 'Member', servant: 'Servant', admin: 'Admin', assistant_admin: 'Asst. Admin', platform_admin: 'Platform Admin' }
  return role ? map[role] ?? role : ''
}

export default function ServantQRInvites() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const userDetailPath = (u: { id: number; role?: string }): string => {
    return `/servant/members/${u.id}`
  }

  const columns: Column<QRInvite>[] = [
    { key: 'type', header: t('qr.inviteType'), render: (q) => <Badge variant={typeBadge[q.type]}>{q.type_label}</Badge> },
    { key: 'status', header: t('common.status'), render: (q) => <Badge variant={statusBadge[q.status]}>{t(`qr.status${q.status.charAt(0).toUpperCase() + q.status.slice(1)}`)}</Badge> },
    {
      key: 'used_by',
      header: t('qr.usedBy'),
      render: (q) => {
        const allUsers = (q.used_by_users ?? []).length > 0
          ? (q.used_by_users ?? [])
          : (q.used_by ? [q.used_by] : [])

        if (allUsers.length === 0) {
          if (q.use_count > 0) return <span className="text-muted text-xs">{q.use_count} {t('common.total')}</span>
          return <span className="text-muted">-</span>
        }
        return (
          <div className="flex flex-col gap-1">
            {allUsers.map((u) => (
              <div key={u.id} className="flex flex-col gap-0.5 p-1.5 rounded-md bg-surface-secondary">
                <div className="flex items-center gap-1.5">
                  <button
                    onClick={() => navigate(userDetailPath(u))}
                    className="text-xs font-medium hover:text-primary-600 hover:underline transition-colors text-left"
                  >
                    {u.name}
                  </button>
                  {u.role && (
                    <Badge variant={u.role === 'member' ? 'info' : u.role === 'servant' ? 'warning' : 'default'} className="text-[10px]">
                      {roleLabel(u.role)}
                    </Badge>
                  )}
                  <button
                    onClick={(e) => { e.stopPropagation(); navigate(userDetailPath(u)) }}
                    className="ml-auto btn-ghost btn-sm py-0.5 px-1.5 gap-1"
                    title={t('common.view')}
                  >
                    <Eye className="h-3 w-3" />
                    <span className="hidden sm:inline text-[10px]">{t('common.view')}</span>
                  </button>
                </div>
                {(u.class_name || u.stage_name) && (
                  <div className="text-[10px] text-secondary">
                    {u.class_name && <>Class: {u.class_name}</>}
                    {u.class_name && u.stage_name && <span className="mx-1">·</span>}
                    {u.stage_name && <>Stage: {u.stage_name}</>}
                  </div>
                )}
              </div>
            ))}
            {allUsers.length > 5 && (
              <span className="text-[10px] text-muted text-center">+{allUsers.length - 5} more</span>
            )}
          </div>
        )
      },
    },
    { key: 'usage_label', header: t('qr.usage'), render: (q) => q.usage_label ?? (q.max_uses ? `0 / ${q.max_uses}` : (q.use_count > 0 ? String(q.use_count) : '-')) },
    { key: 'created_at', header: t('qr.createdAt'), render: (q) => new Date(q.created_at).toLocaleDateString() },
    { key: 'expires_at', header: t('qr.expiresAt'), render: (q) => new Date(q.expires_at).toLocaleDateString() },
  ]
  const [invites, setInvites] = useState<QRInvite[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [selectedUrl, setSelectedUrl] = useState<string | null>(null)
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)
  const [myClasses, setMyClasses] = useState<{ id: number; name: string }[]>([])
  const [createError, setCreateError] = useState('')
  const [maxUses, setMaxUses] = useState<number | ''>('')
  const [expiresIn, setExpiresIn] = useState<string>('24')

  const [filters, setFilters] = useState<Record<string, string | number>>({})
  const [searchInput, setSearchInput] = useState('')

  const fetch = async (page = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { page, per_page: 15, ...filters }
      if (params.search === '') delete params.search
      const res = await listQRInvites(params); setInvites(res.data); setMeta(res.meta)
    } finally { setLoading(false) }
  }

  useEffect(() => { fetch() }, [filters])
  useEffect(() => {
    getMyClasses()
      .then(setMyClasses)
      .catch(() => toast.error(t('common.failedToLoad')))
  }, [])

  const handleSearch = () => {
    if (searchInput.trim()) {
      setFilters(f => ({ ...f, search: searchInput.trim() }))
    } else {
      const newFilters = { ...filters }
      delete newFilters.search
      setFilters(newFilters)
    }
  }

  const handleClassFilter = (classId: string) => {
    if (classId) {
      setFilters(f => ({ ...f, class_id: classId }))
    } else {
      const newFilters = { ...filters }
      delete newFilters.class_id
      setFilters(newFilters)
    }
  }

  const handleExpiresFrom = (date: string) => {
    if (date) {
      setFilters(f => ({ ...f, expires_from: date }))
    } else {
      const newFilters = { ...filters }
      delete newFilters.expires_from
      setFilters(newFilters)
    }
  }

  const handleExpiresTo = (date: string) => {
    if (date) {
      setFilters(f => ({ ...f, expires_to: date }))
    } else {
      const newFilters = { ...filters }
      delete newFilters.expires_to
      setFilters(newFilters)
    }
  }

  const resetForm = () => {
    setMaxUses('')
    setExpiresIn('24')
    setCreateError('')
  }

  const handleCreate = async (type: QRInviteType) => {
    setCreateError('')
    try {
      const payload: { type: QRInviteType; max_uses?: number; expires_in_hours?: number } = { type }
      if (maxUses !== '') payload.max_uses = Number(maxUses)
      if (expiresIn) payload.expires_in_hours = Number(expiresIn)
      const result = await createQRInvite(payload)
      setSelectedUrl(result.url)
      const dataUrl = await QRCodeLib.toDataURL(result.url, { width: 400, margin: 2 })
      setQrDataUrl(dataUrl)
      setShowCreate(false)
      resetForm()
      fetch()
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      setCreateError(Object.values(axiosErr?.response?.data?.errors || {}).flat().join(', ') || axiosErr?.response?.data?.message || t('common.saving'))
    }
  }

  const handleCloseResult = () => { setSelectedUrl(null); setQrDataUrl(null) }

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1">
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted" />
            <input
              type="text"
              placeholder={t('common.search')}
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              className="input-field pl-8 text-sm w-32 sm:w-40"
            />
          </div>
          {filters.search && (
            <button onClick={() => { setSearchInput(''); const f = { ...filters }; delete f.search; setFilters(f) }} className="btn-ghost btn-sm p-1">
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        <select value={(filters.status as string) || ''} onChange={(e) => setFilters(f => ({ ...f, status: e.target.value }))} className="input-field text-sm w-24 sm:w-28">
          <option value="">{t('common.all')}</option>
          <option value="unused">{t('qr.statusUnused')}</option>
          <option value="used">{t('qr.statusUsed')}</option>
          <option value="expired">{t('qr.statusExpired')}</option>
          <option value="revoked">{t('qr.statusRevoked')}</option>
        </select>
        {myClasses.length > 1 && (
          <select value={(filters.class_id as string) || ''} onChange={(e) => handleClassFilter(e.target.value)} className="input-field text-sm w-32 sm:w-40">
            <option value="">{t('qr.allClasses')}</option>
            {myClasses.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        )}
        <input type="date" value={(filters.expires_from as string) || ''} onChange={(e) => handleExpiresFrom(e.target.value)}
          className="input-field text-sm w-32 sm:w-36" placeholder={t('context.from')} />
        <input type="date" value={(filters.expires_to as string) || ''} onChange={(e) => handleExpiresTo(e.target.value)}
          className="input-field text-sm w-32 sm:w-36" placeholder={t('context.to')} />
        <p className="text-sm text-secondary whitespace-nowrap">{meta.total} {t('common.total')}</p>
        <div className="flex items-center gap-2 sm:ml-auto">
          <div className="group relative">
            <Info className="h-4 w-4 text-secondary cursor-help" />
            <div className="absolute right-0 top-full mt-1 w-64 rounded-lg bg-surface p-3 text-xs text-secondary shadow-lg border border-border opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
              {t('qr.inviteHelpText')}
            </div>
          </div>
          <button onClick={() => { setCreateError(''); setShowCreate(true) }}
            className="btn-primary btn-md">
            <Plus className="h-4 w-4" /> {t('qr.createInvite')}
          </button>
        </div>
      </div>

      <DataTable
        columns={[
          ...columns,
          {
            key: 'actions',
            header: '',
            render: (q) => (
              <div className="flex items-center gap-1">
                {q.status === 'unused' || q.status === 'partial' ? (
                  <button onClick={() => revokeQRInvite(q.id).then(() => fetch())} className="btn-ghost btn-sm text-red-500" title={t('qr.revoke')}>
                    <X className="h-3.5 w-3.5" />
                  </button>
                ) : null}
              </div>
            ),
          },
        ]}
        data={invites}
        meta={meta}
        isLoading={loading}
        onPageChange={fetch}
      />

      <Modal isOpen={showCreate} onClose={() => { setShowCreate(false); resetForm() }} title={t('qr.createInviteTitle')}>
        <div className="space-y-3">
          <div className="rounded-lg bg-primary-50 dark:bg-primary-900/20 p-3 text-xs text-primary-700 dark:text-primary-400">
            <Info className="inline h-3 w-3 mr-1" />
            {t('qr.inviteHelpText')}
          </div>
          {createError && <div className="form-error">{createError}</div>}

          <div className="rounded-xl border border-border bg-surface-secondary p-4 space-y-4">
            <p className="font-medium">{t('qr.inviteSettings')}</p>

            <div>
              <label className="block text-xs font-medium text-secondary mb-1">{t('qr.maxUses')}</label>
              <input type="number" min="1" max="999999" value={maxUses} onChange={(e) => setMaxUses(e.target.value ? Number(e.target.value) : '')}
                placeholder={t('qr.maxUsesPlaceholder')} className="input-field" />
              <p className="text-xs text-muted mt-1">{t('qr.maxUsesHelp')}</p>
            </div>

            <div>
              <label className="block text-xs font-medium text-secondary mb-1">{t('qr.expiration')}</label>
              <select value={expiresIn} onChange={(e) => setExpiresIn(e.target.value)} className="input-field">
                <option value="24">{t('qr.expires24h')}</option>
                <option value="48">{t('qr.expires48h')}</option>
                <option value="72">{t('qr.expires72h')}</option>
                <option value="168">{t('qr.expires1w')}</option>
                <option value="720">{t('qr.expires30d')}</option>
              </select>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-surface-secondary p-4">
            <p className="font-medium">{t('qr.memberInvite')}</p>
            <p className="text-sm text-secondary">{t('qr.memberInviteNoClass')}</p>
            <button onClick={() => handleCreate('servant_to_member_invite')}
              className="btn-primary btn-md mt-3 w-full">{t('qr.createMemberInvite')}</button>
          </div>

        </div>
      </Modal>

      <Modal isOpen={!!selectedUrl} onClose={handleCloseResult} title={t('qr.createdModalTitle')} size="lg">
        <div className="flex flex-col items-center space-y-4">
          {qrDataUrl && <img src={qrDataUrl} alt="QR Code" className="h-64 w-64 max-w-full" />}
          <p className="text-sm text-secondary">{t('qr.sharePrompt')}</p>
          <div className="w-full rounded-lg bg-surface-secondary p-4 break-all">
            <code className="text-sm whitespace-pre-wrap break-all">{selectedUrl}</code>
          </div>
          <button onClick={() => { navigator.clipboard.writeText(selectedUrl ?? ''); toast.success(t('common.copied')); handleCloseResult() }}
            className="btn-primary btn-md w-full">
            <Copy className="h-4 w-4" /> {t('qr.copyUrl')}
          </button>
        </div>
      </Modal>
    </div>
  )
}
