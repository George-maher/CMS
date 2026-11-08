import { useMemo } from 'react'
import type { UserRole } from '@/types'
import { roleDashboardPath } from '@/lib/roles'

/**
 * Effective capability level per role.
 *
 * `assistant_admin` is deliberately EQUAL to `admin`. The product decision is
 * that the assistant admin is an admin in every respect, and the backend
 * mirrors it in `User::isAdmin()` (`role === Admin || role === AssistantAdmin`).
 * A stricter hierarchy here (`assistant_admin: 70`) made
 * `hasAccess('admin')` false for assistant admins, so any UI gated behind
 * `isAtLeast('admin')` silently disappeared for them.
 *
 * This is a UX control only. All authorization is enforced server-side.
 */
const roleLevel: Record<UserRole, number> = {
  platform_admin: 100,
  admin: 80,
  assistant_admin: 80,
  stage_admin: 60,
  servant: 50,
  member: 10,
}

export function useRoleAccess(userRole: UserRole | null | undefined) {
  return useMemo(() => {
    const role = userRole ?? null

    const hasAccess = (requiredRole: UserRole): boolean => {
      if (!role) return false
      return roleLevel[role] >= roleLevel[requiredRole]
    }

    const isAtLeast = (requiredRole: UserRole): boolean => hasAccess(requiredRole)

    const isExactly = (r: UserRole): boolean => role === r

    const getDashboardPath = (): string => roleDashboardPath(role)

    // Mirrors the backend capability model rather than the level table: a
    // platform admin may manage everyone except another platform admin, and
    // admins (including assistant admins) may manage servants and members.
    // Stage admins are intentionally absent — their server-side range is
    // narrower and stage-relative, and this hook has no stage context, so
    // returning false here can only hide UI, never grant it.
    const canManage = (targetRole: UserRole): boolean => {
      if (!role) return false
      if (role === 'platform_admin') return targetRole !== 'platform_admin'
      if (role === 'admin' || role === 'assistant_admin') return targetRole === 'servant' || targetRole === 'member'
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
      isAdmin: role === 'admin' || role === 'assistant_admin',
      isAssistantAdmin: role === 'assistant_admin',
      isStageAdmin: role === 'stage_admin',
      isServant: role === 'servant',
      isMember: role === 'member',
    }
  }, [userRole])
}
