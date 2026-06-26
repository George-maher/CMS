import { useMemo } from 'react'
import type { UserRole } from '../types'

const roleHierarchy: Record<UserRole, number> = {
  platform_admin: 100,
  admin: 80,
  servant: 50,
  member: 10,
}

const roleRedirects: Record<UserRole, string> = {
  platform_admin: '/platform',
  admin: '/admin',
  servant: '/servant',
  member: '/member',
}

export function useRoleAccess(userRole: UserRole | null | undefined) {
  return useMemo(() => {
    const role = userRole ?? null

    const hasAccess = (requiredRole: UserRole): boolean => {
      if (!role) return false
      return roleHierarchy[role] >= roleHierarchy[requiredRole]
    }

    const isAtLeast = (requiredRole: UserRole): boolean => hasAccess(requiredRole)

    const isExactly = (r: UserRole): boolean => role === r

    const getDashboardPath = (): string => {
      if (!role) return '/login'
      return roleRedirects[role] ?? '/login'
    }

    const canManage = (targetRole: UserRole): boolean => {
      if (!role) return false
      if (role === 'platform_admin') return targetRole !== 'platform_admin'
      if (role === 'admin') return targetRole === 'servant' || targetRole === 'member'
      return false
    }

    return {
      role,
      hasAccess,
      isAtLeast,
      isExactly,
      getDashboardPath,
      canManage,
      isPlatformAdmin: role === 'platform_admin',
      isAdmin: role === 'admin',
      isServant: role === 'servant',
      isMember: role === 'member',
    }
  }, [userRole])
}
