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
    servant: 'info',
    member: 'success',
  }
  return map[role] ?? 'default'
}