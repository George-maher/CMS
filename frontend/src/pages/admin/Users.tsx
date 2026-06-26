import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Plus, Search } from 'lucide-react'
import Badge from '@/components/common/Badge'
import CopyButton from '@/components/common/CopyButton'
import DataTable from '@/components/common/DataTable'
import Modal from '@/components/common/Modal'
import { useTheme } from '@/contexts/ThemeContext'
import type { Column } from '@/components/common/DataTable'
import type { CreateUserPayload, User, UserRole } from '@/types'
import { listUsers, createUser } from '@/api/users'
import { listAllClasses } from '@/api/structure'
import { roleBadgeVariant, roleTranslationKey } from '@/lib/roles'

export default function AdminUsers() {
  const { t } = useTranslation()
  const { dir } = useTheme()
  const navigate = useNavigate()
  const [users, setUsers] = useState<User[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [form, setForm] = useState<CreateUserPayload>({ name: '', email: '', role: 'member' })
  const [search, setSearch] = useState('')
  const [phoneError, setPhoneError] = useState('')
  const searchTimeout = useRef<ReturnType<typeof setTimeout>>(undefined)

  const columns: Column<User>[] = [
    { key: 'member_id', header: t('users.memberIdLabel'), render: (u) => u.member_id ? (
      <span className="inline-flex items-center gap-1 font-mono text-xs">
        {u.member_id}
        <CopyButton value={u.member_id} iconSize={12} />
      </span>
    ) : <span className="font-mono text-xs">-</span> },
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: t('auth.email') },
    {
      key: 'role',
      header: t('users.role'),
      render: (u) => <Badge variant={roleBadgeVariant(u.role)}>{t(roleTranslationKey(u.role))}</Badge>,
    },
    { key: 'classe', header: t('users.class'), render: (u) => u.classe?.name ?? '-' },
    {
      key: 'is_active',
      header: t('common.status'),
      render: (u) => (
        <Badge variant={u.is_active ? 'success' : 'danger'}>
          {u.is_active ? t('common.active') : t('common.inactive')}
        </Badge>
      ),
    },
    { key: 'total_points', header: t('common.points') },
  ]

  const fetchUsers = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { page, per_page: 15 }
      if (search.trim()) params.search = search.trim()
      const res = await listUsers(params)
      setUsers(res.data)
      setMeta(res.meta)
    } finally { setLoading(false) }
  }, [search])

  useEffect(() => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current)
    searchTimeout.current = setTimeout(() => fetchUsers(), 400)
    return () => { if (searchTimeout.current) clearTimeout(searchTimeout.current) }
  }, [search, fetchUsers])

  useEffect(() => { listAllClasses().then(setClasses).catch(() => {}) }, [])

  const validatePhone = (phone: string | null | undefined): string => {
    if (!phone) return ''
    if (!/^[0-9]+$/.test(phone)) return t('validation.phoneOnlyNumbers')
    if (phone.length !== 11) return t('validation.phoneExact11')
    return ''
  }

  const handlePhoneChange = (value: string) => {
    const digits = value.replace(/[^0-9]/g, '')
    setForm({ ...form, phone: digits })
    if (digits && validatePhone(digits)) {
      setPhoneError(validatePhone(digits))
    } else {
      setPhoneError('')
    }
  }

  const handleCreate = async () => {
    const phoneErr = validatePhone(form.phone)
    if (phoneErr) {
      setPhoneError(phoneErr)
      toast.error(phoneErr)
      return
    }
    try {
      await createUser(form)
      setShowCreate(false)
      setForm({ name: '', email: '', role: 'member' })
      setPhoneError('')
      toast.success(t('common.create'))
      fetchUsers()
    } catch { toast.error(t('common.saving')) }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p className="text-sm text-secondary">{meta.total} {t('users.totalUsers')}</p>
        <div className="flex items-center gap-3 w-full sm:w-auto">
          <div className="relative flex-1 sm:flex-none">
            <Search className={`absolute ${dir === 'rtl' ? 'right-3' : 'left-3'} top-1/2 -translate-y-1/2 h-4 w-4 text-muted`} />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('attendance.searchMembers')}
              className={`input-field text-sm w-full sm:w-56 ${dir === 'rtl' ? 'pr-9' : 'pl-9'}`}
            />
          </div>
          <button onClick={() => setShowCreate(true)} className="btn-primary btn-md flex items-center gap-2 shrink-0">
            <Plus className="h-4 w-4" />
            {t('users.createUser')}
          </button>
        </div>
      </div>

      <DataTable columns={columns} data={users} meta={meta} isLoading={loading} onPageChange={fetchUsers} onRowClick={(u) => navigate(`/admin/users/${u.id}`)} />

      <Modal isOpen={showCreate} onClose={() => setShowCreate(false)} title={t('users.createUser')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowCreate(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleCreate} className="flex-1 btn-primary btn-md">{t('common.create')}</button>
          </div>
        }>
        <div className="space-y-4">
          <div>
            <label className="label">{t('auth.name')}</label>
            <input placeholder={t('users.namePlaceholder')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="input-field w-full" />
          </div>
          <div>
            <label className="label">{t('auth.email')}</label>
            <input placeholder={t('users.emailPlaceholder')} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}
              className="input-field w-full" />
          </div>
          <div>
            <label className="label">{t('users.role')}</label>
            <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}
              className="input-field w-full">
              <option value="member">{t('users.roleMember')}</option>
              <option value="servant">{t('users.roleServant')}</option>
              <option value="assistant_admin">{t('users.roleAssistantAdmin')}</option>
              <option value="admin">{t('users.roleAdmin')}</option>
            </select>
          </div>
          <div>
            <label className="label">{t('auth.birthday')}</label>
            <input placeholder={t('users.birthdayPlaceholder')} type="date" value={form.birthday ?? ''} onChange={(e) => setForm({ ...form, birthday: e.target.value })}
              className="input-field w-full" />
          </div>
          <div>
            <label className="label">{t('users.class')}</label>
            {classes.length > 0 ? (
              <select value={form.class_id ?? ''} onChange={(e) => setForm({ ...form, class_id: e.target.value ? Number(e.target.value) : null })}
                className="input-field w-full">
                <option value="">{t('absentMembers.selectClass')}</option>
                {classes.map((c) => (<option key={c.id} value={c.id}>{c.name}</option>))}
              </select>
            ) : (
              <p className="text-sm text-secondary">{t('structure.noClasses')}</p>
            )}
          </div>
          <div>
            <label className="label">{t('auth.phone')}</label>
            <input placeholder={t('users.phonePlaceholder')} value={form.phone ?? ''} onChange={(e) => handlePhoneChange(e.target.value)} onBlur={() => setPhoneError(validatePhone(form.phone))}
              className={`input-field w-full ${phoneError ? 'error' : ''}`} autoComplete="tel" />
            {phoneError && <p className="form-error flex items-center gap-1 mt-1"><span className="text-danger">⚠</span> {phoneError}</p>}
          </div>
          <div>
            <label className="label">{t('common.address')}</label>
            <input placeholder={t('users.addressPlaceholder')} value={form.address ?? ''} onChange={(e) => setForm({ ...form, address: e.target.value })}
              className="input-field w-full" />
          </div>
        </div>
      </Modal>
    </div>
  )
}