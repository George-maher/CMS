import type { ReactNode } from 'react'

interface Props {
  children: ReactNode
  className?: string
  hover?: boolean
  solid?: boolean
  as?: 'div' | 'section' | 'article'
}

export default function GlassCard({ children, className = '', hover = true, solid = false, as: Tag = 'div' }: Props) {
  const base = solid ? 'glass-card-solid' : 'glass-card'

  return (
    <Tag className={`${base} p-5 ${hover ? '' : 'hover:!transform-none hover:!shadow-none'} ${className}`}>
      {children}
    </Tag>
  )
}
