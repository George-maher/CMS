import { useState } from 'react'
import { X, Download, Smartphone, Monitor, Menu as MenuIcon, Apple, Chrome } from 'lucide-react'
import { usePWAInstall } from '@/hooks/usePwaInstall'
import { useDeviceDetection } from '@/hooks/useDeviceDetection'
import IOSInstallGuide from './IOSInstallGuide'

interface InstallAppModalProps {
  isOpen: boolean
  onClose: () => void
}

type PlatformTab = 'ios' | 'android' | 'desktop'

export default function InstallAppModal({ isOpen, onClose }: InstallAppModalProps) {
  const { isInstallable, isInstalled, install } = usePWAInstall()
  const { isIOS, isAndroid } = useDeviceDetection()
  const [tab, setTab] = useState<PlatformTab>(isIOS ? 'ios' : isAndroid ? 'android' : 'desktop')

  if (!isOpen) return null

  const tabs: { id: PlatformTab; label: string; icon: typeof Smartphone }[] = [
    { id: 'ios', label: 'iPhone / iPad', icon: Apple },
    { id: 'android', label: 'Android', icon: Smartphone },
    { id: 'desktop', label: 'Desktop', icon: Monitor },
  ]

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
        <button
          onClick={onClose}
          className="absolute right-4 top-4 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
        >
          <X className="h-5 w-5" />
        </button>

        <div className="mb-4 mt-2 text-center">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 dark:bg-primary-900/20">
            <Download className="h-8 w-8 text-primary-600 dark:text-primary-400" />
          </div>
          <h2 className="text-xl font-bold text-gray-900 dark:text-white">
            Install Church Manager
          </h2>
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Install as an app for the best experience — works offline, loads faster, and feels like a native app.
          </p>
        </div>

        {isInstalled && (
          <div className="mb-4 rounded-xl bg-green-50 p-3 text-center dark:bg-green-900/20">
            <p className="font-medium text-green-700 dark:text-green-300">App is installed on this device</p>
          </div>
        )}

        <div className="mb-4 grid grid-cols-3 gap-1.5 rounded-xl bg-gray-100 p-1 dark:bg-gray-700">
          {tabs.map((t) => {
            const Icon = t.icon
            return (
              <button
                key={t.id}
                onClick={() => setTab(t.id)}
                className={`flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium transition-colors ${
                  tab === t.id
                    ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-600 dark:text-white'
                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                }`}
              >
                <Icon className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">{t.label}</span>
              </button>
            )
          })}
        </div>

        {tab === 'ios' && <IOSInstallGuide />}

        {tab === 'android' && (
          <div className="space-y-3">
            {isInstallable ? (
              <div className="space-y-3">
                <button
                  onClick={install}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-3 font-medium text-white transition-colors hover:bg-primary-700"
                >
                  <Download className="h-5 w-5" />
                  Install App
                </button>
                <p className="text-center text-xs text-gray-400 dark:text-gray-500">
                  Free • No app store required • Works offline
                </p>
              </div>
            ) : (
              <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-700/50">
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                  <MenuIcon className="h-4 w-4" />
                  Install on Android
                </h3>
                <ol className="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                  <li className="flex items-start gap-2">
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">1</span>
                    <span>Open Chrome or Samsung Internet</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">2</span>
                    <span>Tap the menu icon <MenuIcon className="inline h-3.5 w-3.5 text-primary-500" /> (three dots)</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">3</span>
                    <span>Tap <strong>"Install app"</strong> or <strong>"Add to Home screen"</strong></span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">4</span>
                    <span>Tap <strong>"Install"</strong> in the popup</span>
                  </li>
                </ol>
              </div>
            )}
          </div>
        )}

        {tab === 'desktop' && (
          <div className="space-y-3">
            {isInstallable ? (
              <div className="space-y-3">
                <button
                  onClick={install}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-3 font-medium text-white transition-colors hover:bg-primary-700"
                >
                  <Download className="h-5 w-5" />
                  Install App
                </button>
                <p className="text-center text-xs text-gray-400 dark:text-gray-500">
                  Free • No app store required • Works offline
                </p>
              </div>
            ) : (
              <div className="space-y-2">
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-700/50">
                  <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    <Chrome className="h-4 w-4" />
                    Chrome / Edge / Brave
                  </h3>
                  <ol className="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">1</span>
                      <span>Click the install icon <Download className="inline h-3.5 w-3.5 text-primary-500" /> in the address bar</span>
                    </li>
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">2</span>
                      <span>Click <strong>"Install"</strong> in the dialog</span>
                    </li>
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">3</span>
                      <span>The app opens in its own window — no browser tabs needed</span>
                    </li>
                  </ol>
                </div>
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-700/50">
                  <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    <Monitor className="h-4 w-4" />
                    Firefox / Safari
                  </h3>
                  <ol className="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">1</span>
                      <span>Open the browser menu (☰ or ⋮)</span>
                    </li>
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">2</span>
                      <span>Look for <strong>"Install"</strong> or <strong>"Add to Dock"</strong> (Safari) or <strong>"Install"</strong> (Firefox)</span>
                    </li>
                    <li className="flex items-start gap-2">
                      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">3</span>
                      <span>Confirm to install as a standalone app</span>
                    </li>
                  </ol>
                </div>
              </div>
            )}
          </div>
        )}

        <div className="mt-6 flex items-center justify-center gap-6 text-xs text-gray-400 dark:text-gray-500">
          <span className="flex items-center gap-1">
            <Smartphone className="h-3.5 w-3.5" /> Offline support
          </span>
          <span className="flex items-center gap-1">
            <Download className="h-3.5 w-3.5" /> Fast loading
          </span>
        </div>
      </div>
    </div>
  )
}
