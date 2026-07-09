import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/hooks/useAuth'
import toast from 'react-hot-toast'
import { Church, Upload, ArrowLeft, Loader2, Eye, EyeOff, FileText, AlertTriangle, Clock, XCircle, CheckCircle, Info } from 'lucide-react'
import { submitChurchApplication, lookupApplicationByEmail } from '@/api/churchApplications'
import type { ChurchApplication } from '@/types'
import { validatePhone as validatePhoneUtil, filterPhoneInput } from '@/lib/phoneValidation'
import { logCatch } from '@/lib/debug'

const ACCEPTED_FORMATS = '.jpg,.jpeg,.png,.pdf'

export default function JoinNow() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { user: authUser, refreshUser } = useAuth()
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [idType, setIdType] = useState<'national_id' | 'church_permission'>('national_id')
  const [frontIdFile, setFrontIdFile] = useState<File | null>(null)
  const [backIdFile, setBackIdFile] = useState<File | null>(null)
  const [permissionDocFile, setPermissionDocFile] = useState<File | null>(null)
  const [frontPreview, setFrontPreview] = useState<string | null>(null)
  const [backPreview, setBackPreview] = useState<string | null>(null)
  const [permissionPreview, setPermissionPreview] = useState<string | null>(null)
  const [phoneError, setPhoneError] = useState('')
  const [existingApp, setExistingApp] = useState<ChurchApplication | null>(null)
  const [isLookingUp, setIsLookingUp] = useState(false)
  const [lookupMessage, setLookupMessage] = useState('')
  const [form, setForm] = useState({
    church_name: '',
    service_name: '',
    priest_name: '',
    main_servant_name: '',
    phone: '',
    address: '',
    email: '',
    password: '',
    password_confirmation: '',
  })

  const handleFileChange = (field: 'front' | 'back' | 'permission') => (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    if (field === 'front') {
      setFrontIdFile(file)
      if (file.type.startsWith('image/')) setFrontPreview(URL.createObjectURL(file))
      else setFrontPreview(null)
    } else if (field === 'back') {
      setBackIdFile(file)
      if (file.type.startsWith('image/')) setBackPreview(URL.createObjectURL(file))
      else setBackPreview(null)
    } else {
      setPermissionDocFile(file)
      if (file.type.startsWith('image/')) setPermissionPreview(URL.createObjectURL(file))
      else setPermissionPreview(null)
    }
  }

  const validatePhone = (phone: string): string => {
    if (!phone) return t('join.errorRequired')
    return validatePhoneUtil(phone, t)
  }

  const handlePhoneChange = (value: string) => {
    const digits = filterPhoneInput(value)
    setForm({ ...form, phone: digits })
    if (digits && validatePhone(digits)) {
      setPhoneError(validatePhone(digits))
    } else {
      setPhoneError('')
    }
  }

  const lookupEmail = async (email: string) => {
    const trimmed = email.trim()
    if (!trimmed || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) return
    setIsLookingUp(true)
    try {
      const result = await lookupApplicationByEmail(trimmed)
      if (result.application) {
        setExistingApp(result.application)
        setLookupMessage(result.message || '')
        setForm({
          church_name: result.application.church_name || '',
          service_name: result.application.service_name || '',
          priest_name: result.application.priest_name || '',
          main_servant_name: result.application.main_servant_name || '',
          phone: result.application.priest_phone?.replace(/[^0-9]/g, '').slice(0, 11) || result.application.phone?.replace(/[^0-9]/g, '').slice(0, 11) || '',
          address: result.application.address || '',
          email: result.application.contact_email || trimmed,
          password: '',
          password_confirmation: '',
        })
        setIdType(result.application.id_type === 'church_permission' ? 'church_permission' : 'national_id')
      } else {
        setExistingApp(null)
        setLookupMessage('')
      }
    } catch (e) {
      logCatch('JoinNow.lookupByEmail', e)
    } finally {
      setIsLookingUp(false)
    }
  }

  const handleEmailBlur = () => lookupEmail(form.email)

  useEffect(() => {
    const urlEmail = searchParams.get('email')
    const email = urlEmail || authUser?.email
    if (email) {
      queueMicrotask(() => setForm((prev) => ({ ...prev, email })))
      const trimmed = email.trim()
      if (trimmed && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
        queueMicrotask(() => setIsLookingUp(true))
        lookupApplicationByEmail(trimmed).then((result) => {
          if (result.application) {
            setExistingApp(result.application)
            setLookupMessage(result.message || '')
            setForm({
              church_name: result.application.church_name || '',
              service_name: result.application.service_name || '',
              priest_name: result.application.priest_name || '',
              main_servant_name: result.application.main_servant_name || '',
              phone: result.application.priest_phone?.replace(/[^0-9]/g, '').slice(0, 11) || result.application.phone?.replace(/[^0-9]/g, '').slice(0, 11) || '',
              address: result.application.address || '',
              email: result.application.contact_email || trimmed,
              password: '',
              password_confirmation: '',
            })
            setIdType(result.application.id_type === 'church_permission' ? 'church_permission' : 'national_id')
          } else {
            setExistingApp(null)
            setLookupMessage('')
          }
        }).catch(() => {}).finally(() => setIsLookingUp(false))
      }
    }
  }, [authUser, searchParams])

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    const phoneErr = validatePhone(form.phone)
    if (phoneErr) {
      setPhoneError(phoneErr)
      toast.error(phoneErr)
      return
    }
    if (!form.church_name || !form.priest_name || !form.main_servant_name) {
      toast.error(t('join.errorRequired'))
      return
    }
    if (!form.phone) {
      toast.error(t('join.errorRequired'))
      return
    }
    if (!form.email) {
      toast.error(t('join.errorRequired'))
      return
    }
    if (!form.address) {
      toast.error(t('join.errorAddressRequired'))
      return
    }
    if (!existingApp) {
      if (!form.password || form.password.length < 8) {
        toast.error(t('auth.passwordMinLength'))
        return
      }
      if (form.password !== form.password_confirmation) {
        toast.error(t('auth.passwordsDoNotMatch'))
        return
      }
    }
    if (idType === 'national_id') {
      if (!existingApp && (!frontIdFile || !backIdFile)) {
        toast.error(t('join.idRequired'))
        return
      }
      if (existingApp && !frontIdFile && !backIdFile && !existingApp.front_id_url && !existingApp.back_id_url) {
        toast.error(t('join.idRequired'))
        return
      }
    } else {
      if (!existingApp && !permissionDocFile) {
        toast.error(t('join.permissionRequired'))
        return
      }
      if (existingApp && !permissionDocFile && !existingApp.church_permission_doc_url) {
        toast.error(t('join.permissionRequired'))
        return
      }
    }
    setSubmitting(true)
    try {
      const fd = new FormData()
      fd.append('church_name', form.church_name)
      if (form.service_name) fd.append('service_name', form.service_name)
      fd.append('priest_name', form.priest_name)
      fd.append('main_servant_name', form.main_servant_name)
      fd.append('phone', form.phone)
      fd.append('address', form.address)
      fd.append('email', form.email)
      fd.append('id_type', idType)
      if (existingApp) {
        fd.append('password', '')
        fd.append('password_confirmation', '')
      } else {
        fd.append('password', form.password)
        fd.append('password_confirmation', form.password_confirmation)
      }
      if (frontIdFile) fd.append('front_id', frontIdFile)
      if (backIdFile) fd.append('back_id', backIdFile)
      if (permissionDocFile) fd.append('church_permission_doc', permissionDocFile)
      const result = await submitChurchApplication(fd)
      if (result.is_update) {
        toast.success(t('join.updatedSuccess'))
        await refreshUser()
        navigate('/application-status')
      } else {
        toast.success(t('join.success'))
        navigate('/login')
      }
    } catch (err: unknown) {
      logCatch('JoinNow.submit', err)
      const axiosErr = err as { response?: { data?: Record<string, unknown> } }
      const responseData = axiosErr?.response?.data
      if (responseData?.errors && typeof responseData.errors === 'object') {
        const messages = Object.values(responseData.errors as Record<string, string[]>).flat()
        messages.forEach(msg => toast.error(msg))
      } else if (responseData?.message) {
        toast.error(String(responseData.message))
      } else {
        toast.error(t('common.saving'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const getAcceptTypes = () => ACCEPTED_FORMATS

  const renderStatusBadge = () => {
    if (!existingApp) return null
    const status = existingApp.status
    if (status === 'pending') {
      return (
        <div className="flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
          <Clock className="h-4 w-4 shrink-0" />
          <span className="font-medium">{t('applicationStatus.pendingBadge')}</span>
        </div>
      )
    }
    if (status === 'rejected') {
      return (
        <div className="flex items-center gap-2 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
          <XCircle className="h-4 w-4 shrink-0" />
          <span className="font-medium">{t('applicationStatus.rejectedBadge')}</span>
        </div>
      )
    }
    if (status === 'approved') {
      return (
        <div className="flex items-center gap-2 rounded-lg bg-green-50 px-4 py-2.5 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-300">
          <CheckCircle className="h-4 w-4 shrink-0" />
          <span className="font-medium">{t('applicationStatus.approvedBadge')}</span>
        </div>
      )
    }
    return null
  }

  const renderRejectionReason = () => {
    if (!existingApp?.rejection_reason) return null
    return (
      <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30">
        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-red-600 dark:text-red-400">
            {t('applicationStatus.rejectionReason')}
          </p>
          <p className="mt-1 text-sm font-medium text-red-700 dark:text-red-300">
            {existingApp.rejection_reason}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-surface-secondary">
      <header className="sticky top-0 z-40 border-b bg-surface">
        <div className="mx-auto flex h-16 max-w-4xl items-center justify-between px-4">
          <button onClick={() => navigate('/')} className="btn-ghost btn-sm">
            <ArrowLeft className="h-4 w-4" /> {t('join.backToHome')}
          </button>
        </div>
      </header>

      <div className="mx-auto max-w-2xl px-4 py-12">
        <div className="card p-6 sm:p-8">
          <div className="mb-6 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary-100 dark:bg-primary-900/50">
              <Church className="h-6 w-6 text-primary-600 dark:text-primary-400" />
            </div>
            <h1 className="mt-3 text-2xl font-bold">
              {existingApp ? t('join.editTitle') : t('join.title')}
            </h1>
            <p className="mt-1 text-sm text-secondary">
              {existingApp ? t('join.editSubtitle') : t('join.subtitle')}
            </p>
          </div>

          {/* Existing application banner */}
          {lookupMessage && (
            <div className="mb-6 flex items-start gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 dark:border-primary-900/50 dark:bg-primary-950/30">
              <Info className="mt-0.5 h-5 w-5 shrink-0 text-primary-500" />
              <p className="text-sm text-primary-700 dark:text-primary-300">{lookupMessage}</p>
            </div>
          )}

          {/* Status badge + rejection reason */}
          {existingApp && (
            <div className="mb-6 space-y-3">
              {renderStatusBadge()}
              {renderRejectionReason()}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="grid gap-5 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label className="label">{t('join.churchName')} <span className="text-danger">*</span></label>
                <input value={form.church_name} onChange={(e) => setForm({ ...form, church_name: e.target.value })} required className="input-field" placeholder={t('join.churchNamePlaceholder')} />
              </div>
              <div className="sm:col-span-2">
                <label className="label">{t('join.serviceName')}</label>
                <input value={form.service_name} onChange={(e) => setForm({ ...form, service_name: e.target.value })} className="input-field" placeholder={t('join.serviceNamePlaceholder')} />
              </div>
              <div>
                <label className="label">{t('join.priestName')} <span className="text-danger">*</span></label>
                <input value={form.priest_name} onChange={(e) => setForm({ ...form, priest_name: e.target.value })} required className="input-field" placeholder={t('join.priestNamePlaceholder')} />
              </div>
              <div>
                <label className="label">{t('join.mainServantName')} <span className="text-danger">*</span></label>
                <input value={form.main_servant_name} onChange={(e) => setForm({ ...form, main_servant_name: e.target.value })} required className="input-field" placeholder={t('join.mainServantNamePlaceholder')} />
              </div>
              <div>
                <label className="label">{t('join.phone')} <span className="text-danger">*</span></label>
                <input type="tel" inputMode="numeric" value={form.phone} onChange={(e) => handlePhoneChange(e.target.value)} onBlur={() => { const e = validatePhone(form.phone); setPhoneError(e) }} required className={`input-field ${phoneError ? 'error' : ''}`} placeholder={t('join.phonePlaceholder')} autoComplete="tel" />
                {phoneError && <p className="form-error flex items-center gap-1 mt-1"><span className="text-danger">⚠</span> {phoneError}</p>}
              </div>
              <div>
                <label className="label">{t('join.adminEmail')} <span className="text-danger">*</span></label>
                <div className="relative">
                  <input
                    type="email"
                    value={form.email}
                    onChange={(e) => { setForm({ ...form, email: e.target.value }); setExistingApp(null); setLookupMessage('') }}
                    onBlur={handleEmailBlur}
                    required
                    className="input-field pr-10"
                    placeholder={t('join.adminEmailPlaceholder')}
                    autoComplete="email"
                  />
                  {isLookingUp && (
                    <div className="absolute inset-y-0 right-0 flex items-center pr-3">
                      <Loader2 className="h-4 w-4 animate-spin text-muted" />
                    </div>
                  )}
                </div>
                <p className="mt-1 text-xs text-muted">{t('join.adminEmailHelp')}</p>
              </div>
            </div>

            {!existingApp && (
              <div className="grid gap-5 sm:grid-cols-2">
                <div>
                  <label className="label">{t('auth.password')} <span className="text-danger">*</span></label>
                  <div className="relative">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={form.password}
                      onChange={(e) => setForm({ ...form, password: e.target.value })}
                      required
                      minLength={8}
                      className="input-field pr-10"
                      placeholder={t('auth.passwordPlaceholder')}
                      autoComplete="new-password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary"
                      tabIndex={-1}
                    >
                      {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                    </button>
                  </div>
                  <p className="mt-1 text-xs text-muted">{t('auth.passwordMin')}</p>
                </div>
                <div>
                  <label className="label">{t('auth.confirmPassword')} <span className="text-danger">*</span></label>
                  <div className="relative">
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      value={form.password_confirmation}
                      onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                      required
                      minLength={8}
                      className="input-field pr-10"
                      placeholder={t('auth.confirmPasswordPlaceholder')}
                      autoComplete="new-password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                      className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary"
                      tabIndex={-1}
                    >
                      {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                    </button>
                  </div>
                </div>
              </div>
            )}

            {existingApp && (
              <div className="rounded-xl bg-surface-secondary p-4">
                <p className="text-sm text-muted">
                  {t('join.passwordPreserved')}
                </p>
              </div>
            )}

            <div>
              <label className="label">{t('join.churchAddress')} <span className="text-danger">*</span></label>
              <textarea value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} required className="input-field" rows={2} placeholder={t('join.churchAddressPlaceholder')} />
            </div>

            <div>
              <label className="label">{t('join.verificationMethod')} <span className="text-danger">*</span></label>
              <div className="flex gap-3">
                <button type="button" onClick={() => setIdType('national_id')} className={`flex-1 rounded-lg border-2 p-3 text-center text-sm font-medium transition-colors ${idType === 'national_id' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400' : 'border-border text-secondary hover:border-primary-300'}`}>
                  {t('join.nationalIdOption')}
                </button>
                <button type="button" onClick={() => setIdType('church_permission')} className={`flex-1 rounded-lg border-2 p-3 text-center text-sm font-medium transition-colors ${idType === 'church_permission' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400' : 'border-border text-secondary hover:border-primary-300'}`}>
                  {t('join.churchPermissionOption')}
                </button>
              </div>
            </div>

            {idType === 'national_id' ? (
              <div className="grid gap-5 sm:grid-cols-2">
                <div>
                  <label className="label">{t('join.frontId')} {!existingApp && <span className="text-danger">*</span>}</label>
                  <div className="relative flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-border bg-surface-secondary p-4 hover:border-primary-500 transition-colors">
                    {frontPreview ? (
                      <img src={frontPreview} alt="Front ID" className="max-h-32 rounded object-contain" />
                    ) : frontIdFile ? (
                      <FileText className="h-8 w-8 text-muted" />
                    ) : existingApp?.front_id_url ? (
                      <img src={existingApp.front_id_url} alt="Front ID" className="max-h-32 rounded object-contain" />
                    ) : (
                      <Upload className="h-8 w-8 text-muted" />
                    )}
                    <input type="file" accept={getAcceptTypes()} onChange={handleFileChange('front')} className="absolute inset-0 cursor-pointer opacity-0" />
                    <p className="mt-2 text-xs text-muted">
                      {frontIdFile
                        ? frontIdFile.name
                        : existingApp?.front_id_url
                          ? t('join.fileExists')
                          : t('join.frontIdHelp')}
                    </p>
                  </div>
                </div>
                <div>
                  <label className="label">{t('join.backId')} {!existingApp && <span className="text-danger">*</span>}</label>
                  <div className="relative flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-border bg-surface-secondary p-4 hover:border-primary-500 transition-colors">
                    {backPreview ? (
                      <img src={backPreview} alt="Back ID" className="max-h-32 rounded object-contain" />
                    ) : backIdFile ? (
                      <FileText className="h-8 w-8 text-muted" />
                    ) : existingApp?.back_id_url ? (
                      <img src={existingApp.back_id_url} alt="Back ID" className="max-h-32 rounded object-contain" />
                    ) : (
                      <Upload className="h-8 w-8 text-muted" />
                    )}
                    <input type="file" accept={getAcceptTypes()} onChange={handleFileChange('back')} className="absolute inset-0 cursor-pointer opacity-0" />
                    <p className="mt-2 text-xs text-muted">
                      {backIdFile
                        ? backIdFile.name
                        : existingApp?.back_id_url
                          ? t('join.fileExists')
                          : t('join.backIdHelp')}
                    </p>
                  </div>
                </div>
              </div>
            ) : (
              <div>
                <label className="label">{t('join.churchPermissionDoc')} {!existingApp && <span className="text-danger">*</span>}</label>
                <div className="relative flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-border bg-surface-secondary p-4 hover:border-primary-500 transition-colors">
                  {permissionPreview ? (
                    <img src={permissionPreview} alt="Permission Document" className="max-h-32 rounded object-contain" />
                  ) : permissionDocFile ? (
                    <div className="flex items-center gap-2 text-sm text-secondary">
                      <FileText className="h-5 w-5" />
                      <span>{permissionDocFile.name}</span>
                    </div>
                  ) : existingApp?.church_permission_doc_url ? (
                    <div className="flex items-center gap-2 text-sm text-secondary">
                      <FileText className="h-5 w-5" />
                      <span>{t('join.fileExists')}</span>
                    </div>
                  ) : (
                    <>
                      <Upload className="h-8 w-8 text-muted" />
                      <p className="mt-2 text-xs text-muted">{t('join.churchPermissionHelp')}</p>
                    </>
                  )}
                  <input type="file" accept={getAcceptTypes()} onChange={handleFileChange('permission')} className="absolute inset-0 cursor-pointer opacity-0" />
                </div>
              </div>
            )}

            <button type="submit" disabled={submitting} className="btn-primary btn-md w-full">
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? t('join.submitting') : (existingApp ? t('join.updateApplication') : t('join.submit'))}
            </button>
          </form>

          <p className="mt-6 text-center text-sm text-secondary">
            {t('join.alreadyRegistered')}{' '}
            <button onClick={() => navigate('/login')} className="font-medium text-primary-600 hover:underline dark:text-primary-400">
              {t('join.signIn')}
            </button>
          </p>
        </div>
      </div>
    </div>
  )
}
