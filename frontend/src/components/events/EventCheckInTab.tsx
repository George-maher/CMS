import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import Badge from '@/components/common/Badge'
import type { EventRegistration } from '@/types'
import { checkInByToken, listRegistrations } from '@/api/eventRegistrations'
import { logCatch } from '@/lib/debug'
import { attendanceStatusLabelKey, attendanceStatusVariant } from './eventStatus'

interface Props {
  eventId: number
}

export default function EventCheckInTab({ eventId }: Props) {
  const { t } = useTranslation()

  const [token, setToken] = useState('')
  const [search, setSearch] = useState('')
  const [results, setResults] = useState<EventRegistration[]>([])
  const [lastChecked, setLastChecked] = useState<EventRegistration | null>(null)
  const [busy, setBusy] = useState(false)

  const scannerRef = useRef<HTMLDivElement>(null)
  const html5QrCodeRef = useRef<{ stop: () => Promise<void> } | null>(null)
  const [cameraActive, setCameraActive] = useState(false)

  const doCheckIn = useCallback(async (qrToken: string) => {
    if (!qrToken.trim() || busy) return
    setBusy(true)
    try {
      const reg = await checkInByToken(eventId, qrToken.trim())
      setLastChecked(reg)
      toast.success(`${reg.user?.name ?? ''} — ${t('eventMgmt.checkedInToast')}`)
      setToken('')
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(msg || t('eventMgmt.actionFailed'))
    } finally {
      setBusy(false)
    }
  }, [eventId, busy, t])

  useEffect(() => {
    const timer = setTimeout(() => {
      const q = search.trim()
      if (q.length < 2) {
        setResults([])
        return
      }
      listRegistrations(eventId, { search: q, per_page: 8 })
        .then((res) => setResults(res.data))
        .catch((e) => logCatch('EventCheckInTab.search', e))
    }, 300)
    return () => clearTimeout(timer)
  }, [search, eventId])

  useEffect(() => {
    return () => {
      if (html5QrCodeRef.current) {
        try {
          void html5QrCodeRef.current.stop()
        } catch (e) {
          logCatch('EventCheckInTab.stopScanner', e)
        }
        html5QrCodeRef.current = null
      }
    }
  }, [])

  const startScanner = async () => {
    try {
      const { Html5Qrcode } = await import('html5-qrcode')
      const cameras: { id: string; label: string }[] = await Html5Qrcode.getCameras()
      if (!cameras.length) {
        toast.error(t('eventMgmt.noCamera'))
        return
      }
      const scanner = new Html5Qrcode('event-checkin-scanner')
      html5QrCodeRef.current = scanner
      setCameraActive(true)
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 } },
        (decodedText: string) => {
          void doCheckIn(decodedText)
        },
        () => undefined,
      )
    } catch (e) {
      logCatch('EventCheckInTab.startScanner', e)
      setCameraActive(false)
      toast.error(t('eventMgmt.cameraFailed'))
    }
  }

  const stopScanner = async () => {
    if (html5QrCodeRef.current) {
      try {
        await html5QrCodeRef.current.stop()
      } catch (e) {
        logCatch('EventCheckInTab.stopScanner', e)
      }
      html5QrCodeRef.current = null
    }
    setCameraActive(false)
  }

  return (
    <div className="mx-auto max-w-xl space-y-4">
      <div className="rounded-xl border border-border bg-surface p-4">
        <div id="event-checkin-scanner" ref={scannerRef} className={`overflow-hidden rounded-lg ${cameraActive ? '' : 'hidden'}`} />
        <button
          onClick={() => (cameraActive ? void stopScanner() : void startScanner())}
          className={`${cameraActive ? 'btn-secondary' : 'btn-primary'} btn-md mt-3 w-full`}
        >
          {cameraActive ? t('eventMgmt.stopCamera') : t('eventMgmt.scanQr')}
        </button>
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doCheckIn(token)
        }}
        className="flex gap-2"
      >
        <input
          value={token}
          onChange={(e) => setToken(e.target.value)}
          placeholder={t('eventMgmt.qrTokenPlaceholder')}
          className="input-field flex-1"
        />
        <button type="submit" disabled={busy} className="btn-primary btn-md whitespace-nowrap">{t('eventMgmt.checkIn')}</button>
      </form>

      {lastChecked ? (
        <div className="rounded-xl border border-success/40 bg-success/10 p-3 text-sm">
          <p className="font-medium">✓ {lastChecked.user?.name}</p>
          <Badge variant="success" className="mt-1">{t(attendanceStatusLabelKey(lastChecked.attendance_status))}</Badge>
        </div>
      ) : null}

      <div>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t('eventMgmt.searchParticipant')}
          className="input-field w-full"
        />
        {results.length > 0 ? (
          <ul className="mt-2 divide-y divide-border rounded-xl border border-border">
            {results.map((r) => (
              <li key={r.id} className="flex items-center justify-between gap-2 px-3 py-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{r.user?.name}</p>
                  <p className="text-xs text-secondary">{r.user?.phone ?? ''}</p>
                </div>
                <Badge variant={attendanceStatusVariant[r.attendance_status]}>
                  {t(attendanceStatusLabelKey(r.attendance_status))}
                </Badge>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </div>
  )
}
