import { afterEach } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'

// Unmount components after every test so no stale watcher runs against cleared Nuxt state.
enableAutoUnmount(afterEach)
