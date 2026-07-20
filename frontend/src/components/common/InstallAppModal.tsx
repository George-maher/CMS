import { X, Download, Smartphone, Monitor } from 'lucide-react'
import { usePWAInstall } from '@/hooks/usePwaInstall'
import IOSInstallGuide from './IOSInstallGuide'

interface InstallAppModalProps {
  isOpen: boolean
  onClose: () => void
}

export default function InstallAppModal({ isOpen, onClose }: InstallAppModalProps) {
  const { isInstallable, isInstalled, isIOS, install } = usePWAInstall()

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
        <button
          onClick={onClose}
          className="absolute right-4 top-4 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
        >
          <X className="h-5 w-5" />
        </button>

        <div className="mb-6 mt-2 text-center">
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

        {isIOS ? (
          <IOSInstallGuide />
        ) : isInstallable ? (
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
        ) : isInstalled ? (
          <div className="rounded-xl bg-green-50 p-4 text-center dark:bg-green-900/20">
            <Smartphone className="mx-auto mb-2 h-8 w-8 text-green-600 dark:text-green-400" />
            <p className="font-medium text-green-700 dark:text-green-300">App is installed</p>
            <p className="mt-1 text-sm text-green-600 dark:text-green-400">
              You can access Church Manager from your home screen.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            <div className="rounded-xl bg-gray-50 p-4 text-center dark:bg-gray-700/50">
              <Monitor className="mx-auto mb-2 h-8 w-8 text-gray-400" />
              <p className="text-sm text-gray-500 dark:text-gray-400">
                Use the browser menu to add this app to your home screen.
              </p>
            </div>
            <IOSInstallGuide />
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
