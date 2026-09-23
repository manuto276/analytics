import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'happy-dom',
    environmentOptions: {
      // No network and no script evaluation: a script element "loads" as soon as it is inserted,
      // and test/fake-tracker.ts decides what that load does.
      happyDOM: { settings: { disableJavaScriptFileLoading: true, handleDisabledFileLoadingAsSuccess: true } },
    },
    include: ['test/**/*.test.{ts,tsx}'],
    setupFiles: ['test/setup.ts'],
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/generated/**', 'src/types.ts'],
      reporter: ['text', 'text-summary', 'lcov'],
      thresholds: { lines: 95, branches: 90 },
    },
  },
});
