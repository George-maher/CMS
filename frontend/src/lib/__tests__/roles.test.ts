import { describe, expect, it } from 'vitest'
import { roleBadgeVariant, roleDashboardPath, roleTranslationKey } from '@/lib/roles'
import arabic from '@/i18n/ar.json'
import type { UserRole } from '@/types'

const ALL_ROLES: UserRole[] = [
  'platform_admin',
  'admin',
  'assistant_admin',
  'stage_admin',
  'servant',
  'member',
]

/**
 * Regression coverage for the role -> dashboard mapping (F-09).
 *
 * This map used to be duplicated in Login.tsx, AppLayout.tsx and
 * ApplicationStatus.tsx, with a fourth, contradictory copy in
 * useRoleAccess.ts that sent `assistant_admin` to `/admin` while every other
 * component sent the same user to `/assistant-admin`.
 */
describe('roleDashboardPath', () => {
  it('maps every role to exactly one dashboard', () => {
    expect(roleDashboardPath('platform_admin')).toBe('/platform')
    expect(roleDashboardPath('admin')).toBe('/admin')
    expect(roleDashboardPath('assistant_admin')).toBe('/assistant-admin')
    expect(roleDashboardPath('stage_admin')).toBe('/stage')
    expect(roleDashboardPath('servant')).toBe('/servant')
    expect(roleDashboardPath('member')).toBe('/member')
  })

  it('never routes the assistant admin to the admin dashboard', () => {
    expect(roleDashboardPath('assistant_admin')).toBe('/assistant-admin')
    expect(roleDashboardPath('assistant_admin')).not.toBe('/admin')
  })

  it('gives every role a distinct destination', () => {
    const destinations = ALL_ROLES.map(roleDashboardPath)

    expect(new Set(destinations).size).toBe(ALL_ROLES.length)
  })

  it('falls back to the login screen for a missing or unknown role', () => {
    expect(roleDashboardPath(null)).toBe('/login')
    expect(roleDashboardPath(undefined)).toBe('/login')
    expect(roleDashboardPath('')).toBe('/login')
    expect(roleDashboardPath('not_a_role')).toBe('/login')
  })
})

describe('role label helpers', () => {
  it('keys a translation for every role', () => {
    const keys = ALL_ROLES.map(roleTranslationKey)

    expect(new Set(keys).size).toBe(ALL_ROLES.length)
    for (const key of keys) {
      expect(key).not.toBe('common.unknown')
    }
  })

  it('renders the assistant admin with the same badge variant as an admin', () => {
    expect(roleBadgeVariant('assistant_admin')).toBe(roleBadgeVariant('admin'))
  })
})

describe('Arabic administrative role labels', () => {
  it('keeps equivalent church administrator roles on the agreed title', () => {
    expect(roleTranslationKey('admin')).toBe('users.roleAdmin')
    expect(roleTranslationKey('assistant_admin')).toBe('users.roleAssistantAdmin')
    expect(arabic.users.roleAdmin).toBe('الأمين العام')
    expect(arabic.users.roleAssistantAdmin).toBe('الأمين العام')
  })

  it('uses the agreed Stage Admin title', () => {
    expect(roleTranslationKey('stage_admin')).toBe('users.roleStageAdmin')
    expect(arabic.users.roleStageAdmin).toBe('أمين المرحلة')
  })
})
