/**
 * PWA / offline tenant isolation guardrails.
 *
 * The service worker is the one component that can persist API responses to
 * disk outside the app's control. If it ever runtime-caches an `/api/`
 * response, a response containing tenant A's members would be served to
 * tenant B from the HTTP cache on a shared device — a cross-tenant disclosure
 * that no amount of client-side cache-key scoping prevents, because the
 * service worker intercepts before the app's own caching logic runs.
 *
 * The current configuration is safe:
 *   - `globPatterns` precaches only static build assets, never API paths;
 *   - the single `runtimeCaching` rule covers Google Fonts only;
 *   - `navigateFallbackDenylist: [/^\/api\//]` keeps SPA navigation fallback
 *     from rewriting API requests to index.html.
 *
 * These assertions read the real config so a future edit that adds API
 * caching fails here instead of shipping.
 */
import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import path from 'node:path'

const configPath = path.resolve(__dirname, '../../vite.config.ts')
const config = readFileSync(configPath, 'utf8')

describe('service worker API isolation', () => {
  it('never runtime-caches an /api/ URL', () => {
    // Isolate the workbox block so the check cannot be satisfied by an
    // unrelated /api/ mention elsewhere in the file (server.proxy, define...).
    const workbox = config.slice(config.indexOf('workbox:'))

    const runtimeCachingUrls = [...workbox.matchAll(/urlPattern:\s*([^,\n]+)/g)].map((m) => m[1])

    expect(runtimeCachingUrls.length).toBeGreaterThan(0)

    for (const pattern of runtimeCachingUrls) {
      // `noUncheckedIndexedAccess` widens the capture group, and an
      // undefined pattern would silently pass the test below.
      expect(pattern).toBeTypeOf('string')

      expect(
        /\/api\//.test(pattern ?? ''),
        `runtimeCaching rule "${pattern}" would cache API responses in the service worker. `
          + 'API responses are tenant-scoped and must never be persisted to disk.',
      ).toBe(false)
    }
  })

  it('excludes API paths from the SPA navigation fallback', () => {
    expect(config).toMatch(/navigateFallbackDenylist:\s*\[\s*\/\^\\\/api\\\/\//)
  })

  it('precaches only static assets, never API paths', () => {
    const globPatterns = config.match(/globPatterns:\s*\[([^\]]*)\]/)?.[1] ?? ''

    expect(globPatterns).not.toBe('')
    // A JSON glob would sweep API payloads into the precache manifest.
    expect(globPatterns).not.toMatch(/\.json\b/)
    expect(globPatterns).not.toMatch(/api/i)
  })

  it('does not put the service-role Supabase key or any token in the config', () => {
    // Cheap guard against a credential being pasted into build config, which
    // would be embedded in the publicly served bundle.
    expect(config).not.toMatch(/service_role/i)
    expect(config).not.toMatch(/eyJ[A-Za-z0-9_-]{20,}/) // JWT-looking literal
  })
})

/**
 * The GENERATED service worker, not the config.
 *
 * The tests above read `vite.config.ts` as text. That is a useful check on
 * intent, but it cannot prove what workbox actually emitted: a plugin default,
 * an injected manifest, or a hand-edited `sw.js` in `public/` would all pass
 * the source assertions while caching authenticated API responses to disk.
 *
 * This is the artifact that ships, so it is the one that matters. It is
 * skipped when `dist/` is absent so `npm test` still works before a build;
 * CI runs `npm run build` before `npm test`, so there it always executes.
 */
describe('generated sw.js', () => {
  const swPath = path.resolve(__dirname, '../../dist/sw.js')
  const built = existsSync(swPath)
  const sw = built ? readFileSync(swPath, 'utf8') : ''

  it.skipIf(!built)('exists after a production build', () => {
    expect(sw.length).toBeGreaterThan(0)
  })

  it.skipIf(!built)('registers no route that can capture an API request', () => {
    // A generous window: the navigation route's denylist sits a few hundred
    // characters past `registerRoute(` in the minified output.
    const routes = [...sw.matchAll(/registerRoute\(([\s\S]{0,400}?)\)\)/g)].map((m) => m[1] ?? '')

    expect(routes.length).toBeGreaterThan(0)

    for (const route of routes) {
      // A navigation-fallback route is fine ONLY if it denylists /api/;
      // anything else must not mention the API path at all.
      if (route.includes('NavigationRoute')) {
        expect(route).toMatch(/\/api/)
      } else {
        expect(route).not.toMatch(/\/api/)
      }
    }
  })

  it.skipIf(!built)('uses no runtime caching strategy beyond the approved font rule', () => {
    const strategies = [...sw.matchAll(/(StaleWhileRevalidate|CacheFirst|CacheOnly|NetworkFirst|NetworkOnly)/g)]
      .map((m) => m[1])

    // Exactly one: the Google Fonts CacheFirst rule. A second strategy is how
    // an API response starts being persisted.
    expect(strategies).toEqual(['CacheFirst'])
  })

  it.skipIf(!built)('mentions the API path only inside the navigation denylist', () => {
    // The emitted denylist is the minified regex literal `/^\/api\//`, so the
    // source text is "/api" followed by an escaped slash — matching "/api/" as
    // a plain string would find nothing and pass vacuously.
    const occurrences = [...sw.matchAll(/\/api/g)].length

    // Exactly one: the denylist entry, and nothing else.
    expect(occurrences).toBe(1)
    expect(sw).toMatch(/denylist:\s*\[\s*\/\^\\\/api\\/)
  })
})

