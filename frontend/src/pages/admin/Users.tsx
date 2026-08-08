import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Eye, EyeOff, Loader2, Plus, Search } from 'lucide-react'
import Badge from '@/components/common/Badge'
import CopyButton from '@/components/common/CopyButton'
import DataTable from '@/components/common/DataTable'
import Modal from '@/components/common/Modal'
import { useTheme } from '@/hooks/useTheme'
import type { Column } from '@/components/common/DataTable'
import type { Classe, CreateUserPayload, Stage, User, UserRole } from '@/types'
import { listUsers, createUser } from '@/api/users'
import { listStages, getStageClasses } from '@/api/stages'
import { roleBadgeVariant, roleTranslationKey } from '@/lib/roles'
import { validatePhone as validatePhoneUtil, filterPhoneInput } from '@/lib/phoneValidation'
import { logCatch } from '@/lib/debug'
import type { AxiosError } from 'axios'

interface FormErrors {
  name?: string
  email?: string
  password?: string
  password_confirmation?: string
  role?: string
  phone?: string
  stage_id?: string
  class_id?: string
  member_id?: string
  server?: string
}

const initialForm: CreateUserPayload = {
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'member',
  birthday: null,
  class_id: null,
  phone: null,
  address: null,
  member_id: null,
  member_address: null,
  is_active: true,
}

export default function AdminUsers() {
  const { t } = useTranslation()
  const { dir } = useTheme()
  const navigate = useNavigate()
  const [users, setUsers] = useState<User[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [stages, setStages] = useState<Stage[]>([])
  const [stagesLoading, setStagesLoading] = useState(false)
  const [stagesError, setStagesError] = useState<string | null>(null)
  const [selectedStageId, setSelectedStageId] = useState<number | null>(null)
  const [classes, setClasses] = useState<Classe[]>([])
  const [classesLoading, setClassesLoading] = useState(false)
  const [classesError, setClassesError] = useState<string | null>(null)
  const [form, setForm] = useState<CreateUserPayload>({ ...initialForm })
  const [errors, setErrors] = useState<FormErrors>({})
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false)
  const [search, setSearch] = useState('')
  const searchTimeout = useRef<ReturnType<typeof setTimeout>>(undefined)
  const nameRef = useRef<HTMLInputElement>(null)

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

  const fetchStages = useCallback(async () => {
    setStagesLoading(true)
    setStagesError(null)
    try {
      const data = await listStages()
      setStages(data)
    } catch (e) {
      logCatch('AdminUsers.listStages', e)
      setStagesError(t('common.failedToLoad'))
    } finally {
      setStagesLoading(false)
    }
  }, [t])

  useEffect(() => {
    let active = true
    listStages()
      .then((data) => {
        if (active) setStages(data)
      })
      .catch((error) => {
        logCatch('AdminUsers.listStages', error)
        if (active) setStagesError(t('common.failedToLoad'))
      })
      .finally(() => {
        if (active) setStagesLoading(false)
      })
    return () => { active = false }
  }, [t])

  const handleStageChange = useCallback(async (stageId: number | null) => {
    setSelectedStageId(stageId)
    setForm((prev) => ({ ...prev, class_id: null }))
    if (!stageId) {
      setClasses([])
      return
    }
    setClassesLoading(true)
    setClassesError(null)
    try {
      const data = await getStageClasses(stageId)
      setClasses(data)
    } catch (e) {
      logCatch('AdminUsers.getStageClasses', e)
      setClassesError(t('common.failedToLoad'))
    } finally {
      setClassesLoading(false)
    }
  }, [t])

  useEffect(() => {
    if (showCreate && nameRef.current) {
      setTimeout(() => nameRef.current?.focus(), 100)
    }
  }, [showCreate])

  const validatePhone = (phone: string | null | undefined): string => {
    if (!phone) return ''
    return validatePhoneUtil(phone, t)
  }

  const handlePhoneChange = (value: string) => {
    const digits = filterPhoneInput(value)
    setForm({ ...form, phone: digits })
    if (digits && validatePhone(digits)) {
      setErrors((prev) => ({ ...prev, phone: validatePhone(digits) }))
    } else {
      setErrors((prev) => ({ ...prev, phone: undefined }))
    }
  }

  const validateForm = (): boolean => {
    const newErrors: FormErrors = {}

    if (!form.name.trim()) {
      newErrors.name = t('validation.nameRequired')
    }

    if (!form.email.trim()) {
      newErrors.email = t('auth.emailRequired')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      newErrors.email = t('auth.emailInvalid')
    }

    if (!form.password) {
      newErrors.password = t('auth.passwordRequired')
    } else if (form.password.length < 8) {
      newErrors.password = t('auth.passwordMin')
    } else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_\-+=]).+$/.test(form.password)) {
      newErrors.password = t('auth.passwordFormat')
    }

    if (!form.password_confirmation) {
      newErrors.password_confirmation = t('auth.confirmPasswordRequired')
    } else if (form.password !== form.password_confirmation) {
      newErrors.password_confirmation = t('auth.passwordsDoNotMatch')
    }

    if (!form.role) {
      newErrors.role = t('validation.required')
    }

    if (form.role === 'member') {
      if (!form.class_id) {
        newErrors.class_id = t('validation.required')
      }
    }

    const phoneErr = validatePhone(form.phone)
    if (phoneErr) {
      newErrors.phone = phoneErr
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const openCreateModal = () => {
    setForm({ ...initialForm })
    setErrors({})
    setSubmitting(false)
    setShowPassword(false)
    setShowPasswordConfirmation(false)
    setSelectedStageId(null)
    setClasses([])
    setShowCreate(true)
  }

  const handleCreate = async () => {
    if (!validateForm()) return

    setSubmitting(true)
    setErrors({})
    try {
      const payload: CreateUserPayload = {
        name: form.name.trim(),
        email: form.email.trim().toLowerCase(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        role: form.role,
        birthday: form.birthday || null,
        class_id: form.class_id,
        phone: form.phone || null,
        address: form.address || null,
        member_id: form.member_id || null,
        member_address: form.member_address || null,
        is_active: form.is_active,
      }

      await createUser(payload)
      setShowCreate(false)
      setForm({ ...initialForm })
      setErrors({})
      setSelectedStageId(null)
      setClasses([])
      toast.success(t('users.created'))
      fetchUsers()
    } catch (err) {
      const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
      if (axiosErr.response?.data?.errors) {
        const serverErrors: FormErrors = {}
        const errData = axiosErr.response.data.errors
        for (const [field, messages] of Object.entries(errData)) {
          if (field === 'password') serverErrors.password = messages[0]
          else if (field === 'email') serverErrors.email = messages[0]
          else if (field === 'name') serverErrors.name = messages[0]
          else if (field === 'phone') serverErrors.phone = messages[0]
          else if (field === 'role') serverErrors.role = messages[0]
          else if (field === 'class_id') serverErrors.class_id = messages[0]
          else if (field === 'member_id') serverErrors.member_id = messages[0]
          else serverErrors.server = messages[0]
        }
        setErrors(serverErrors)
      } else {
        setErrors({ server: axiosErr.response?.data?.message || t('common.failedToSave') })
      }
    } finally {
      setSubmitting(false)
    }
  }

  const handleClose = () => {
    if (submitting) return
    setShowCreate(false)
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
              aria-label={t('common.search')}
            />
          </div>
          <button onClick={openCreateModal} className="btn-primary btn-md flex items-center gap-2 shrink-0">
            <Plus className="h-4 w-4" />
            {t('users.createUser')}
          </button>
        </div>
      </div>

      <DataTable columns={columns} data={users} meta={meta} isLoading={loading} onPageChange={fetchUsers} onRowClick={(u) => navigate(`/admin/users/${u.id}`)} />

      <Modal
        isOpen={showCreate}
        onClose={handleClose}
        title={t('users.createUser')}
        size="lg"
        footer={
          <div className="flex gap-3 w-full">
            <button
              onClick={handleClose}
              disabled={submitting}
              className="flex-1 btn-secondary btn-md disabled:opacity-50"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={handleCreate}
              disabled={submitting}
              className="flex-1 btn-primary btn-md inline-flex items-center justify-center gap-2 disabled:opacity-50"
            >
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? t('common.saving') : t('common.create')}
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          {errors.server && (
            <div className="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-3" role="alert">
              <p className="text-sm text-red-700 dark:text-red-300">{errors.server}</p>
            </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <label htmlFor="create-user-name" className="label">
                {t('auth.name')} <span className="text-danger">*</span>
              </label>
              <input
                id="create-user-name"
                ref={nameRef}
                placeholder={t('users.namePlaceholder')}
                value={form.name}
                onChange={(e) => { setForm({ ...form, name: e.target.value }); setErrors((prev) => ({ ...prev, name: undefined })) }}
                className={`input-field w-full ${errors.name ? 'error' : ''}`}
                disabled={submitting}
                aria-invalid={!!errors.name}
                aria-describedby={errors.name ? 'create-user-name-error' : undefined}
              />
              {errors.name && <p id="create-user-name-error" className="form-error text-xs">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-email" className="label">
                {t('auth.email')} <span className="text-danger">*</span>
              </label>
              <input
                id="create-user-email"
                type="email"
                placeholder={t('users.emailPlaceholder')}
                value={form.email}
                onChange={(e) => { setForm({ ...form, email: e.target.value }); setErrors((prev) => ({ ...prev, email: undefined })) }}
                className={`input-field w-full ${errors.email ? 'error' : ''}`}
                disabled={submitting}
                autoComplete="email"
                aria-invalid={!!errors.email}
                aria-describedby={errors.email ? 'create-user-email-error' : undefined}
              />
              {errors.email && <p id="create-user-email-error" className="form-error text-xs">{errors.email}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-password" className="label">
                {t('auth.password')} <span className="text-danger">*</span>
              </label>
              <div className="relative">
                <input
                  id="create-user-password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder={t('auth.passwordPlaceholder')}
                  value={form.password}
                  onChange={(e) => { setForm({ ...form, password: e.target.value }); setErrors((prev) => ({ ...prev, password: undefined })) }}
                  className={`input-field w-full pr-10 ${errors.password ? 'error' : ''}`}
                  disabled={submitting}
                  autoComplete="new-password"
                  aria-invalid={!!errors.password}
                  aria-describedby={errors.password ? 'create-user-password-error' : undefined}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className={`absolute top-1/2 -translate-y-1/2 ${dir === 'rtl' ? 'left-3' : 'right-3'} text-muted hover:text-secondary transition-colors`}
                  aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')}
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              {errors.password && <p id="create-user-password-error" className="form-error text-xs">{errors.password}</p>}
              <p className="text-xs text-muted mt-1 leading-relaxed">{t('auth.passwordMin')} — {t('auth.passwordFormat')}</p>
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-password-confirm" className="label">
                {t('auth.confirmPassword')} <span className="text-danger">*</span>
              </label>
              <div className="relative">
                <input
                  id="create-user-password-confirm"
                  type={showPasswordConfirmation ? 'text' : 'password'}
                  placeholder={t('auth.confirmPasswordPlaceholder')}
                  value={form.password_confirmation}
                  onChange={(e) => { setForm({ ...form, password_confirmation: e.target.value }); setErrors((prev) => ({ ...prev, password_confirmation: undefined })) }}
                  className={`input-field w-full pr-10 ${errors.password_confirmation ? 'error' : ''}`}
                  disabled={submitting}
                  autoComplete="new-password"
                  aria-invalid={!!errors.password_confirmation}
                  aria-describedby={errors.password_confirmation ? 'create-user-password-confirm-error' : undefined}
                />
                <button
                  type="button"
                  onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                  className={`absolute top-1/2 -translate-y-1/2 ${dir === 'rtl' ? 'left-3' : 'right-3'} text-muted hover:text-secondary transition-colors`}
                  aria-label={showPasswordConfirmation ? t('auth.hidePassword') : t('auth.showPassword')}
                  tabIndex={-1}
                >
                  {showPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              {errors.password_confirmation && <p id="create-user-password-confirm-error" className="form-error text-xs">{errors.password_confirmation}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-role" className="label">
                {t('users.role')} <span className="text-danger">*</span>
              </label>
              <select
                id="create-user-role"
                value={form.role}
                onChange={(e) => { setForm({ ...form, role: e.target.value as UserRole }); setErrors((prev) => ({ ...prev, role: undefined })) }}
                className={`input-field w-full ${errors.role ? 'error' : ''}`}
                disabled={submitting}
                aria-invalid={!!errors.role}
                aria-describedby={errors.role ? 'create-user-role-error' : undefined}
              >
                <option value="member">{t('users.roleMember')}</option>
                <option value="servant">{t('users.roleServant')}</option>
                <option value="assistant_admin">{t('users.roleAssistantAdmin')}</option>
                <option value="admin">{t('users.roleAdmin')}</option>
              </select>
              {errors.role && <p id="create-user-role-error" className="form-error text-xs">{errors.role}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-member-id" className="label">
                {t('users.memberIdLabel')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              <input
                id="create-user-member-id"
                placeholder="MBR-000001"
                value={form.member_id ?? ''}
                onChange={(e) => { setForm({ ...form, member_id: e.target.value || null }); setErrors((prev) => ({ ...prev, member_id: undefined })) }}
                className={`input-field w-full font-mono text-sm ${errors.member_id ? 'error' : ''}`}
                disabled={submitting}
                aria-invalid={!!errors.member_id}
                aria-describedby={errors.member_id ? 'create-user-member-id-error' : undefined}
              />
              {errors.member_id && <p id="create-user-member-id-error" className="form-error text-xs">{errors.member_id}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-birthday" className="label">
                {t('auth.birthday')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              <input
                id="create-user-birthday"
                type="date"
                value={form.birthday ?? ''}
                onChange={(e) => setForm({ ...form, birthday: e.target.value || null })}
                className="input-field w-full"
                disabled={submitting}
              />
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-stage" className="label">
                {t('structure.stage')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              {stagesLoading ? (
                <div className="input-field w-full flex items-center gap-2 text-muted text-sm">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('common.loading')}
                </div>
              ) : stagesError ? (
                <div className="flex items-center gap-2">
                  <p className="text-sm text-danger">{stagesError}</p>
                  <button onClick={fetchStages} className="text-xs text-primary underline hover:no-underline" type="button">
                    {t('common.retry')}
                  </button>
                </div>
              ) : stages.length > 0 ? (
                <select
                  id="create-user-stage"
                  value={selectedStageId ?? ''}
                  onChange={(e) => handleStageChange(e.target.value ? Number(e.target.value) : null)}
                  className="input-field w-full"
                  disabled={submitting}
                >
                  <option value="">{t('structure.selectStage')}</option>
                  {stages.map((s) => (<option key={s.id} value={s.id}>{s.name}</option>))}
                </select>
              ) : (
                <p className="text-sm text-secondary">{t('structure.noStages')}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-class" className="label">
                {t('users.class')} {form.role === 'member' ? <span className="text-danger">*</span> : <span className="text-xs text-muted">({t('common.optional')})</span>}
              </label>
              {!selectedStageId ? (
                <p className="text-sm text-secondary">{t('structure.selectStageFirst')}</p>
              ) : classesLoading ? (
                <div className="input-field w-full flex items-center gap-2 text-muted text-sm">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('common.loading')}
                </div>
              ) : classesError ? (
                <div className="flex items-center gap-2">
                  <p className="text-sm text-danger">{classesError}</p>
                  <button onClick={() => handleStageChange(selectedStageId)} className="text-xs text-primary underline hover:no-underline" type="button">
                    {t('common.retry')}
                  </button>
                </div>
              ) : classes.length > 0 ? (
                <select
                  id="create-user-class"
                  value={form.class_id ?? ''}
                  onChange={(e) => { setForm({ ...form, class_id: e.target.value ? Number(e.target.value) : null }); setErrors((prev) => ({ ...prev, class_id: undefined })) }}
                  className={`input-field w-full ${errors.class_id ? 'error' : ''}`}
                  disabled={submitting}
                  aria-invalid={!!errors.class_id}
                >
                  <option value="">{t('absentMembers.selectClass')}</option>
                  {classes.map((c) => (<option key={c.id} value={c.id}>{c.name}</option>))}
                </select>
              ) : (
                <p className="text-sm text-secondary">{t('structure.noClasses')}</p>
              )}
              {errors.class_id && <p className="form-error text-xs">{errors.class_id}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-phone" className="label">
                {t('auth.phone')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              <input
                id="create-user-phone"
                type="tel"
                inputMode="numeric"
                placeholder={t('users.phonePlaceholder')}
                value={form.phone ?? ''}
                onChange={(e) => handlePhoneChange(e.target.value)}
                onBlur={() => {
                  const err = validatePhone(form.phone)
                  if (err) setErrors((prev) => ({ ...prev, phone: err }))
                }}
                className={`input-field w-full ${errors.phone ? 'error' : ''}`}
                disabled={submitting}
                autoComplete="tel"
                aria-invalid={!!errors.phone}
                aria-describedby={errors.phone ? 'create-user-phone-error' : undefined}
              />
              {errors.phone && <p id="create-user-phone-error" className="form-error text-xs">{errors.phone}</p>}
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-address" className="label">
                {t('common.address')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              <input
                id="create-user-address"
                placeholder={t('users.addressPlaceholder')}
                value={form.address ?? ''}
                onChange={(e) => setForm({ ...form, address: e.target.value || null })}
                className="input-field w-full"
                disabled={submitting}
              />
            </div>

            <div className="space-y-1.5">
              <label htmlFor="create-user-member-address" className="label">
                {t('auth.memberAddress')} <span className="text-xs text-muted">({t('common.optional')})</span>
              </label>
              <input
                id="create-user-member-address"
                placeholder={t('auth.memberAddressPlaceholder')}
                value={form.member_address ?? ''}
                onChange={(e) => setForm({ ...form, member_address: e.target.value || null })}
                className="input-field w-full"
                disabled={submitting}
              />
            </div>

            <div className="flex items-end pb-2">
              <label className="relative inline-flex items-center cursor-pointer gap-3 group">
                <input
                  type="checkbox"
                  checked={form.is_active ?? true}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  className="sr-only peer"
                  disabled={submitting}
                />
                <div className="peer w-11 h-6 rounded-full border-2 bg-gray-300 dark:bg-gray-600 border-gray-400 dark:border-gray-500 peer-checked:bg-success peer-checked:border-success relative transition-all duration-300 ease-in-out after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:rounded-full after:h-[18px] after:w-[18px] after:shadow-sm after:transition-all after:duration-300 after:ease-in-out peer-checked:after:translate-x-[18px] rtl:peer-checked:after:-translate-x-[18px] group-hover:opacity-80 peer-disabled:opacity-50 peer-disabled:cursor-not-allowed peer-focus-visible:ring-2 peer-focus-visible:ring-success/30 peer-focus-visible:outline-none"></div>
                <span className={`text-sm font-medium select-none transition-colors duration-300 ${(form.is_active ?? true) ? 'text-success' : 'text-muted'}`}>
                  {(form.is_active ?? true) ? t('common.active') : t('common.inactive')}
                </span>
              </label>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  )
}
