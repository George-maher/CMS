import { describe, expect, it } from 'vitest'
import { roleTranslationKey } from '@/lib/roles'
import arabic from '@/i18n/ar.json'

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
