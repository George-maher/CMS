import { logCatch } from '@/lib/debug'

/**
 * Generates a unique idempotency key used to de-duplicate QR invite
 * submissions. Falls back to a timestamp+random scheme when the Web Crypto
 * `randomUUID` API is unavailable (non-secure contexts / older browsers).
 */
export function newRequestId(): string {
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID()
    }
  } catch (e) {
    logCatch('requestId.randomUUID', e)
  }

  return `req-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}-${Math.random().toString(36).slice(2, 8)}`
}