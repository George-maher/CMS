import { useState } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import Sidebar from './Sidebar'
import Header from './Header'
import LoadingSpinner from '@/components/common/LoadingSpinner'

interface Props {
  allowedRoles: string[]
}

export default function AppLayout({ allowedRoles }: Props) {
  const { user, isAuthenticated, isLoading } = useAuth()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const location = useLocation()

  if (isLoading) return (
    <div className="flex h-screen items-center justify-center bg-surface-secondary">
      <LoadingSpinner size="lg" />
    </div>
  )

  if (!isAuthenticated) return <Navigate to="/login" replace />

  if (user?.application_status === 'pending') return <Navigate to="/pending" replace />
  if (user?.application_status === 'rejected') return <Navigate to="/rejected" replace />

  if (user && !allowedRoles.includes(user.role)) {
    const redirectMap: Record<string, string> = { platform_admin: '/platform', admin: '/admin', assistant_admin: '/assistant-admin', servant: '/servant', member: '/member' }
    return <Navigate to={redirectMap[user.role] || '/login'} replace />
  }

  return (
    <div className="flex h-screen overflow-hidden bg-surface-secondary" key={location.pathname}>
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className="flex flex-1 flex-col overflow-hidden min-w-0">
        <Header onMenuClick={() => setSidebarOpen(true)} />
        <main className="flex-1 overflow-y-auto overflow-x-hidden">
          <div className="page-container">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
