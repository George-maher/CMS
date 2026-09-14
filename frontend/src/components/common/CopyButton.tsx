import { useState, useCallback } from 'react'
import { Copy, Check } from 'lucide-react'
import toast from 'react-hot-toast'
import { useTranslation } from 'react-i18next'

interface Props {
  value: string
  label?: string
  className?: string
  iconSize?: number
}

export default function CopyButton({ value, label, className = '', iconSize = 14 }: Props) {
  const { t } = useTranslation()
  const [justCopied, setJustCopied] = useState(false)

  const handleCopy = useCallback(async (e: React.MouseEvent) => {
    e.stopPropagation()
    e.preventDefault()
    try {
      await navigator.clipboard.writeText(value)
      setJustCopied(true)
      toast.success(label ? t('common.copyLabel', { label }) : t('common.copied'))
      setTimeout(() => setJustCopied(false), 2000)
    } catch {
      toast.error(t('common.copyFailed'))
    }
  }, [value, label, t])

  return (
    <button
      onClick={handleCopy}
      className={`inline-flex items-center justify-center rounded p-1 text-muted hover:text-secondary hover:bg-surface-secondary hover:bg-surface-tertiary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-400 ${className}`}
      title={label ? t('common.copyLabel', { label }) : t('common.copyToClipboard')}
      aria-label={label ? t('common.copyLabel', { label }) : t('common.copyToClipboard')}
    >
      {justCopied ? (
        <Check className="text-success-dark" size={iconSize} />
      ) : (
        <Copy size={iconSize} />
      )}
    </button>
  )
}
