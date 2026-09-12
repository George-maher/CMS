type ImportFn = () => Promise<{ default: React.ComponentType }>

const routeImports: Record<string, ImportFn> = {
  // Admin routes
  '/admin': () => import('@/pages/admin/Dashboard'),
  '/admin/users': () => import('@/pages/admin/Users'),
  '/admin/structure': () => import('@/pages/admin/StructureManagement'),
  '/admin/events': () => import('@/pages/admin/Events'),
  '/admin/leaderboard': () => import('@/pages/admin/Leaderboard'),
  '/admin/attendance': () => import('@/pages/servant/Attendance'),
  '/admin/qr': () => import('@/pages/admin/QRManagement'),
  '/admin/feedback': () => import('@/pages/FeedbackManagement'),
  '/admin/verses': () => import('@/pages/VerseManagement'),
  '/admin/absent-members': () => import('@/pages/AbsentMembers'),
  '/admin/attendance-contexts': () => import('@/pages/AttendanceContextManagement'),
  '/admin/password-reset-requests': () => import('@/pages/admin/PasswordResetRequests'),
  '/admin/profile-update-requests': () => import('@/pages/servant/ProfileUpdateRequests'),
  '/admin/profile': () => import('@/pages/Profile'),

  // Stage Admin routes
  '/stage': () => import('@/pages/stage/Dashboard'),
  '/stage/structure': () => import('@/pages/admin/StructureManagement'),
  '/stage/events': () => import('@/pages/admin/Events'),
  '/stage/attendance': () => import('@/pages/servant/Attendance'),
  '/stage/leaderboard': () => import('@/pages/admin/Leaderboard'),
  '/stage/users': () => import('@/pages/admin/Users'),
  '/stage/qr': () => import('@/pages/admin/QRManagement'),
  '/stage/absent-members': () => import('@/pages/AbsentMembers'),
  '/stage/attendance-contexts': () => import('@/pages/AttendanceContextManagement'),
  '/stage/profile-update-requests': () => import('@/pages/servant/ProfileUpdateRequests'),
  '/stage/profile': () => import('@/pages/Profile'),

  // Servant routes
  '/servant': () => import('@/pages/servant/Dashboard'),
  '/servant/members': () => import('@/pages/servant/Members'),
  '/servant/events': () => import('@/pages/servant/Events'),
  '/servant/attendance': () => import('@/pages/servant/Attendance'),
  '/servant/leaderboard': () => import('@/pages/servant/Leaderboard'),
  '/servant/scan': () => import('@/pages/servant/ScanQR'),
  '/servant/qr': () => import('@/pages/servant/QRInvites'),
  '/servant/feedback': () => import('@/pages/FeedbackManagement'),
  '/servant/absent-members': () => import('@/pages/AbsentMembers'),
  '/servant/attendance-contexts': () => import('@/pages/AttendanceContextManagement'),
  '/servant/profile-update-requests': () => import('@/pages/servant/ProfileUpdateRequests'),
  '/servant/profile': () => import('@/pages/Profile'),

  // Member routes
  '/member': () => import('@/pages/member/Dashboard'),
  '/member/attendance': () => import('@/pages/member/Attendance'),
  '/member/points': () => import('@/pages/member/Points'),
  '/member/events': () => import('@/pages/member/Events'),
  '/member/qr': () => import('@/pages/member/MyQR'),
  '/member/feedback': () => import('@/pages/FeedbackSubmit'),
  '/member/leaderboard': () => import('@/pages/member/Leaderboard'),
  '/member/spiritual-log': () => import('@/pages/member/SpiritualLog'),
  '/member/profile': () => import('@/pages/Profile'),

  // Platform routes
  '/platform': () => import('@/pages/PlatformDashboard'),
  '/platform/churches': () => import('@/pages/platform/ChurchDeletion'),
}

/**
 * Get the lazy import function for a given route path.
 * Handles exact matches and prefix matches for nested routes.
 */
export function getRouteImport(path: string): ImportFn | null {
  if (routeImports[path]) return routeImports[path]

  // For nested routes like /admin/users/123, try parent
  const parts = path.split('/')
  for (let i = parts.length - 1; i > 1; i--) {
    const parentPath = parts.slice(0, i).join('/')
    if (routeImports[parentPath]) return routeImports[parentPath]
  }

  return null
}

/**
 * Get all route import functions for a given role's navigation.
 */
export function getRoleRouteImports(role: string): Array<{ path: string; importFn: ImportFn }> {
  const prefix = role === 'assistant_admin' ? 'admin' : role
  const entries = Object.entries(routeImports).filter(([path]) => path.startsWith(`/${prefix}`))
  return entries.map(([path, importFn]) => ({ path, importFn }))
}
