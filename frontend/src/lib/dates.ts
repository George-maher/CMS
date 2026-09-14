import i18n from '@/i18n'

const locale = (): string => (i18n.language?.startsWith('ar') ? 'ar-EG' : 'en-US')

function parse(value: Date | string | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null
  const d = typeof value === 'string' ? new Date(value) : value
  return Number.isNaN(d.getTime()) ? null : d
}

export const fmtDate = (value: Date | string | null | undefined, opts?: Intl.DateTimeFormatOptions): string => {
  const d = parse(value)
  return d ? d.toLocaleDateString(locale(), opts) : '-'
}

export const fmtTime = (value: Date | string | null | undefined, opts?: Intl.DateTimeFormatOptions): string => {
  const d = parse(value)
  return d ? d.toLocaleTimeString(locale(), opts) : '-'
}

export const fmtDateTime = (value: Date | string | null | undefined, opts?: Intl.DateTimeFormatOptions): string => {
  const d = parse(value)
  return d ? d.toLocaleString(locale(), opts) : '-'
}