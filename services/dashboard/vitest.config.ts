import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    environmentOptions: {
      nuxt: {
        domEnvironment: 'happy-dom'
      }
    },
    include: ['test/**/*.{test,spec}.ts'],
    setupFiles: ['test/setup/global.ts'],
    // Building the Nuxt environment for a suite takes well over the 10 s default on a loaded CI
    // container, where these run next to the PHP suites and the Docker builds.
    hookTimeout: 120_000,
    testTimeout: 30_000,
    coverage: {
      provider: 'v8',
      include: ['app/**/*.{ts,vue}'],
      exclude: ['app/types/**'],
      reporter: ['text', 'html', 'lcov'],
      thresholds: {
        'app/composables/**': { lines: 85, functions: 70, branches: 70, statements: 85 },
        'app/utils/**': { lines: 85, functions: 70, branches: 70, statements: 85 },
        'app/components/**': { lines: 70, functions: 50, branches: 60, statements: 70 }
      }
    }
  }
})
