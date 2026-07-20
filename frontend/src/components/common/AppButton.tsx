import { type ButtonHTMLAttributes, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { AppWindow, Download, Smartphone } from 'lucide-react'
import { usePwaInstall, type PwaInstallStatus } from '@/hooks/usePwaInstall'
import IOSInstallGuide from '@/components/common/IOSInstallGuide'

export interface AppButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onClick' | 'children'> {
  variant?: 'hero' | 'header' | 'default'
}

export default function AppButton({ variant = 'default', className = '', ...props }: AppButtonProps) {
  const { t } = useTranslation()
  const { status, showIOSGuide, handleAppClick, closeIOSGuide } = usePwaInstall()
  const [showTooltip, setShowTooltip] = useState(false)
  const tooltipTimer = useRef<number | null>(null)

  const isHero = variant === 'hero'
  const isHeader = variant === 'header'

  const baseStyles = isHero
    ? 'btn-lg rounded-xl px-8 py-3.5 text-base font-semibold inline-flex items-center justify-center gap-2.5 transition-all duration-200 active:scale-[0.97]'
    : isHeader
      ? 'inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 active:scale-[0.97]'
      : 'inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200 active:scale-[0.97]'

  const installedStyles = isHero
    ? 'border border-white/20 bg-white/10 dark:bg-white/5 backdrop-blur-sm text-white hover:bg-white/20 dark:hover:bg-white/10 shadow-sm'
    : 'border border-glass-border bg-glass-bg backdrop-blur-sm text-gray-700 dark:text-gray-200 hover:bg-black/5 dark:hover:bg-white/10 shadow-sm'

  const actionStyles = isHero
    ? 'gold-gradient text-navy-900 shadow-lg shadow-[#d4af37]/30 hover:shadow-xl hover:shadow-[#d4af37]/40 hover:brightness-110'
    : 'gold-gradient text-navy-900 shadow-md shadow-[#d4af37]/25 hover:shadow-lg hover:shadow-[#d4af37]/30 hover:brightness-110'

  const showOpen = status === 'installed'
  const label = showOpen ? t('app.openApp') : t('app.downloadApp')
  const Icon = showOpen ? AppWindow : Download

  useEffect(() => {
    return () => {
      if (tooltipTimer.current !== null) clearTimeout(tooltipTimer.current)
    }
  }, [])

  const classNameResult = [baseStyles, showOpen ? installedStyles : actionStyles, className].filter(Boolean).join(' ')

  const statusMessages: Record<PwaInstallStatus, string | null> = {
    installed: null,
    ready: null,
    ios: null,
    waiting: 'pwa.installWaitingDesc',
    unsupported: 'pwa.installUnsupportedDesc',
  }

  const handleClick = async () => {
    if (status === 'installed') {
      setShowTooltip(true)
      if (tooltipTimer.current !== null) clearTimeout(tooltipTimer.current)
      tooltipTimer.current = window.setTimeout(() => setShowTooltip(false), 3000)
      return
    }

    const result = await handleAppClick()

    if (result === 'installed' || result === 'ios_guide' || result === 'dismissed') {
      return
    }

    const messageKey = statusMessages[status]
    if (messageKey) {
      toast(t(messageKey), {
        duration: 4000,
        icon: <Smartphone className="h-4 w-4" />,
        style: { borderRadius: '12px', padding: '12px 16px', fontSize: '13px' },
      })
    }
  }

  return (
    <>
      <div className="relative inline-flex">
        <button onClick={handleClick} className={classNameResult} aria-label={label} {...props}>
          <Icon className="h-4 w-5 shrink-0" />
          <span className={isHeader ? 'hidden sm:inline' : ''}>{label}</span>
        </button>

        {showTooltip && (
          <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap shadow-lg animate-fade-in bg-navy-800 text-white dark:bg-surface dark:text-text-primary border border-glass-border z-50">
            <Smartphone className="h-3 w-3 inline mr-1" />
            {t('app.alreadyInstalled')}
          </div>
        )}
      </div>

      <IOSInstallGuide open={showIOSGuide} onClose={closeIOSGuide} />
    </>
  )
}
