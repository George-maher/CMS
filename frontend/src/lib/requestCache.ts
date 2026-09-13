interface CacheEntry {
  data: unknown
  expiresAt: number
  staleAt: number
}

interface CacheConfig {
  ttl: number
  staleWhileRevalidate: number
}

const DEFAULT_CONFIG: CacheConfig = { ttl: 60_000, staleWhileRevalidate: 300_000 }

const ENDPOINT_CONFIGS: Record<string, CacheConfig> = {
  '/stages': { ttl: 300_000, staleWhileRevalidate: 600_000 },
  '/classes': { ttl: 300_000, staleWhileRevalidate: 600_000 },
  '/attendances-contexts': { ttl: 300_000, staleWhileRevalidate: 600_000 },
  '/daily-verse': { ttl: 600_000, staleWhileRevalidate: 900_000 },
  '/users/members': { ttl: 120_000, staleWhileRevalidate: 300_000 },
  '/users/servants': { ttl: 120_000, staleWhileRevalidate: 300_000 },
  '/dashboard': { ttl: 60_000, staleWhileRevalidate: 120_000 },
  '/notifications/unread-count': { ttl: 15_000, staleWhileRevalidate: 30_000 },
  '/notifications': { ttl: 30_000, staleWhileRevalidate: 60_000 },
  '/attendances/today': { ttl: 30_000, staleWhileRevalidate: 60_000 },
  '/leaderboard': { ttl: 60_000, staleWhileRevalidate: 120_000 },
  '/events': { ttl: 60_000, staleWhileRevalidate: 120_000 },
  '/users': { ttl: 60_000, staleWhileRevalidate: 120_000 },
}

const cache = new Map<string, CacheEntry>()
const inflight = new Map<string, Promise<unknown>>()

/*
 * Monotonic invalidation generation. Bumped every time invalidateCache() runs
 * (even when it deletes nothing). Background stale-while-revalidate refreshes
 * capture the generation when they START and skip repopulating the cache if it
 * has been invalidated since — otherwise a mutation that invalidates a cache
 * key could be undone by a stale refresh that resolves moments later.
 */
let generation = 0

export function getGeneration(): number {
  return generation
}

function getConfig(key: string): CacheConfig {
  for (const [pattern, config] of Object.entries(ENDPOINT_CONFIGS)) {
    if (key.includes(pattern)) return config
  }
  return DEFAULT_CONFIG
}

export function getCached(key: string): unknown | null {
  const entry = cache.get(key)
  if (!entry) return null
  if (Date.now() > entry.expiresAt) {
    cache.delete(key)
    return null
  }
  return entry.data
}

export function setCache(key: string, data: unknown): void {
  const config = getConfig(key)
  const now = Date.now()
  cache.set(key, {
    data,
    expiresAt: now + config.ttl + config.staleWhileRevalidate,
    staleAt: now + config.ttl,
  })
}

export function isStale(key: string): boolean {
  const entry = cache.get(key)
  if (!entry) return true
  return Date.now() > entry.staleAt
}

export function getInflight(key: string): Promise<unknown> | null {
  return inflight.get(key) ?? null
}

export function setInflight(key: string, promise: Promise<unknown>): void {
  inflight.set(key, promise)
  promise.finally(() => inflight.delete(key))
}

export function invalidateCache(pattern?: string): void {
  generation += 1
  if (!pattern) {
    cache.clear()
    return
  }
  for (const k of cache.keys()) {
    if (k.includes(pattern)) cache.delete(k)
  }
}
