import { useState, useEffect } from 'react'
import { Download, Smartphone, Monitor, Menu as MenuIcon, Apple, Chrome, QrCode, Share2, CheckCircle } from 'lucide-react'
import { usePWAInstall } from '@/hooks/usePwaInstall'
import { useDeviceDetection } from '@/hooks/useDeviceDetection'
import QRCode from 'qrcode'
import Modal from './Modal'
import IOSInstallGuide from './IOSInstallGuide'

interface InstallAppModalProps {
  isOpen: boolean
  onClose: () => void
}

type PlatformTab = 'ios' | 'android' | 'desktop'

const tabs: { id: PlatformTab; label: string; icon: typeof Smartphone }[] = [
  { id: 'ios', label: 'iPhone / iPad', icon: Apple },
  { id: 'android', label: 'Android', icon: Smartphone },
  { id: 'desktop', label: 'Desktop', icon: Monitor },
]

function StepCircle({ num }: { num: number }) {
  return (
    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">
      {num}
    </span>
  )
}

function AndroidContent({ isInstallable, install }: { isInstallable: boolean; install: () => Promise<void> }) {
  if (isInstallable) {
    return (
      <button
        onClick={install}
        className="btn-gold w-full"
      >
        <Download className="h-5 w-5" />
        Install App
      </button>
    )
  }

  return (
    <div className="card p-4">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-secondary">
        <MenuIcon className="h-4 w-4" />
        Install on Android
      </h3>
      <ol className="space-y-2 text-sm text-secondary">
        <li className="flex items-start gap-2">
          <StepCircle num={1} />
          <span>Open Chrome or Samsung Internet</span>
        </li>
        <li className="flex items-start gap-2">
          <StepCircle num={2} />
          <span>Tap the menu icon <MenuIcon className="inline h-3.5 w-3.5 text-primary-400" /> (three dots)</span>
        </li>
        <li className="flex items-start gap-2">
          <StepCircle num={3} />
          <span>Tap <strong>"Install app"</strong> or <strong>"Add to Home screen"</strong></span>
        </li>
        <li className="flex items-start gap-2">
          <StepCircle num={4} />
          <span>Tap <strong>"Install"</strong> in the popup</span>
        </li>
      </ol>
    </div>
  )
}

function DesktopContent({ isInstallable, install }: { isInstallable: boolean; install: () => Promise<void> }) {
  if (isInstallable) {
    return (
      <button
        onClick={install}
        className="btn-gold w-full"
      >
        <Download className="h-5 w-5" />
        Install App
      </button>
    )
  }

  return (
    <div className="space-y-3">
      <div className="card p-4">
        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-secondary">
          <Chrome className="h-4 w-4" />
          Chrome / Edge / Brave
        </h3>
        <ol className="space-y-2 text-sm text-secondary">
          <li className="flex items-start gap-2">
            <StepCircle num={1} />
            <span>Click the install icon <Download className="inline h-3.5 w-3.5 text-primary-400" /> in the address bar</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={2} />
            <span>Click <strong>"Install"</strong> in the dialog</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={3} />
            <span>The app opens in its own window — no browser tabs needed</span>
          </li>
        </ol>
      </div>
      <div className="card p-4">
        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-secondary">
          <Monitor className="h-4 w-4" />
          Firefox / Safari
        </h3>
        <ol className="space-y-2 text-sm text-secondary">
          <li className="flex items-start gap-2">
            <StepCircle num={1} />
            <span>Open the browser menu (☰ or ⋮)</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={2} />
            <span>Look for <strong>"Install"</strong> or <strong>"Add to Dock"</strong> (Safari) or <strong>"Install"</strong> (Firefox)</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={3} />
            <span>Confirm to install as a standalone app</span>
          </li>
        </ol>
      </div>
    </div>
  )
}

function QRPanel({ showQR, setShowQR, qrDataUrl }: { showQR: boolean; setShowQR: (v: boolean) => void; qrDataUrl: string }) {
  const handleShare = async () => {
    const url = window.location.origin
    if (typeof navigator.share === 'function') {
      await navigator.share({ title: 'Church Manager', url })
    } else {
      await navigator.clipboard.writeText(url)
    }
  }

  return (
    <div>
      <button
        onClick={() => setShowQR(!showQR)}
        className="btn-secondary w-full"
      >
        <QrCode className="h-4 w-4" />
        {showQR ? 'Hide QR code' : 'Open on another device'}
      </button>

      {showQR && (
        <div className="mt-3 card p-4 text-center">
          <p className="mb-3 text-sm font-medium text-secondary">
            Scan with your other device to open the app
          </p>
          <div className="mx-auto mb-3 flex justify-center">
            {qrDataUrl ? (
              <img src={qrDataUrl} alt="QR Code" className="h-44 w-44 rounded-lg border-4 border-white shadow-sm dark:border-gray-600" />
            ) : (
              <div className="flex h-44 w-44 items-center justify-center rounded-lg bg-surface dark:bg-gray-600">
                <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
              </div>
            )}
          </div>
          <p className="mb-3 text-xs text-muted">{window.location.origin}</p>
          <button onClick={handleShare} className="btn-primary btn-sm">
            <Share2 className="h-3.5 w-3.5" />
            {typeof navigator.share === 'function' ? 'Share link' : 'Copy link'}
          </button>
        </div>
      )}
    </div>
  )
}

export default function InstallAppModal({ isOpen, onClose }: InstallAppModalProps) {
  const { isInstallable, isInstalled, install } = usePWAInstall()
  const { isIOS, isAndroid } = useDeviceDetection()
  const [tab, setTab] = useState<PlatformTab>(isIOS ? 'ios' : isAndroid ? 'android' : 'desktop')
  const [showQR, setShowQR] = useState(false)
  const [qrDataUrl, setQrDataUrl] = useState('')

  useEffect(() => {
    if (showQR) {
      QRCode.toDataURL(window.location.origin, { width: 200, margin: 2, color: { dark: '#1e293b', light: '#ffffff' } })
        .then((url) => setQrDataUrl(url))
        .catch(() => {})
    }
  }, [showQR])

  if (!isOpen) return null

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Install Church Manager" size="sm">
      <div className="text-center">
        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 dark:bg-primary-900/20">
          <Download className="h-8 w-8 text-primary-400" />
        </div>
        <p className="mb-4 text-sm text-secondary">
          Install as an app for the best experience — works offline, loads faster, and feels like a native app.
        </p>
      </div>

      {isInstalled && (
        <div className="mb-4 flex items-center gap-2 rounded-xl bg-success-light p-3 dark:bg-success/10">
          <CheckCircle className="h-4 w-4 shrink-0 text-success" />
          <p className="text-sm font-medium text-success">App is installed on this device</p>
        </div>
      )}

      <div className="mb-4 grid grid-cols-3 gap-1 rounded-xl bg-surface-secondary p-1">
        {tabs.map((t) => {
          const Icon = t.icon
          return (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium transition-colors ${
                tab === t.id
                  ? 'bg-surface text-primary-400 shadow-sm'
                  : 'text-muted hover:text-secondary'
              }`}
            >
              <Icon className="h-3.5 w-3.5 shrink-0" />
              <span className="truncate">{t.label}</span>
            </button>
          )
        })}
      </div>

      {tab === 'ios' && <IOSInstallGuide />}
      {tab === 'android' && <AndroidContent isInstallable={isInstallable} install={install} />}
      {tab === 'desktop' && <DesktopContent isInstallable={isInstallable} install={install} />}

      <div className="mt-4">
        <QRPanel showQR={showQR} setShowQR={setShowQR} qrDataUrl={qrDataUrl} />
      </div>

      <div className="mt-4 flex items-center justify-center gap-6 text-xs text-muted">
        <span className="flex items-center gap-1">
          <Smartphone className="h-3.5 w-3.5" /> Offline support
        </span>
        <span className="flex items-center gap-1">
          <Download className="h-3.5 w-3.5" /> Fast loading
        </span>
      </div>
    </Modal>
  )
}
