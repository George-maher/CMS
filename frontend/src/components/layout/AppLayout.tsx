import { Suspense, useCallback, useState } from 'react'
import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import Sidebar from './Sidebar'
import Header from './Header'
import { PageSkeleton } from '@/components/common/Skeleton'

interface Props {
  allowedRoles: string[]
}

export default function AppLayout({ allowedRoles }: Props) {
  const { user, isAuthenticated, isLoading } = useAuth()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const closeSidebar = useCallback(() => setSidebarOpen(false), [])

  if (isLoading) return <PageSkeleton />

  if (!isAuthenticated) return <Navigate to="/login" replace />

  if (user?.application_status === 'pending' || user?.application_status === 'rejected') return <Navigate to="/application-status" replace />

  if (user && !allowedRoles.includes(user.role)) {
    const redirectMap: Record<string, string> = { platform_admin: '/platform', admin: '/admin', assistant_admin: '/assistant-admin', servant: '/servant', member: '/member' }
    return <Navigate to={redirectMap[user.role] || '/login'} replace />
  }

  return (
    <div className="flex h-screen overflow-hidden bg-surface-secondary">
      <Sidebar isOpen={sidebarOpen} onClose={closeSidebar} />
      <div className="flex flex-1 flex-col overflow-hidden min-w-0">
        <Header onMenuClick={() => setSidebarOpen(true)} />
        <main className="flex-1 overflow-y-auto overflow-x-hidden">
          <div className="page-container">
            <Suspense fallback={<div className="flex items-center justify-center py-20"><div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" /></div>}>
              <Outlet />
            </Suspense>
          </div>
        </main>
      </div>
    </div>
  )
}
