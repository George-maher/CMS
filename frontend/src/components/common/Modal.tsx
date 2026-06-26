import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'

interface Props {
  isOpen: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'
  closeOnOverlayClick?: boolean
}

export default function Modal({
  isOpen, onClose, title, children, footer,
  size = 'md', closeOnOverlayClick = true,
}: Props) {
  const dialogRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    if (isOpen) {
      document.addEventListener('keydown', handleEsc)
      const prev = document.body.style.overflow
      document.body.style.overflow = 'hidden'
      return () => { document.removeEventListener('keydown', handleEsc); document.body.style.overflow = prev }
    }
    return () => document.removeEventListener('keydown', handleEsc)
  }, [isOpen, onClose])

  if (!isOpen) return null

  return createPortal(
    <div className="modal-overlay" onClick={closeOnOverlayClick ? onClose : undefined} role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <div ref={dialogRef} className={`modal-content ${size}`} onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <div className="flex-1 min-w-0">
            <h2 id="modal-title" className="text-lg font-semibold truncate gold-text">{title}</h2>
          </div>
          <button onClick={onClose} className="btn-icon btn-ghost rounded-lg shrink-0" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}
