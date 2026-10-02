import { defineConfig } from 'vitest/config'
import path from 'node:path'

/**
 * Test config, kept separate from vite.config.ts on purpose.
 *
 * The production config owns the PWA/service-worker build. Merging test
 * settings into it would risk changing the shipped bundle while adding tests,
 * so the two are deliberately independent and share only the `@` alias.
 */
export default defineConfig({
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  define: {
    __APP_ENV__: JSON.stringify('test'),
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    // The offline queue exercises retry backoff, which is timer-driven.
    testTimeout: 20_000,
  },
})
