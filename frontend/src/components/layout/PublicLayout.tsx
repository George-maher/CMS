import type { ReactNode } from 'react'
import PublicHeader from './PublicHeader'
import PublicFooter from './PublicFooter'

interface Props {
  children: ReactNode
  showFooter?: boolean
}

export default function PublicLayout({ children, showFooter = true }: Props) {
  return (
    <div className="flex min-h-screen flex-col bg-surface-secondary">
      <PublicHeader />
      <main className="flex-1">
        {children}
      </main>
      {showFooter && <PublicFooter />}
    </div>
  )
}
