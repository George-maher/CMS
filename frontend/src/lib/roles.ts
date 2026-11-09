import type { UserRole } from '@/types'

/**
 * Maps a backend UserRole value to its i18n translation key
 * under the "users" namespace.
 *
 * Never use `role_label` from the API directly since it's always
 * English and does not change with language switching.
 */
export function roleTranslationKey(role: UserRole): string {
  const map: Record<UserRole, string> = {
    platform_admin: 'users.rolePlatformAdmin',
    admin: 'users.roleAdmin',
    assistant_admin: 'users.roleAssistantAdmin',
    stage_admin: 'users.roleStageAdmin',
    servant: 'users.roleServant',
    member: 'users.roleMember',
  }
  return map[role] ?? 'common.unknown'
}

/**
 * Maps a backend UserRole value to its Badge variant.
 */
export function roleBadgeVariant(role: UserRole): 'warning' | 'info' | 'success' | 'primary' {
  const map: Record<UserRole, 'warning' | 'info' | 'success' | 'primary'> = {
    platform_admin: 'primary',
    admin: 'warning',
    assistant_admin: 'warning',
    stage_admin: 'warning',
    servant: 'info',
    member: 'success',
  }
  return map[role] ?? 'default'
}

/**
 * Landing route per role — the single source of truth for post-login,
 * post-approval and "wrong dashboard for this role" redirects.
 *
 * This map used to be duplicated in Login.tsx, AppLayout.tsx and
 * ApplicationStatus.tsx (and a fourth, inconsistent copy inside
 * useRoleAccess.ts sent `assistant_admin` to `/admin`). Keeping one map means
 * a role can no longer be routed to two different dashboards depending on
 * which component performed the redirect.
 */
const ROLE_DASHBOARD_PATHS: Readonly<Record<string, string>> = {
  platform_admin: '/platform',
  admin: '/admin',
  assistant_admin: '/assistant-admin',
  stage_admin: '/stage',
  servant: '/servant',
  member: '/member',
}

/**
 * Resolve the dashboard route for a role, falling back to the login screen for
 * an unknown or missing role.
 */
export function roleDashboardPath(role: string | null | undefined): string {
  if (!role) return '/login'
  return ROLE_DASHBOARD_PATHS[role] || '/login'
}

/**
 * Base path for the shared structure screens (stage list, stage detail, class
 * detail). The same components are mounted under /admin, /assistant-admin and
 * /stage, so links inside them must be derived from the signed-in role instead
 * of being hardcoded to /admin.
 */
const STRUCTURE_BASE_PATHS: Readonly<Record<string, string>> = {
  admin: '/admin',
  assistant_admin: '/assistant-admin',
  stage_admin: '/stage',
}

export function structureBasePath(role: string | null | undefined): string {
  if (!role) return '/admin'
  return STRUCTURE_BASE_PATHS[role] ?? '/admin'
}

/**
 * Whether the role may create/edit classes, appoint a class secretary or
 * manage servants. The backend enforces the same boundary; this only decides
 * which controls are rendered.
 */
export function canManageStructure(role: string | null | undefined): boolean {
  return role === 'admin' || role === 'assistant_admin' || role === 'stage_admin'
}