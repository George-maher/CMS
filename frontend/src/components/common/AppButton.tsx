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

  const baseStyles = isHero
    ? 'btn-lg rounded-xl border border-white/20 px-8 py-3.5 text-base font-semibold text-white hover:bg-white/10 transition-all backdrop-blur-sm inline-flex items-center justify-center gap-2'
    : isHeader
      ? 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-glass-border bg-glass-bg backdrop-blur-sm text-gray-800 dark:text-white hover:bg-black/5 dark:hover:bg-white/10 transition-all'
      : 'inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border border-glass-border bg-glass-bg backdrop-blur-sm text-gray-800 dark:text-white hover:bg-black/5 dark:hover:bg-white/10 transition-all'

  const iconClass = isHero ? 'h-5 w-5' : 'h-4 w-5'

  return (
    <>
      <button
        onClick={handleAppClick}
        disabled={isInstalling}
        className={`${baseStyles} ${isInstalling || isWaiting ? 'opacity-60 cursor-wait' : 'cursor-pointer'} ${className}`}
        aria-label={isStandalone ? t('app.openApp') : t('app.installApp')}
        {...props}
      >
        {isInstalling || isWaiting ? (
          <Loader2 className={`${iconClass} animate-spin`} />
        ) : isStandalone ? (
          <AppWindow className={iconClass} />
        ) : (
          <Download className={iconClass} />
        )}
        {isWaiting ? t('common.loading').replace('...', '') : isStandalone ? t('app.openApp') : t('app.installApp')}
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
