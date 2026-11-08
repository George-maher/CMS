import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useRoleAccess } from '@/hooks/useRoleAccess'
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
 * Regression coverage for F-09.
 *
 * `useRoleAccess` used to rank `assistant_admin` below `admin` and to exclude
 * it from `canManage`, even though the product decision (mirrored by the
 * backend's `User::isAdmin()`) is that the assistant admin is an admin in every
 * respect. Any UI gated behind `isAtLeast('admin')` silently disappeared for
 * assistant admins.
 *
 * This hook is a UX control only: authorization is always enforced
 * server-side.
 */
describe('useRoleAccess', () => {
  it('gives the assistant admin exactly the same access as an admin', () => {
    const { result: admin } = renderHook(() => useRoleAccess('admin'))
    const { result: assistant } = renderHook(() => useRoleAccess('assistant_admin'))

    for (const role of ALL_ROLES) {
      expect(assistant.current.hasAccess(role)).toBe(admin.current.hasAccess(role))
      expect(assistant.current.isAtLeast(role)).toBe(admin.current.isAtLeast(role))
      expect(assistant.current.canManage(role)).toBe(admin.current.canManage(role))
    }

    expect(admin.current.isAdmin).toBe(true)
    expect(assistant.current.isAdmin).toBe(true)
    expect(assistant.current.isAssistantAdmin).toBe(true)
    expect(assistant.current.isPlatformAdmin).toBe(false)
  })

  it('keeps every restricted role out of admin-level access', () => {
    for (const role of ['stage_admin', 'servant', 'member'] as const) {
      const { result } = renderHook(() => useRoleAccess(role))

      expect(result.current.hasAccess('admin')).toBe(false)
      expect(result.current.isAdmin).toBe(false)
      expect(result.current.canManage('member')).toBe(false)
      expect(result.current.canManage('servant')).toBe(false)
    }
  })

  it('lets admins manage servants and members but not other admins', () => {
    const { result } = renderHook(() => useRoleAccess('admin'))

    expect(result.current.canManage('servant')).toBe(true)
    expect(result.current.canManage('member')).toBe(true)
    expect(result.current.canManage('admin')).toBe(false)
    expect(result.current.canManage('platform_admin')).toBe(false)
  })

  it('routes each role to the dashboard from the shared role map', () => {
    expect(renderHook(() => useRoleAccess('assistant_admin')).result.current.getDashboardPath()).toBe('/assistant-admin')
    expect(renderHook(() => useRoleAccess('stage_admin')).result.current.getDashboardPath()).toBe('/stage')
    expect(renderHook(() => useRoleAccess('member')).result.current.getDashboardPath()).toBe('/member')
  })

  it('denies everything and falls back to login when unauthenticated', () => {
    for (const role of [null, undefined]) {
      const { result } = renderHook(() => useRoleAccess(role))

      expect(result.current.role).toBeNull()
      expect(result.current.hasAccess('member')).toBe(false)
      expect(result.current.isAdmin).toBe(false)
      expect(result.current.canManage('member')).toBe(false)
      expect(result.current.getDashboardPath()).toBe('/login')
    }
  })
})
