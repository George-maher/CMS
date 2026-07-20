import { type ButtonHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { AppWindow, Download, Loader2 } from 'lucide-react'
import { usePwaInstall } from '@/hooks/usePwaInstall'
import InstallAppModal from '@/components/common/InstallAppModal'

export interface AppButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onClick' | 'children'> {
  variant?: 'hero' | 'header' | 'default'
}

export default function AppButton({ variant = 'default', className = '', ...props }: AppButtonProps) {
  const { t } = useTranslation()
  const {
    isInstalled,
    isStandalone,
    isInstalling,
    isWaiting,
    modalType,
    handleAppClick,
    handleCloseModal,
    handleInstalled,
  } = usePwaInstall()

  const isHero = variant === 'hero'
  const isHeader = variant === 'header'
  const ready = isStandalone || isInstalled
  const busy = isInstalling || isWaiting

  const baseStyles = isHero
    ? 'btn-lg rounded-xl px-8 py-3.5 text-base font-semibold inline-flex items-center justify-center gap-2.5 transition-all duration-200 active:scale-[0.97]'
    : isHeader
      ? 'inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 active:scale-[0.97]'
      : 'inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200 active:scale-[0.97]'

  const readyStyles = isHero
    ? 'border border-glass-border bg-white/10 dark:bg-white/5 backdrop-blur-sm text-white hover:bg-white/20 dark:hover:bg-white/10 shadow-sm'
    : 'border border-glass-border bg-glass-bg backdrop-blur-sm text-gray-700 dark:text-gray-200 hover:bg-black/5 dark:hover:bg-white/10 shadow-sm'

  const actionStyles = isHero
    ? 'gold-gradient text-navy-900 shadow-lg shadow-[#d4af37]/30 hover:shadow-xl hover:shadow-[#d4af37]/40 hover:brightness-110'
    : 'gold-gradient text-navy-900 shadow-md shadow-[#d4af37]/25 hover:shadow-lg hover:shadow-[#d4af37]/30 hover:brightness-110'

  const classNameResult = [
    baseStyles,
    busy ? 'opacity-60 cursor-wait gold-gradient text-navy-900' : ready ? readyStyles : actionStyles,
    !busy && !ready ? 'cursor-pointer' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  const iconClass = isHero ? 'h-5 w-5 shrink-0' : 'h-4 w-5 shrink-0'

  return (
    <>
      <button
        onClick={handleAppClick}
        disabled={busy}
        className={classNameResult}
        aria-label={ready ? t('app.openApp') : t('app.installApp')}
        {...props}
      >
        {busy ? (
          <Loader2 className={`${iconClass} animate-spin`} />
        ) : ready ? (
          <AppWindow className={iconClass} />
        ) : (
          <Download className={iconClass} />
        )}

        <span className={isHeader && !isHero ? 'hidden sm:inline' : ''}>
          {busy
            ? isInstalling
              ? t('app.installing')
              : t('common.loading').replace('...', '')
            : ready
              ? t('app.openApp')
              : isHeader
                ? t('app.downloadAppShort')
                : t('app.downloadApp')}
        </span>
      </button>

      <InstallAppModal
        open={modalType !== null}
        type={modalType}
        onClose={handleCloseModal}
        onInstalled={handleInstalled}
      />
    </>
  )
}
