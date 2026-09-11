/**
 * Lightweight performance monitoring.
 *
 * Tracks:
 * - First visit vs returning visit
 * - Route transition times (click → page visible)
 * - API response times
 * - Core Web Vitals (FCP, LCP)
 *
 * In development, logs to console.
 * In production, stores in localStorage for later analysis.
 */

const STORAGE_KEY = 'perf_metrics'
const VISIT_KEY = 'perf_visit_count'

interface PerfMetric {
  name: string
  value: number
  unit: 'ms' | 'count'
  timestamp: number
  meta?: Record<string, string | number>
}

interface StoredMetrics {
  visits: number
  metrics: PerfMetric[]
}

function getStored(): StoredMetrics {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw) as StoredMetrics
  } catch { /* ignore */ }
  return { visits: 0, metrics: [] }
}

function store(metric: PerfMetric): void {
  const stored = getStored()
  stored.metrics.push(metric)
  // Keep last 200 metrics
  if (stored.metrics.length > 200) {
    stored.metrics = stored.metrics.slice(-200)
  }
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(stored))
  } catch { /* quota exceeded — ignore */ }
}

export function isFirstVisit(): boolean {
  const count = parseInt(localStorage.getItem(VISIT_KEY) || '0', 10)
  return count === 0
}

export function recordVisit(): void {
  const count = parseInt(localStorage.getItem(VISIT_KEY) || '0', 10)
  localStorage.setItem(VISIT_KEY, String(count + 1))
}

export function getVisitCount(): number {
  return parseInt(localStorage.getItem(VISIT_KEY) || '0', 10)
}

/**
 * Record a named timing metric.
 */
export function recordMetric(
  name: string,
  value: number,
  unit: 'ms' | 'count' = 'ms',
  meta?: Record<string, string | number>,
): void {
  const metric: PerfMetric = {
    name,
    value,
    unit,
    timestamp: Date.now(),
    meta,
  }

  store(metric)

  if (import.meta.env.DEV) {
    console.log(`[PERF] ${name}: ${value.toFixed(1)}${unit}`, meta || '')
  }
}

/**
 * Record a route transition time.
 * Call this when navigating between pages.
 */
export function recordRouteTransition(from: string, to: string, durationMs: number): void {
  recordMetric('route_transition', durationMs, 'ms', { from, to })
}

/**
 * Record an API call timing.
 */
export function recordApiTiming(method: string, url: string, durationMs: number, status: number): void {
  recordMetric('api_call', durationMs, 'ms', { method, url, status })
}

/**
 * Get all stored metrics.
 */
export function getMetrics(): PerfMetric[] {
  return getStored().metrics
}

/**
 * Get metrics summary.
 */
export function getMetricsSummary(): {
  totalVisits: number
  routeTransitions: { avg: number; p95: number; count: number }
  apiCalls: { avg: number; p95: number; count: number }
  firstVisitMetrics: PerfMetric[]
} {
  const stored = getStored()
  const routeTransitions = stored.metrics.filter(m => m.name === 'route_transition')
  const apiCalls = stored.metrics.filter(m => m.name === 'api_call')

  const avg = (arr: number[]) => arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0
  const p95 = (arr: number[]) => {
    if (!arr.length) return 0
    const sorted = [...arr].sort((a, b) => a - b)
    return sorted[Math.floor(sorted.length * 0.95)] ?? 0
  }

  const routeTimes = routeTransitions.map(m => m.value)
  const apiTimes = apiCalls.map(m => m.value)

  return {
    totalVisits: stored.visits,
    routeTransitions: { avg: avg(routeTimes), p95: p95(routeTimes), count: routeTimes.length },
    apiCalls: { avg: avg(apiTimes), p95: p95(apiTimes), count: apiTimes.length },
    firstVisitMetrics: stored.metrics.filter(m => m.meta?.visit === 1),
  }
}

/**
 * Clear all stored metrics.
 */
export function clearMetrics(): void {
  localStorage.removeItem(STORAGE_KEY)
}

/**
 * Initialize performance observers (call once on app start).
 */
export function initPerformanceObservers(): void {
  if (typeof PerformanceObserver === 'undefined') return

  // Observe FCP
  try {
    const fcpObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (entry.name === 'first-contentful-paint') {
          recordMetric('fcp', entry.startTime, 'ms', { visit: getVisitCount() })
        }
      }
    })
    fcpObserver.observe({ type: 'paint', buffered: true })
  } catch { /* Observer not supported */ }

  // Observe LCP
  try {
    const lcpObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries()
      const lastEntry = entries[entries.length - 1]
      if (lastEntry) {
        recordMetric('lcp', lastEntry.startTime, 'ms', { visit: getVisitCount() })
      }
    })
    lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true })
  } catch { /* Observer not supported */ }

  // Observe long tasks
  try {
    const longTaskObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (entry.duration > 50) {
          recordMetric('long_task', entry.duration, 'ms', {
            visit: getVisitCount(),
            start: Math.round(entry.startTime),
          })
        }
      }
    })
    longTaskObserver.observe({ type: 'longtask', buffered: false })
  } catch { /* Observer not supported */ }
}
