import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { getInviteDetails, acceptInvite } from '@/api/qr'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Church, XCircle, CheckCircle, Loader2, Eye, EyeOff, Sun, Moon, Languages } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import { roleTranslationKey } from '@/lib/roles'
import type { UserRole } from '@/types'

type PageState = 'loading' | 'invalid' | 'details' | 'form' | 'success' | 'error'

export default function InviteLanding() {
  const { token } = useParams<{ token: string }>()
  const navigate = useNavigate()
  const { isAuthenticated, register: authRegister, logout } = useAuth()
  const { t } = useTranslation()
  const { theme, toggleTheme, language, setLanguage, dir } = useTheme()

  const [state, setState] = useState<PageState>('loading')
  const [details, setDetails] = useState<Awaited<ReturnType<typeof getInviteDetails>> | null>(null)
  const [errorMsg, setErrorMsg] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [form, setForm] = useState({
    name: '', email: '', birthday: '', phone: '',
    class_id: '', password: '', password_confirmation: '',
    member_address: '',
  })
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [passwordError, setPasswordError] = useState('')
  const [classError, setClassError] = useState('')

  useEffect(() => {
    if (!token) {
      setErrorMsg(t('auth.noTokenProvided'))
      setState('invalid')
      return
    }
    getInviteDetails(token).then((res) => {
      if (res.valid) {
        setDetails(res)
        setClasses(res.classes ?? [])
        setState(isAuthenticated ? 'details' : 'form')
      } else if (res.is_expired) { setErrorMsg(t('auth.inviteExpired')); setState('invalid') }
      else if (res.is_used) { setErrorMsg(t('auth.inviteUsed')); setState('invalid') }
      else if (res.is_revoked) { setErrorMsg(t('auth.inviteRevoked')); setState('invalid') }
      else { setErrorMsg(t('auth.inviteInvalid')); setState('invalid') }
    }).catch(() => { setErrorMsg(t('auth.invalidToken')); setState('invalid') })
  }, [token, isAuthenticated])

  const validateForm = (): boolean => {
    let valid = true
    setPasswordError('')
    setClassError('')
    if (form.password !== form.password_confirmation) { setPasswordError(t('auth.passwordsDoNotMatch')); valid = false }
    if (!form.class_id && classes.length > 0) { setClassError(t('auth.selectClass')); valid = false }
    return valid
  }

  const handleAccept = async () => {
    if (!token) return
    setSubmitting(true)
    setErrorMsg('')
    try {
      await acceptInvite(token)
      await logout()
      setState('success')
      setTimeout(() => navigate('/login'), 2000)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string }; status?: number } }
      if (axiosErr?.response?.status === 401) { setState('form') }
      else {
        const msg = Object.values(axiosErr?.response?.data?.errors || {}).flat().join(', ') ||
          axiosErr?.response?.data?.message || t('auth.failedAccept')
        setErrorMsg(msg); toast.error(msg)
      }
    } finally { setSubmitting(false) }
  }

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!token || !validateForm()) return
    setSubmitting(true)
    setErrorMsg('')
    try {
      const payload: Record<string, unknown> = {
        name: form.name, email: form.email, password: form.password,
        password_confirmation: form.password_confirmation, invite_token: token,
        ...(form.class_id ? { class_id: Number(form.class_id) } : {}),
        ...(form.birthday ? { birthday: form.birthday } : {}),
        ...(form.phone ? { phone: form.phone } : {}),
        ...(form.member_address ? { member_address: form.member_address } : {}),
      }
      await authRegister(payload)
      setState('success')
      setTimeout(() => navigate('/login'), 2000)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const msg = Object.values(axiosErr?.response?.data?.errors || {}).flat().join(', ') ||
        axiosErr?.response?.data?.message || t('auth.registerFailed')
      setErrorMsg(msg); toast.error(msg)
    } finally { setSubmitting(false) }
  }

  const toggleLang = () => setLanguage(language === 'en' ? 'ar' : 'en')

  function TopBar() {
    return (
      <div className="mb-4 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600">
            <Church className="h-5 w-5 text-white" />
          </div>
          <span className="text-sm font-bold">{t('app.name')}</span>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={toggleTheme} className="btn-ghost btn-icon rounded-lg" aria-label={t('theme.toggleTheme')}>
            {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
          </button>
          <button onClick={toggleLang} className="btn-ghost btn-sm border min-w-[40px]">
            {language === 'en' ? 'AR' : 'EN'}
          </button>
        </div>
      </div>
    )
  }

  if (state === 'loading') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <LoadingSpinner className="py-20" />
      </div>
    )
  }

  if (state === 'invalid') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <div className="w-full max-w-md">
          <TopBar />
          <div className="card p-8 text-center">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-danger-light">
              <XCircle className="h-8 w-8 text-danger" />
            </div>
            <h2 className="text-lg font-semibold">{t('errors.forbidden')}</h2>
            <p className="mt-2 text-sm text-secondary">{errorMsg}</p>
            <button onClick={() => navigate('/login')} className="btn-primary btn-md mt-6">
              {t('errors.goLogin')}
            </button>
          </div>
        </div>
      </div>
    )
  }

  if (state === 'success') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <div className="w-full max-w-md">
          <TopBar />
          <div className="card p-8 text-center">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-success-light">
              <CheckCircle className="h-8 w-8 text-success" />
            </div>
            <h2 className="text-lg font-semibold">{t('common.confirm')}</h2>
            <p className="mt-2 text-sm text-secondary">{t('auth.registeredSuccessfully')}</p>
            <p className="mt-1 text-xs text-muted">{t('auth.loginAfterRegister')}</p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
      <div className="w-full max-w-md">
        <TopBar />
        <div className="card p-8">
          <div className="mb-6 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary-100 dark:bg-primary-900/50">
              <Church className="h-6 w-6 text-primary-600 dark:text-primary-400" />
            </div>
            <h1 className="mt-3 text-xl font-bold">{t('app.name')}</h1>
            {details && (
              <div className="mt-4 rounded-lg bg-primary-50 dark:bg-primary-900/20 p-4 text-left text-sm" style={{ textAlign: dir === 'rtl' ? 'right' : 'left' }}>
                <p className="font-medium text-primary-800 dark:text-primary-300">
                  {t('auth.registerViaInvite')} <span className="font-bold">{t(roleTranslationKey(details.role as UserRole))}</span>
                </p>
                {details.creator_name && (
                  <p className="mt-1 text-primary-600 dark:text-primary-400">{t('auth.createdBy')}: {details.creator_name}</p>
                )}
                {(details.class_name ?? details.creator_class_name) && (
                  <p className="mt-1 text-primary-600 dark:text-primary-400">
                    {t('auth.classYear')}: {details.class_name ?? details.creator_class_name}
                  </p>
                )}
                <p className="mt-2 text-primary-500 dark:text-primary-400 text-xs">
                  {t('auth.inviteLinkExpiry')}
                </p>
                <p className="mt-1 text-primary-500 dark:text-primary-400 text-xs">
                  {t('qr.expiresAt')}: {new Date(details.expires_at).toLocaleDateString()}
                </p>
              </div>
            )}
          </div>

          {state === 'details' && isAuthenticated && (
            <div className="space-y-4">
              <div className="rounded-lg bg-warning-light p-3 text-sm text-warning">
                {t('auth.acceptWillLogout')}
              </div>
              {errorMsg && (
                <div className="rounded-lg bg-danger-light p-3 text-sm text-danger">{errorMsg}</div>
              )}
              <button onClick={handleAccept} disabled={submitting} className="btn-primary btn-md w-full">
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                {submitting ? t('common.saving') : t('auth.acceptInvite')}
              </button>
            </div>
          )}

          {state === 'form' && !isAuthenticated && (
            <form onSubmit={handleRegister} className="space-y-4">
              <div>
                <label className="label">{t('auth.name')}</label>
                <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required className="input-field" />
              </div>
              <div>
                <label className="label">{t('auth.email')}</label>
                <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required className="input-field" />
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="label">{t('auth.birthday')}</label>
                  <input type="date" value={form.birthday} onChange={(e) => setForm({ ...form, birthday: e.target.value })} className="input-field" />
                </div>
                <div>
                  <label className="label">{t('auth.phone')}</label>
                  <input type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="input-field" placeholder={t('auth.phonePlaceholder')} />
                </div>
              </div>
              <div>
                <label className="label">{t('auth.memberAddress')}</label>
                <input type="text" value={form.member_address} onChange={(e) => setForm({ ...form, member_address: e.target.value })} className="input-field" placeholder={t('auth.memberAddressPlaceholder')} />
              </div>
              <div>
                <label className="label">{t('auth.classYear')} <span className="text-danger">*</span></label>
                {classes.length > 0 ? (
                  <select value={form.class_id} onChange={(e) => { setForm({ ...form, class_id: e.target.value }); setClassError('') }} className="input-field">
                    <option value="" disabled>{t('absentMembers.selectClass')}</option>
                    {classes.map((c) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                ) : (
                  <p className="text-sm text-secondary">{t('structure.noClasses')}</p>
                )}
                {classError && <p className="form-error">{classError}</p>}
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="label">{t('auth.password')}</label>
                  <div className="relative">
                    <input type={showPassword ? 'text' : 'password'} value={form.password}
                      onChange={(e) => { setForm({ ...form, password: e.target.value }); setPasswordError('') }} required minLength={8}
                      className="input-field pr-10" />
                    <button type="button" onClick={() => setShowPassword(!showPassword)}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-secondary hover:text-primary" tabIndex={-1}>
                      {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                    </button>
                  </div>
                  <p className="text-xs text-muted mt-1">{t('auth.passwordMin')}</p>
                </div>
                <div>
                  <label className="label">{t('common.confirm')}</label>
                  <div className="relative">
                    <input type={showConfirmPassword ? 'text' : 'password'} value={form.password_confirmation}
                      onChange={(e) => { setForm({ ...form, password_confirmation: e.target.value }); setPasswordError('') }} required minLength={8}
                      className="input-field pr-10" />
                    <button type="button" onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-secondary hover:text-primary" tabIndex={-1}>
                      {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                    </button>
                  </div>
                </div>
              </div>
              {passwordError && <p className="form-error">{passwordError}</p>}
              {errorMsg && (
                <div className="rounded-lg bg-danger-light p-3 text-sm text-danger">{errorMsg}</div>
              )}
              <button type="submit" disabled={submitting} className="btn-primary btn-md w-full">
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                {submitting ? t('common.saving') : t('auth.register')}
              </button>
              <p className="text-center text-xs text-muted">
                {t('auth.hasAccount')}{' '}
                <button type="button" onClick={() => navigate('/login')}
                  className="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                  {t('auth.signIn')}
                </button>
              </p>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
