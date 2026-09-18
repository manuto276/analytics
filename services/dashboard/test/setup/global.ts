import { afterAll, afterEach } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'

// Unmount components after every test so no stale watcher runs against cleared Nuxt state.
enableAutoUnmount(afterEach)

// Nuxt's router resolves route targets lazily, so a link rendered by a test can still have a
// dynamic import in flight when vitest tears the environment down. That import then rejects with
// EnvironmentTeardownError and fails the run although every test passed. Yield a few macrotasks at
// the end of each file so those imports land first.
afterAll(async () => {
  for (let i = 0; i < 12; i++) await new Promise(resolve => setTimeout(resolve, 30))
})
