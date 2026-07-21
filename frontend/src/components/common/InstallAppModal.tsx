import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Download, Smartphone, Monitor, Apple, Share2, Menu as MenuIcon, CheckCircle, AlertCircle } from 'lucide-react'
import { usePWAInstall } from '@/hooks/usePwaInstall'
import { useDeviceDetection } from '@/hooks/useDeviceDetection'
import Modal from './Modal'
import IOSInstallGuide from './IOSInstallGuide'

type InstallPhase = 'prompt' | 'success' | 'failed'

function StepCircle({ num }: { num: number }) {
  return (
    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">
      {num}
    </span>
  )
}

function InstallInstructions({ platform }: { platform: 'ios' | 'android' | 'desktop' }) {
  const { t } = useTranslation()

  if (platform === 'ios') {
    return (
      <div className="space-y-3">
        <ol className="space-y-2 text-sm text-secondary">
          <li className="flex items-start gap-2">
            <StepCircle num={1} />
            <span>{t('installApp.iosStep1')} <Share2 className="inline h-3.5 w-3.5 text-primary-400" /></span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={2} />
            <span>{t('installApp.iosStep2')}</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={3} />
            <span>{t('installApp.iosStep3')}</span>
          </li>
        </ol>
      </div>
    )
  }

  if (platform === 'android') {
    return (
      <div className="space-y-3">
        <ol className="space-y-2 text-sm text-secondary">
          <li className="flex items-start gap-2">
            <StepCircle num={1} />
            <span>{t('installApp.androidStep1')}</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={2} />
            <span>{t('installApp.androidStep2')} <MenuIcon className="inline h-3.5 w-3.5 text-primary-400" /></span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={3} />
            <span>{t('installApp.androidStep3')}</span>
          </li>
          <li className="flex items-start gap-2">
            <StepCircle num={4} />
            <span>{t('installApp.androidStep4')}</span>
          </li>
        </ol>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <ol className="space-y-2 text-sm text-secondary">
        <li className="flex items-start gap-2">
          <StepCircle num={1} />
          <span>{t('installApp.desktopChromeStep1')} <Download className="inline h-3.5 w-3.5 text-primary-400" /></span>
        </li>
        <li className="flex items-start gap-2">
          <StepCircle num={2} />
          <span>{t('installApp.desktopChromeStep2')}</span>
        </li>
        <li className="flex items-start gap-2">
          <StepCircle num={3} />
          <span>{t('installApp.desktopChromeStep3')}</span>
        </li>
      </ol>
    </div>
  )
}

export default function InstallAppModal({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const { isInstalled, install } = usePWAInstall()
  const { isIOS, isAndroid } = useDeviceDetection()
  const [phase, setPhase] = useState<InstallPhase>('prompt')
  const platform = isIOS ? 'ios' : isAndroid ? 'android' : 'desktop'
  const isRtl = i18n.dir() === 'rtl'

  useEffect(() => {
    if (isOpen) {
      setPhase('prompt')
    }
  }, [isOpen])

  useEffect(() => {
    if (isInstalled && phase === 'prompt') {
      setPhase('success')
    }
  }, [isInstalled, phase])

  const handleInstall = useCallback(async () => {
    if (isInstalled) {
      setPhase('success')
      return
    }
    if (isIOS) {
      setPhase('failed')
      return
    }
    const ok = await install()
    setPhase(ok ? 'success' : 'failed')
  }, [isInstalled, isIOS, install])

  const shouldShowInstructions = phase === 'failed' || (platform === 'ios' && phase === 'prompt')

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('installApp.installTitle')} size="sm">
      <div className={`space-y-5 ${isRtl ? 'text-right' : 'text-left'}`}>
        <div className="flex flex-col items-center gap-3">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 dark:bg-primary-900/20">
            <Download className="h-8 w-8 text-primary-400" />
          </div>
          <div className="text-center">
            <h3 className="text-lg font-semibold">{t('app.name')}</h3>
            <p className="text-sm text-muted mt-1">{t('installApp.installDescription')}</p>
          </div>
        </div>

        {phase === 'success' && (
          <div className="flex items-center gap-2 rounded-xl bg-success-light p-3 dark:bg-success/10">
            <CheckCircle className="h-5 w-5 shrink-0 text-success" />
            <p className="text-sm font-medium text-success">{t('installApp.alreadyInstalled')}</p>
          </div>
        )}

        {phase === 'prompt' && (
          <button onClick={handleInstall} className="btn-primary btn-md w-full gap-2">
            <Download className="h-5 w-5" />
            {t('installApp.install')}
          </button>
        )}

        {phase === 'failed' && (
          <div className="rounded-xl bg-warning/10 p-3 dark:bg-warning/5">
            <p className="flex items-start gap-2 text-sm font-medium text-warning">
              <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
              {t('installApp.browserRestriction')}
            </p>
          </div>
        )}

        {shouldShowInstructions && (
          <div className="border-t border-border pt-4">
            <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-secondary">
              {platform === 'ios' ? <Apple className="h-4 w-4" /> : platform === 'android' ? <Smartphone className="h-4 w-4" /> : <Monitor className="h-4 w-4" />}
              {platform === 'ios' ? t('installApp.iosTitle') : platform === 'android' ? t('installApp.androidTitle') : t('installApp.desktopChrome')}
              {' — '}
              <span className="font-normal text-muted">{t('installApp.manualInstall')}</span>
            </h4>
            {platform === 'ios' ? <IOSInstallGuide /> : <InstallInstructions platform={platform} />}
          </div>
        )}

        <div className="flex items-center justify-center gap-4 text-xs text-muted">
          <span className="flex items-center gap-1"><Smartphone className="h-3.5 w-3.5" /> {t('installApp.offlineSupport')}</span>
          <span className="flex items-center gap-1"><Download className="h-3.5 w-3.5" /> {t('installApp.fastLoading')}</span>
        </div>
      </div>
    </Modal>
  )
}
