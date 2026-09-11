import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'node:path'

/**
 * Vite plugin that injects <link rel="modulepreload"> for critical initial
 * chunks (vendor, i18n, ui) into index.html. This eliminates the waterfall
 * where the browser must first parse the entry script before discovering
 * these large dependency chunks.
 */
function modulePreloadPlugin(): Plugin {
  return {
    name: 'module-preload-inject',
    apply: 'build',
    transformIndexHtml(html, { bundle }) {
      if (!bundle) return html

      const manifest = bundle['index.html'] as { imports?: string[] } | undefined
      if (!manifest?.imports) return html

      const links: string[] = []
      const seen = new Set<string>()

      function collect(imports: string[]) {
        for (const imp of imports) {
          if (seen.has(imp)) continue
          seen.add(imp)
          const chunk = bundle[imp]
          if (chunk?.type === 'chunk') {
            links.push(`<link rel="modulepreload" as="script" crossorigin href="/${chunk.fileName}">`)
            if (chunk.imports) collect(chunk.imports)
          }
        }
      }

      collect(manifest.imports)

      if (links.length === 0) return html

      return html.replace(
        '<!-- Module preloads -->',
        links.join('\n    '),
      )
    },
  }
}

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    modulePreloadPlugin(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons.svg', 'icons/*.png'],
      manifest: {
        id: '/',
        name: 'Church Manager',
        short_name: 'Church Mgr',
        description: 'Church Management System — Manage your church community with attendance tracking, events, and member management.',
        theme_color: '#d4af37',
        background_color: '#0f172a',
        display: 'standalone',
        display_override: ['window-controls-overlay', 'standalone', 'minimal-ui'],
        orientation: 'any',
        scope: '/',
        start_url: '/',
        lang: 'en',
        dir: 'ltr',
        categories: ['productivity', 'education', 'utilities'],
        icons: [
          { src: '/icons/icon-192x192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/icons/icon-192x192-maskable.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
          { src: '/icons/icon-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: '/icons/icon-512x512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
          { src: '/icons/icon.svg', sizes: '192x192', type: 'image/svg+xml', purpose: 'any' },
          { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
        ],
        screenshots: [
          { src: '/screenshots/mobile.png', sizes: '390x844', type: 'image/png', form_factor: 'narrow', label: 'Mobile view' },
          { src: '/screenshots/desktop.png', sizes: '1280x800', type: 'image/png', form_factor: 'wide', label: 'Desktop view' },
        ],
        shortcuts: [
          { name: 'Dashboard', url: '/admin', icons: [{ src: '/favicon.svg', sizes: 'any' }] },
          { name: 'Attendance', url: '/servant/attendance', icons: [{ src: '/favicon.svg', sizes: 'any' }] },
          { name: 'Scan QR', url: '/servant/scan', icons: [{ src: '/favicon.svg', sizes: 'any' }] },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,woff2,svg,png,jpg,jpeg,gif,ico}'],
        globIgnores: ['**/workbox-*.js', '**/manifest.webmanifest'],
        skipWaiting: true,
        clientsClaim: true,
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [
          {
            urlPattern: /^https?:\/\/.*\/api\/v1\/(stages|classes|members|attendance-contexts|daily-verse|users\/members|users\/servants|users\/my-class-servants).*/,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'api-slow-cache',
              expiration: { maxEntries: 50, maxAgeSeconds: 60 * 60 * 24 },
            },
          },
          {
            urlPattern: /^https?:\/\/.*\/api\/v1\/(dashboard|leaderboard|events|attendances\/today|notifications\/unread-count).*/,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-fast-cache',
              expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 },
              networkTimeoutSeconds: 3,
            },
          },
          {
            urlPattern: /^https?:\/\/.*\/api\/v1\/.*/,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-default-cache',
              expiration: { maxEntries: 50, maxAgeSeconds: 60 * 30 },
              networkTimeoutSeconds: 5,
            },
          },
          {
            urlPattern: /^https?:\/\/fonts\.(googleapis|gstatic)\.com\/.*/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-fonts-cache',
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 },
            },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY || 'http://nginx',
        changeOrigin: true,
      },
    },
  },
  define: {
    __APP_ENV__: JSON.stringify(process.env.APP_ENV),
  },
  build: {
    target: 'es2020',
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks(id: string) {
          if (id.includes('node_modules/react-dom') || id.includes('node_modules/react/') || id.includes('node_modules/react-router')) return 'vendor'
          if (id.includes('node_modules/lucide-react') || id.includes('node_modules/react-hot-toast')) return 'ui'
          if (id.includes('node_modules/i18next') || id.includes('node_modules/react-i18next')) return 'i18n'
          if (id.includes('node_modules/html5-qrcode') || id.includes('node_modules/qrcode')) return 'qr'
        },
      },
    },
  },
})
