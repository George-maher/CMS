import { type ButtonHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { AppWindow, Download } from 'lucide-react'
import { usePwaInstall } from '@/hooks/usePwaInstall'
import IOSInstallGuide from '@/components/common/IOSInstallGuide'

export interface AppButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onClick' | 'children'> {
  variant?: 'hero' | 'header' | 'default'
}

export default function AppButton({ variant = 'default', className = '', ...props }: AppButtonProps) {
  const { t } = useTranslation()
  const { isInstalled, showIOSGuide, handleAppClick, closeIOSGuide } = usePwaInstall()

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

  const classNameResult = [baseStyles, isInstalled ? installedStyles : actionStyles, className].filter(Boolean).join(' ')

  return (
    <>
      <button onClick={handleAppClick} className={classNameResult} aria-label={isInstalled ? t('app.openApp') : t('app.downloadApp')} {...props}>
        {isInstalled ? <AppWindow className="h-4 w-5 shrink-0" /> : <Download className="h-4 w-5 shrink-0" />}
        <span className={isHeader ? 'hidden sm:inline' : ''}>{isInstalled ? t('app.openApp') : t('app.downloadApp')}</span>
      </button>

      <IOSInstallGuide open={showIOSGuide} onClose={closeIOSGuide} />
    </>
  )
}
