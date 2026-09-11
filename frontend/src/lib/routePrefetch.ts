const prefetched = new Set<string>()

/**
 * Prefetch a lazy-loaded route chunk.
 * The import() call is cached by the browser after first execution,
 * so calling this again is a no-op (returns the already-resolved module).
 */
export function prefetchRoute(importFn: () => Promise<unknown>, key: string): void {
  if (prefetched.has(key)) return
  prefetched.add(key)
  importFn().catch(() => {
    prefetched.delete(key)
  })
}

/**
 * Prefetch a route chunk on user interaction (hover/focus).
 * Uses a small delay to avoid prefetching on brief hovers.
 */
export function prefetchOnInteraction(
  importFn: () => Promise<unknown>,
  key: string,
  delay = 200,
): { onMouseEnter: () => void; onFocus: () => void; onMouseLeave: () => void } {
  let timer: ReturnType<typeof setTimeout> | null = null

  return {
    onMouseEnter: () => {
      timer = setTimeout(() => prefetchRoute(importFn, key), delay)
    },
    onFocus: () => {
      prefetchRoute(importFn, key)
    },
    onMouseLeave: () => {
      if (timer) {
        clearTimeout(timer)
        timer = null
      }
    },
  }
}

/**
 * Prefetch a route chunk after browser becomes idle.
 * Uses requestIdleCallback with fallback to setTimeout.
 */
export function prefetchWhenIdle(
  importFn: () => Promise<unknown>,
  key: string,
  timeout = 2000,
): void {
  if (prefetched.has(key)) return

  const doPrefetch = () => prefetchRoute(importFn, key)

  if ('requestIdleCallback' in window) {
    (window as Window & { requestIdleCallback: (cb: () => void, opts?: { timeout: number }) => void }).requestIdleCallback(doPrefetch, { timeout })
  } else {
    setTimeout(doPrefetch, timeout)
  }
}

/**
 * Prefetch multiple routes in sequence during idle time.
 */
export function prefetchRoutesWhenIdle(
  routes: Array<{ importFn: () => Promise<unknown>; key: string }>,
  staggerMs = 100,
): void {
  let index = 0

  const scheduleNext = () => {
    if (index >= routes.length) return
    const route = routes[index]!
    index++

    const doPrefetch = () => {
      prefetchRoute(route.importFn, route.key)
      if (index < routes.length) {
        setTimeout(scheduleNext, staggerMs)
      }
    }

    if ('requestIdleCallback' in window) {
      (window as Window & { requestIdleCallback: (cb: () => void, opts?: { timeout: number }) => void }).requestIdleCallback(doPrefetch, { timeout: 3000 })
    } else {
      setTimeout(doPrefetch, 500 + index * staggerMs)
    }
  }

  scheduleNext()
}

/**
 * Get the set of already-prefetched route keys.
 */
export function getPrefetchedRoutes(): ReadonlySet<string> {
  return prefetched
}
