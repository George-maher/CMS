import { useState, useCallback } from 'react'
import { Copy, Check } from 'lucide-react'
import toast from 'react-hot-toast'

interface Props {
  value: string
  label?: string
  className?: string
  iconSize?: number
}

export default function CopyButton({ value, label, className = '', iconSize = 14 }: Props) {
  const [justCopied, setJustCopied] = useState(false)

  const handleCopy = useCallback(async (e: React.MouseEvent) => {
    e.stopPropagation()
    e.preventDefault()
    try {
      await navigator.clipboard.writeText(value)
      setJustCopied(true)
      toast.success(label ? `${label} copied` : 'Copied')
      setTimeout(() => setJustCopied(false), 2000)
    } catch {
      toast.error('Failed to copy')
    }
  }, [value, label])

  return (
    <button
      onClick={handleCopy}
      className={`inline-flex items-center justify-center rounded p-1 text-muted hover:text-secondary hover:bg-surface-secondary hover:bg-surface-tertiary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-400 ${className}`}
      title="Copy to clipboard"
      aria-label={label ? `Copy ${label}` : 'Copy to clipboard'}
    >
      {justCopied ? (
        <Check className="text-success-dark" size={iconSize} />
      ) : (
        <Copy size={iconSize} />
      )}
    </button>
  )
}
