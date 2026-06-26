import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Camera, X, CheckCircle, AlertCircle, QrCode, ClipboardList } from 'lucide-react'
import CopyButton from '@/components/common/CopyButton'
import { useTheme } from '@/contexts/ThemeContext'
import { ctxName, ctxOptionLabel } from '@/lib/contextLabels'
import { lookupByToken, lookupByMemberId, recordAttendance, recordAttendanceByMemberId, getTodayAttendance } from '@/api/attendance'
import { listEvents } from '@/api/events'
import { getActiveContexts } from '@/api/attendanceContexts'
import { getMembers } from '@/api/users'
import { listAllClasses } from '@/api/structure'
import type { AttendanceContext, Event, User } from '@/types'

export default function ServantScanQR() {
  const { t } = useTranslation()
  const { language } = useTheme()
  const [manualToken, setManualToken] = useState('')
  const [manualMemberId, setManualMemberId] = useState('')
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<{ success: boolean; message: string } | null>(null)
  const [cameraActive, setCameraActive] = useState(false)
  const [events, setEvents] = useState<Event[]>([])
  const [selectedEventId, setSelectedEventId] = useState<number | ''>('')
  const [contexts, setContexts] = useState<AttendanceContext[]>([])
  const [contextsLoading, setContextsLoading] = useState(true)
  const [members, setMembers] = useState<User[]>([])
  const [todayAttended, setTodayAttended] = useState<Set<number>>(new Set())
  const [structureClasses, setStructureClasses] = useState<{ stage_id: number; stage_name: string; id: number; name: string }[]>([])
  const [selectedClassId, setSelectedClassId] = useState<number | ''>('')
  const [pendingMember, setPendingMember] = useState<User | null>(null)
  const [pendingToken, setPendingToken] = useState<string | null>(null)
  const [pendingMemberId, setPendingMemberId] = useState<string | null>(null)
  const [confirmContextId, setConfirmContextId] = useState<number | ''>('')
  const [qrContextName, setQrContextName] = useState<string | null>(null)

  const scannerRef = useRef<HTMLDivElement>(null)
  const html5QrCodeRef = useRef<{ stop: () => Promise<void> } | null>(null)
  const cameraActiveRef = useRef(false)
  const selectedEventIdRef = useRef<number | ''>('')

  useEffect(() => { cameraActiveRef.current = cameraActive }, [cameraActive])
  useEffect(() => { selectedEventIdRef.current = selectedEventId }, [selectedEventId])

  useEffect(() => {
    listEvents({ upcoming: true, active_only: true, per_page: 50 })
      .then((ev) => setEvents(ev.data ?? []))
      .catch(() => {})

    getActiveContexts()
      .then((ctxs) => { setContexts(ctxs ?? []); setContextsLoading(false) })
      .catch(() => { setContextsLoading(false); toast.error(t('common.failedToLoad')) })

    getMembers()
      .then((m) => setMembers(m ?? []))
      .catch(() => {})

    getTodayAttendance()
      .then((today) => setTodayAttended(new Set(today.data?.map((a) => a.user?.id).filter(Boolean) ?? [])))
      .catch(() => {})

    listAllClasses()
      .then(setStructureClasses)
      .catch(() => {})

    return () => {
      if (html5QrCodeRef.current) { try { html5QrCodeRef.current.stop() } catch {}; html5QrCodeRef.current = null }
    }
  }, [])

  const confirmAttendance = async (token: string, memberId?: string) => {
    if (!confirmContextId) {
      setResult({ success: false, message: t('attendance.selectContextRequired') })
      return
    }
    setLoading(true)
    setResult(null)
    try {
      let res: { attendance: import('@/types').Attendance; points_earned: number }
      if (memberId) {
        res = await recordAttendanceByMemberId(
          memberId,
          confirmContextId,
          selectedEventIdRef.current || undefined,
          'id',
        )
      } else {
        res = await recordAttendance(
          token,
          confirmContextId,
          selectedEventIdRef.current || undefined,
          'qr',
        )
      }
      setResult({ success: true, message: `${t('attendance.attendanceRecorded')}! +${res.points_earned} ${t('common.points')}` })
      setManualToken('')
      setManualMemberId('')
      setPendingMember(null)
      setPendingToken(null)
      setPendingMemberId(null)
      setConfirmContextId('')
      setQrContextName(null)
      toast.success(`${t('attendance.attendanceRecorded')}! +${res.points_earned} ${t('common.points')}`)
      getTodayAttendance().then(today => {
        setTodayAttended(new Set(today.data.map((a) => a.user?.id).filter(Boolean)))
      }).catch(() => {})
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      let msg = axiosErr?.response?.data?.message || t('attendance.failedToRecord')
      if (axiosErr?.response?.data?.errors) { msg += ` ${Object.values(axiosErr.response.data.errors).flat().join(' ')}` }
      setResult({ success: false, message: msg })
      toast.error(msg)
    } finally { setLoading(false) }
  }

  const handleLookupAndConfirm = useCallback(async (token: string) => {
    setLoading(true)
    setResult(null)
    try {
      const result = await lookupByToken(token)
      const { member, attendance_context_id, attendance_context } = result

      if (attendance_context_id) {
        setConfirmContextId(attendance_context_id)
        setQrContextName(attendance_context?.name ?? null)
      } else {
        setQrContextName(null)
      }

      if (member) {
        setPendingMember(member)
        setPendingToken(token)
        setPendingMemberId(null)
        setManualToken('')
      } else if (attendance_context_id) {
        const ctxName = attendance_context?.name || ''
        setResult({
          success: true,
          message: `${t('attendance.contextSetTo')} "${ctxName}". ${t('attendance.scanMemberNow')}`,
        })
        setManualToken('')
      } else {
        throw new Error(t('attendance.memberNotFound'))
      }
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      const msg = axiosErr?.response?.data?.message || t('attendance.memberNotFound')
      setResult({ success: false, message: msg })
      toast.error(msg)
    } finally { setLoading(false) }
  }, [contexts, t])

  const handleLookupByMemberId = useCallback(async (memberId: string) => {
    setLoading(true)
    setResult(null)
    setQrContextName(null)
    try {
      const { member } = await lookupByMemberId(memberId)
      setPendingMember(member)
      setPendingMemberId(memberId)
      setPendingToken(null)
      setManualMemberId('')
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      const msg = axiosErr?.response?.data?.message || t('attendance.memberNotFound')
      setResult({ success: false, message: msg })
      toast.error(msg)
    } finally { setLoading(false) }
  }, [])

  const canUseCamera = () => !!(
    typeof navigator.mediaDevices?.getUserMedia === 'function' &&
    (window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
  )

  useEffect(() => {
    if (!cameraActive) return
    let cancelled = false
    const elementId = 'qr-reader'
    const initScanner = async () => {
      try {
        if (!canUseCamera()) {
          if (!navigator.mediaDevices?.getUserMedia) throw Object.assign(new Error(), { code: 'UNSUPPORTED' })
          throw Object.assign(new Error(), { code: 'HTTPS_REQUIRED' })
        }
        const { Html5Qrcode } = await import('html5-qrcode')
        if (cancelled) return
        await new Promise<void>(resolve => requestAnimationFrame(() => resolve()))
        if (cancelled) return
        const container = document.getElementById(elementId)
        if (!container) throw Object.assign(new Error('Container not found'), { code: 'NO_CONTAINER' })
        container.innerHTML = ''
        container.style.minHeight = '300px'
        let cameraId: string | undefined
        try {
            const cameras: { id: string; label: string }[] = await Html5Qrcode.getCameras()
          if (cameras && cameras.length > 0) {
            const back = cameras.find((c: { id: string; label: string }) => c.label.toLowerCase().includes('back') || c.label.toLowerCase().includes('environment'))
            const front = cameras.find((c: { id: string; label: string }) => c.label.toLowerCase().includes('front') || c.label.toLowerCase().includes('user'))
            cameraId = back?.id || front?.id || cameras[0]!.id
          }
        } catch {}
        if (cancelled) return
        const scanner = new Html5Qrcode(elementId)
        html5QrCodeRef.current = scanner
        await scanner.start(
          cameraId ? { facingMode: 'environment' } : { facingMode: 'environment' },
          { fps: 5, qrbox: (viewfinderWidth: number, viewfinderHeight: number) => { const minDim = Math.min(viewfinderWidth, viewfinderHeight); const size = Math.max(180, Math.min(minDim * 0.75, 300)); return { width: size, height: size } } },
          async (decodedText: string) => {
            if (cancelled || !cameraActiveRef.current) return
            try { await scanner.stop() } catch {}
            html5QrCodeRef.current = null
            setCameraActive(false)
            handleLookupAndConfirm(decodedText)
          },
          () => {},
        )
      } catch (err: unknown) {
        if (cancelled) return; setCameraActive(false)
        const code = (err as Record<string, unknown>).code as string | undefined
        const errName = (err as DOMException)?.name ?? ''
        const errMsg = (err as Error)?.message ?? ''
        const isNotAllowed = errName === 'NotAllowedError' || errMsg.includes('Permission denied')
        const isNotFound = code === 'NO_CAMERA' || code === 'NO_CONTAINER' || errName === 'NotFoundError' || errMsg.includes('no camera')
        const msg = isNotAllowed ? t('common.cameraPermissionDenied') : isNotFound ? t('common.cameraNotFound') : t('common.cameraFailed')
        setResult({ success: false, message: msg })
      }
    }
    initScanner()
    return () => { cancelled = true; if (html5QrCodeRef.current) { try { html5QrCodeRef.current.stop() } catch {}; html5QrCodeRef.current = null } }
  }, [cameraActive])

  const startScanner = async () => {
    setResult(null)
    if (!canUseCamera()) {
      if (!navigator.mediaDevices?.getUserMedia) { setResult({ success: false, message: t('common.cameraUnsupported') }); return }
      setResult({ success: false, message: t('common.cameraRequiresHttps') }); return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true })
      stream.getTracks().forEach(t => t.stop())
    } catch (err: unknown) {
      const errName = (err as DOMException)?.name ?? ''
      if (errName === 'NotAllowedError') { setResult({ success: false, message: t('common.cameraPermissionDenied') }); return }
    }
    setCameraActive(true)
  }

  const stopScanner = async () => {
    if (html5QrCodeRef.current) { try { await html5QrCodeRef.current.stop() } catch {}; html5QrCodeRef.current = null }
    setCameraActive(false)
  }

  const handleManualSubmit = async (e: React.FormEvent) => { e.preventDefault(); await handleLookupAndConfirm(manualToken) }

  return (
    <div className="mx-auto max-w-lg space-y-4">
      <div className="card p-5">
        <h2 className="text-lg font-semibold">{t('attendance.recordAttendance')}</h2>
        <p className="mt-1 text-sm text-secondary">{t('attendance.scanQRPrompt')}</p>

        <div className="mt-4 space-y-3">
          <div>
            <label className="label">
              {t('context.context')} <span className="text-danger">*</span>
            </label>
            <select
              value={confirmContextId}
              onChange={(e) => setConfirmContextId(e.target.value ? Number(e.target.value) : '')}
              className="input-field"
              disabled={contextsLoading}
            >
              {contextsLoading ? (
                <option value="">{t('common.loading')}...</option>
              ) : (
                <>
                  <option value="">{t('attendance.selectContext')}</option>
                  {contexts.map((ctx) => (
                    <option key={ctx.id} value={ctx.id}>{ctxOptionLabel(ctx, language)}</option>
                  ))}
                </>
              )}
            </select>
          </div>

          {structureClasses.length > 0 && (
            <div>
              <label className="label">{t('attendance.class')}</label>
              <select value={selectedClassId}
                onChange={(e) => setSelectedClassId(e.target.value ? Number(e.target.value) : '')}
                className="input-field"
              >
                <option value="">{t('attendance.allClasses')}</option>
                {structureClasses
                  .reduce<{ stage_name: string; classes: { id: number; name: string }[] }[]>((acc, cls) => {
                    let group = acc.find(g => g.stage_name === cls.stage_name)
                    if (!group) { group = { stage_name: cls.stage_name, classes: [] }; acc.push(group) }
                    group.classes.push({ id: cls.id, name: cls.name })
                    return acc
                  }, [])
                  .map((group) => (
                    <optgroup key={group.stage_name} label={group.stage_name}>
                      {group.classes.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                      ))}
                    </optgroup>
                  ))}
              </select>
            </div>
          )}

          {events.length > 0 && (
            <div>
              <label className="label">{t('attendance.linkToEvent')}</label>
              <select value={selectedEventId} onChange={(e) => setSelectedEventId(e.target.value ? Number(e.target.value) : '')}
                className="input-field">
                <option value="">{t('attendance.noEvent')}</option>
                {events.map((event) => (<option key={event.id} value={event.id}>{event.name}{event.event_date ? ` — ${new Date(event.event_date).toLocaleDateString()}` : ''}</option>))}
              </select>
            </div>
          )}


        </div>
      </div>

      {!cameraActive ? (
        <div className="card p-5">
          <button onClick={startScanner} disabled={loading}
            className="btn-primary btn-md w-full">
            <Camera className="h-5 w-5" /> {t('attendance.openCamera')}
          </button>
        </div>
      ) : (
        <div className="card p-5">
          <div id="qr-reader" ref={scannerRef} className="mx-auto w-full max-w-md overflow-hidden rounded-lg" />
          <button onClick={stopScanner} className="btn-danger btn-md w-full mt-3">
            <X className="h-4 w-4" /> {t('attendance.cancelScan')}
          </button>
        </div>
      )}

      <div className="card p-5 space-y-6">
        <div>
          <h3 className="font-semibold">{t('attendance.byQRToken')}</h3>
          <form onSubmit={handleManualSubmit} className="mt-3 space-y-4">
            <div>
              <label className="label">{t('qr.attendanceQR')}</label>
              <input type="text" value={manualToken} onChange={(e) => setManualToken(e.target.value)}
                placeholder={t('attendance.tokenPlaceholder')} required className="input-field font-mono" />
            </div>
            <button type="submit" disabled={loading || !manualToken} className="btn-primary btn-md w-full">
              {loading ? null : <QrCode className="h-4 w-4" />}
              {loading ? t('common.saving') : t('attendance.lookupToken')}
            </button>
          </form>
        </div>

        <div className="border-t border-border pt-6">
          <h3 className="font-semibold">{t('attendance.byMemberId')}</h3>
          <form onSubmit={(e) => { e.preventDefault(); handleLookupByMemberId(manualMemberId) }} className="mt-3 space-y-4">
            <div>
              <label className="label">{t('attendance.memberId')}</label>
              <input type="text" value={manualMemberId} onChange={(e) => setManualMemberId(e.target.value.toUpperCase())}
                placeholder={t('attendance.memberIdPlaceholder')} required className="input-field font-mono uppercase" />
            </div>
            <button type="submit" disabled={loading || !manualMemberId} className="btn-primary btn-md w-full">
              {loading ? t('common.saving') : t('attendance.lookupMember')}
            </button>
          </form>
        </div>

        {result && (
          <div className={`mt-4 rounded-lg p-4 text-sm flex items-center gap-2 ${
            result.success ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400' : 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400'
          }`}>
            {result.success ? <CheckCircle className="h-5 w-5 shrink-0" /> : <AlertCircle className="h-5 w-5 shrink-0" />}
            {result.message}
          </div>
        )}
      </div>

      {pendingMember && (pendingToken || pendingMemberId) && (
        <div className="card border-l-4 border-l-primary-500 p-5">
          <h3 className="font-semibold">{t('attendance.confirmAttendance')}</h3>
          <div className="mt-3 flex items-center gap-4 rounded-lg bg-surface-secondary p-4">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/30 text-lg font-bold text-primary-700 dark:text-primary-300">
              {pendingMember.name.charAt(0).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <p className="font-medium truncate">{pendingMember.name}</p>
              {pendingMember.classe && <p className="text-sm text-secondary">{pendingMember.classe.name}</p>}
              {pendingMember.member_id && (
                <p className="flex items-center gap-1 text-xs text-muted font-mono">
                  {pendingMember.member_id}
                  <CopyButton value={pendingMember.member_id} iconSize={12} />
                </p>
              )}
            </div>
          </div>
          {qrContextName && (
            <div className="mt-3 flex items-center gap-2 rounded-lg bg-primary-50 dark:bg-primary-900/20 px-4 py-2.5 text-sm text-primary-700 dark:text-primary-300">
              <ClipboardList className="h-4 w-4 shrink-0" />
              <span className="font-medium">{t('attendance.contextFromQR')}:</span>
              <span className="font-semibold">{language === 'ar' ? (contexts.find(c => c.id === confirmContextId)?.name_ar || qrContextName) : qrContextName}</span>
            </div>
          )}
          <div className="mt-4">
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-secondary">
              {t('context.context')} <span className="text-danger">*</span>
            </label>
            <select
              value={confirmContextId}
              onChange={(e) => setConfirmContextId(e.target.value ? Number(e.target.value) : '')}
              className="input-field"
            >
              <option value="">{t('attendance.selectContext')}</option>
              {contexts.map((ctx) => (
                <option key={ctx.id} value={ctx.id}>{ctxOptionLabel(ctx, language)}</option>
              ))}
            </select>
          </div>
          <div className="mt-4 space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row">
              <button onClick={() => { setPendingMember(null); setPendingToken(null); setPendingMemberId(null); setConfirmContextId(''); setQrContextName(null) }}
                className="btn-secondary btn-md flex-1">
                {t('common.cancel')}
              </button>
              <button onClick={() => confirmAttendance(pendingToken ?? '', pendingMemberId ?? undefined)} disabled={loading || !confirmContextId}
                className="btn-primary btn-md flex-1">
                {loading ? t('common.saving') : t('attendance.confirmAttendance')}
              </button>
            </div>
          </div>
        </div>
      )}

      <div className="card p-5">
        <details className="group">
          <summary className="cursor-pointer list-none">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="font-semibold">{t('attendance.myMembersToday')}</h3>
                <p className="mt-1 text-sm text-secondary">{members.length} {t('dashboard.myMembers')}</p>
              </div>
              <span className="text-xs text-muted group-open:hidden">{t('common.show')}</span>
              <span className="text-xs text-muted hidden group-open:inline">{t('common.hide')}</span>
            </div>
          </summary>
          <div className="mt-4 space-y-2">
            {members.length === 0 ? (
              <p className="text-sm text-muted">{t('attendance.noMembersAssigned')}</p>
            ) : (
              members
                .filter((m) => !selectedClassId || m.class_id === selectedClassId || m.classe?.id === selectedClassId)
                .map((member) => {
                const present = todayAttended.has(member.id)
                return (
                  <div key={member.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-2.5">
                    <div className="min-w-0 flex-1">
                      <span className="text-sm font-medium truncate block">{member.name}</span>
                      {member.classe && <span className="text-xs text-muted">{member.classe.name}</span>}
                    </div>
                    <span className={`shrink-0 text-xs font-semibold ${present ? 'text-success' : 'text-secondary'}`}>
                      {present ? t('attendance.present') : '—'}
                    </span>
                  </div>
                )
              })
            )}
          </div>
        </details>
      </div>
    </div>
  )
}
