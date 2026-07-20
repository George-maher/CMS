import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      includeAssets: [
        'favicon.svg',
        'icons/*.svg',
        'icons/*.png',
        'offline.html',
      ],
      manifest: {
        name: 'Church Management System',
        short_name: 'Church Manager',
        description: 'Manage your church community with attendance tracking, events, and member management.',
        theme_color: '#0f1d3d',
        background_color: '#0f1d3d',
        display: 'standalone',
        display_override: ['window-controls-overlay', 'standalone'],
        orientation: 'any',
        scope: '/',
        start_url: '/',
        lang: 'en',
        dir: 'ltr',
        categories: ['productivity', 'education', 'utilities'],
        prefer_related_applications: false,
        shortcuts: [
          {
            name: 'Dashboard',
            short_name: 'Dashboard',
            description: 'View your dashboard',
            url: '/admin',
            icons: [{ src: '/icons/icon-96.svg', sizes: '96x96' }],
          },
          {
            name: 'Attendance',
            short_name: 'Attendance',
            description: 'Mark attendance',
            url: '/servant/attendance',
            icons: [{ src: '/icons/icon-96.svg', sizes: '96x96' }],
          },
          {
            name: 'My QR Code',
            short_name: 'My QR',
            description: 'View your QR code',
            url: '/member/qr',
            icons: [{ src: '/icons/icon-96.svg', sizes: '96x96' }],
          },
        ],
        icons: [
          { src: '/icons/icon-72.svg', sizes: '72x72', type: 'image/svg+xml' },
          { src: '/icons/icon-96.svg', sizes: '96x96', type: 'image/svg+xml' },
          { src: '/icons/icon-128.svg', sizes: '128x128', type: 'image/svg+xml' },
          { src: '/icons/icon-144.svg', sizes: '144x144', type: 'image/svg+xml' },
          { src: '/icons/icon-152.svg', sizes: '152x152', type: 'image/svg+xml' },
          { src: '/icons/icon-192.svg', sizes: '192x192', type: 'image/svg+xml' },
          { src: '/icons/icon-384.svg', sizes: '384x384', type: 'image/svg+xml' },
          { src: '/icons/icon-512.svg', sizes: '512x512', type: 'image/svg+xml', purpose: 'any' },
          { src: '/icons/icon-512-maskable.svg', sizes: '512x512', type: 'image/svg+xml', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,eot,ico}'],
        globIgnores: ['**/node_modules/**', 'sw.js', 'workbox-*'],
        navigateFallback: '/offline.html',
        navigateFallbackDenylist: [/\/api\/.*/],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-fonts-cache',
              expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'gstatic-fonts-cache',
              expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: /\.(?:png|jpg|jpeg|gif|svg|webp|ico)$/i,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'images-cache',
              expiration: { maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 * 30 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: /^\/api\/.*/i,
            handler: 'NetworkFirst',
            method: 'GET',
            options: {
              cacheName: 'api-cache',
              expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 },
              networkTimeoutSeconds: 5,
              cacheableResponse: { statuses: [0, 200, 404] },
            },
          },
        ],
      },
      registerType: 'prompt',
      devOptions: {
        enabled: true,
        type: 'module',
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
