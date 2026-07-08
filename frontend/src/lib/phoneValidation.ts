export const PHONE_EXACT_LENGTH = 11

export function validatePhone(
  phone: string | null | undefined,
  t: (key: string) => string,
): string {
  if (!phone) return ''
  if (!/^[0-9]+$/.test(phone)) return t('validation.phoneOnlyNumbers')
  if (phone.length !== PHONE_EXACT_LENGTH) return t('validation.phoneExact11')
  return ''
}

export function filterPhoneInput(value: string): string {
  return value.replace(/[^0-9]/g, '').slice(0, PHONE_EXACT_LENGTH)
}
