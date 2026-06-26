import type { ReactNode } from 'react'

interface Props {
  children: ReactNode
  className?: string
}

export default function PageTransition({ children, className = '' }: Props) {
  return (
    <div className={`animate-fade-in-up ${className}`}>
      {children}
    </div>
  )
}
