import { type ButtonHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { AppWindow, Download } from 'lucide-react'
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
    showIOSModal,
    handleAppClick,
    handleCloseIOSModal,
    handleInstalledOnIOS,
  } = usePwaInstall()

  const isHero = variant === 'hero'
  const isHeader = variant === 'header'

  const baseStyles = isHero
    ? 'btn-lg rounded-xl border border-white/20 px-8 py-3.5 text-base font-semibold text-white hover:bg-white/10 transition-all backdrop-blur-sm'
    : isHeader
      ? 'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-glass-border bg-glass-bg backdrop-blur-sm text-white hover:bg-white/10 transition-all'
      : 'inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border border-glass-border bg-glass-bg backdrop-blur-sm text-white hover:bg-white/10 transition-all'

  return (
    <>
      <button
        onClick={handleAppClick}
        disabled={isInstalling}
        className={`${baseStyles} ${isInstalling ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'} ${className}`}
        aria-label={isStandalone ? t('app.openApp') : t('app.installApp')}
        {...props}
      >
        {isInstalling ? (
          <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
        ) : isStandalone ? (
          <AppWindow className={`${isHero ? 'h-5 w-5' : 'h-4 w-4'}`} />
        ) : (
          <Download className={`${isHero ? 'h-5 w-5' : 'h-4 w-4'}`} />
        )}
        {isStandalone ? t('app.openApp') : t('app.installApp')}
      </button>

      <InstallAppModal
        open={showIOSModal}
        onClose={handleCloseIOSModal}
        onInstalled={handleInstalledOnIOS}
      />
    </>
  )
}
