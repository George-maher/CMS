import type { ReactNode } from 'react'

interface Props {
  children: ReactNode
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info' | 'primary' | 'gold'
  className?: string
}

const variantMap: Record<string, string> = {
  default: 'badge-default',
  success: 'badge-success',
  warning: 'badge-warning',
  danger: 'badge-danger',
  info: 'badge-info',
  primary: 'badge-primary',
  gold: 'badge-gold',
}

export default function Badge({ children, variant = 'default', className }: Props) {
  return (
    <span className={`badge ${variantMap[variant]} ${className ?? ''}`}>
      {children}
    </span>
  )
}
