/**
 * In-memory API response cache.
 *
 * Two properties are load-bearing for tenant isolation and correctness:
 *
 *  1. `invalidateCache()` must remove EVERY entry when called with no
 *     argument. That is what login, logout and the 401 interceptor call, and
 *     it is the mechanism that stops one session reading another session's
 *     cached API responses.
 *
 *  2. The monotonic generation guard: a background stale-while-revalidate
 *     refresh must not repopulate a cache key that a mutation invalidated
 *     while the refresh was in flight. Without it, a mutation's invalidation
 *     is silently undone seconds later and the UI shows pre-mutation data.
 *
 * Cache KEYS are built in `src/api/client.ts` and are prefixed with a hash of
 * the bearer token, which is what scopes entries to a session. That prefix is
 * asserted structurally in authSession.test.tsx by observing that a session
 * change clears the cache.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
  setCache,
  getCached,
  isStale,
  invalidateCache,
  getGeneration,
} from '@/lib/requestCache'

describe('request cache', () => {
  beforeEach(() => {
    // invalidateCache() with no pattern is the full clear.
    invalidateCache()
  })

  it('returns a cached value within its TTL', () => {
    setCache('anon:/stages:{}', [{ id: 1 }])

    expect(getCached('anon:/stages:{}')).toEqual([{ id: 1 }])
    expect(isStale('anon:/stages:{}')).toBe(false)
  })

  it('reports an unknown key as a miss', () => {
    expect(getCached('nothing-here')).toBeNull()
    expect(isStale('nothing-here')).toBe(true)
  })

  /**
   * The session-change guarantee. Keys are token-scoped, so a full clear is
   * what guarantees the next account cannot read this one's responses.
   */
  it('a full invalidation removes every entry regardless of tenant', () => {
    setCache('tenantA:/stages:{}', 'a-stages')
    setCache('tenantB:/classes:{}', 'b-classes')
    setCache('tenantA:/users:{}', 'a-users')

    invalidateCache()

    expect(getCached('tenantA:/stages:{}')).toBeNull()
    expect(getCached('tenantB:/classes:{}')).toBeNull()
    expect(getCached('tenantA:/users:{}')).toBeNull()
  })

  it('a pattern invalidation only removes matching keys', () => {
    setCache('t:/stages:{}', 'stages')
    setCache('t:/users:{}', 'users')

    invalidateCache('/stages')

    expect(getCached('t:/stages:{}')).toBeNull()
    expect(getCached('t:/users:{}')).toBe('users')
  })

  /**
   * Substring matching is what makes one mutation invalidate several surfaces
   * (e.g. deleting a class also staling /structure and /users).
   */
  it('pattern invalidation matches by substring, not by exact key', () => {
    setCache('t:/classes/12/members', 'members')
    setCache('t:/dashboard/stats', 'stats')

    invalidateCache('/classes')

    expect(getCached('t:/classes/12/members')).toBeNull()
    expect(getCached('t:/dashboard/stats')).toBe('stats')
  })

  it('bumps the generation on every invalidation, even when nothing matched', () => {
    const before = getGeneration()

    invalidateCache('no-such-pattern')

    expect(getGeneration()).toBeGreaterThan(before)
  })

  it('an entry captured before an invalidation is treated as stale afterwards', () => {
    const key = 't:/stages:{}'
    setCache(key, 'value')
    const generationAtCapture = getGeneration()

    invalidateCache('/stages')

    // This is the check a background refresh performs before writing its
    // result back: if the generation moved, the refresh result is discarded.
    expect(getGeneration()).not.toBe(generationAtCapture)
    expect(getCached(key)).toBeNull()
  })

  it('expires an entry once its TTL has passed', () => {
    vi.useFakeTimers()
    try {
      setCache('t:/notifications/unread-count:{}', { unread: 1 })
      expect(getCached('t:/notifications/unread-count:{}')).toEqual({ unread: 1 })

      // The shortest configured window is 15s ttl + 30s swr.
      vi.advanceTimersByTime(120_000)

      expect(getCached('t:/notifications/unread-count:{}')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })
})
