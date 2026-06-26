import { useEffect, useState } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { validateQRToken } from '@/api/qr'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Church, Eye, EyeOff, Loader2, XCircle, CheckCircle, Sun, Moon, Languages } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'

export default function InviteRegister() {
  const { register } = useAuth()
  const { t } = useTranslation()
  const { theme, toggleTheme, language, setLanguage, dir } = useTheme()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const navigate = useNavigate()

  const [validating, setValidating] = useState(true)
  const [inviteValid, setInviteValid] = useState(false)
  const [inviteType, setInviteType] = useState('')
  const [error, setError] = useState('')
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([])
  const [submitted, setSubmitted] = useState(false)
  const [form, setForm] = useState({
    name: '', email: '', birthday: '', phone: '', address: '', member_address: '',
    class_id: '', password: '', password_confirmation: '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [passwordError, setPasswordError] = useState('')
  const [classError, setClassError] = useState('')
  const [phoneError, setPhoneError] = useState('')

  useEffect(() => {
    if (!token) {
      setError(t('auth.noTokenProvided'))
      setValidating(false)
      return
    }
    validateQRToken(token).then((result) => {
      if (result.valid) {
        setInviteValid(true)
        setInviteType(result.type)
        setClasses(result.classes || [])
      } else {
        setError(t('auth.inviteExpired'))
      }
    }).catch((err: unknown) => {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setError(axiosErr?.response?.data?.message || t('auth.inviteExpired'))
    }).finally(() => setValidating(false))
  }, [token])

  const validatePhone = (phone: string): string => {
    if (!phone) return ''
    if (!/^[0-9]+$/.test(phone)) return t('validation.phoneOnlyNumbers')
    if (phone.length !== 11) return t('validation.phoneExact11')
    return ''
  }

  const handlePhoneChange = (value: string) => {
    const digits = value.replace(/[^0-9]/g, '')
    setForm({ ...form, phone: digits })
    setPhoneError(validatePhone(digits))
  }

  const validateForm = (): boolean => {
    let valid = true
    setPasswordError('')
    setClassError('')
    setPhoneError('')
    if (form.password !== form.password_confirmation) { setPasswordError(t('auth.passwordsDoNotMatch')); valid = false }
    if (!form.class_id && classes.length > 0) { setClassError(t('auth.selectClass')); valid = false }
    if (form.phone) {
      const phoneErr = validatePhone(form.phone)
      if (phoneErr) { setPhoneError(phoneErr); valid = false }
    }
    return valid
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validateForm()) return
    setSubmitting(true)
    setError('')
    try {
      const payload: Record<string, unknown> = {
        name: form.name, email: form.email, password: form.password,
        password_confirmation: form.password_confirmation, invite_token: token!,
        ...(form.class_id && classes.length > 0 ? { class_id: Number(form.class_id) } : {}),
        ...(form.birthday ? { birthday: form.birthday } : {}),
        ...(form.phone ? { phone: form.phone } : {}),
        ...(form.address ? { address: form.address } : {}),
        ...(form.member_address ? { member_address: form.member_address } : {}),
      }
      await register(payload as unknown as import('@/types').RegisterPayload)
      setSubmitted(true)
      setTimeout(() => navigate('/login'), 2000)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data
      const errText = msg?.errors ? Object.values(msg.errors).flat().join(', ') : msg?.message || t('auth.registerFailed')
      setError(errText)
      toast.error(errText)
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

  if (validating) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <div className="w-full max-w-md">
          <TopBar />
          <LoadingSpinner className="py-20" />
        </div>
      </div>
    )
  }

  if (submitted) {
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

  if (!inviteValid) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-secondary p-4">
        <div className="w-full max-w-md">
          <TopBar />
          <div className="card p-8 text-center">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-danger-light">
              <XCircle className="h-8 w-8 text-danger" />
            </div>
            <h2 className="text-lg font-semibold">{t('errors.forbidden')}</h2>
            <p className="mt-2 text-sm text-secondary">{error}</p>
            <button onClick={() => navigate('/login')} className="btn-primary btn-md mt-6">
              {t('errors.goLogin')}
            </button>
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
            <h2 className="mt-3 text-xl font-bold">{t('auth.inviteRegister')}</h2>
            <p className="mt-1 text-sm text-secondary">
              {inviteType === 'servant_to_member_invite' || inviteType === 'servant_invite' ? t('auth.invitedAsServant') : t('auth.invitedAsMember')}
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">{t('auth.name')}</label>
              <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required className="input-field" />
            </div>
            <div>
              <label className="label">{t('auth.email')}</label>
              <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required className="input-field" />
            </div>
            <div className="grid grid-cols-1 gap-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="label">{t('auth.birthday')}</label>
                  <input type="date" value={form.birthday} onChange={(e) => setForm({ ...form, birthday: e.target.value })} className="input-field" />
                </div>
                <div>
                  <label className="label">{t('auth.phone')}</label>
                  <input type="tel" value={form.phone} onChange={(e) => handlePhoneChange(e.target.value)} onBlur={() => setPhoneError(validatePhone(form.phone))} className={`input-field ${phoneError ? 'error' : ''}`} placeholder={t('auth.phonePlaceholder')} autoComplete="tel" />
                  {phoneError && <p className="form-error flex items-center gap-1 mt-1"><span className="text-danger">⚠</span> {phoneError}</p>}
                </div>
              </div>
              <div>
                <label className="label">{t('common.address')}</label>
                <input type="text" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} className="input-field" />
              </div>
              <div>
                <label className="label">{t('auth.memberAddress')}</label>
                <input type="text" value={form.member_address} onChange={(e) => setForm({ ...form, member_address: e.target.value })} className="input-field" placeholder={t('auth.memberAddressPlaceholder')} />
              </div>
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
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-gold-400" tabIndex={-1}>
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
                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-gold-400" tabIndex={-1}>
                    {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
              </div>
            </div>
            {passwordError && <p className="form-error">{passwordError}</p>}
            {error && (
              <div className="rounded-lg bg-danger-light p-3 text-sm text-danger">{error}</div>
            )}
            <button type="submit" disabled={submitting} className="btn-primary btn-md w-full">
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? t('common.saving') : t('auth.register')}
            </button>
            <p className="text-center text-xs text-muted">
              {t('auth.hasAccount')}{' '}
              <button type="button" onClick={() => navigate('/login')}
                className="font-medium gold-text hover:opacity-80">
                {t('auth.signIn')}
              </button>
            </p>
          </form>
        </div>
      </div>
    </div>
  )
}
