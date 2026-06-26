import type { AttendanceContext } from '@/types'

/**
 * Returns the localized name of an attendance context.
 * When language is 'ar' and name_ar exists, returns name_ar.
 */
export function ctxName(ctx: AttendanceContext | { name: string; name_ar?: string | null }, lang: 'en' | 'ar'): string {
  if (lang === 'ar' && ctx.name_ar) return ctx.name_ar
  return ctx.name
}

/**
 * Formats a context option for a dropdown label.
 */
export function ctxOptionLabel(ctx: AttendanceContext | { name: string; name_ar?: string | null }, lang: 'en' | 'ar'): string {
  if (lang === 'ar' && ctx.name_ar) return `${ctx.name_ar} (${ctx.name})`
  return ctx.name
}
