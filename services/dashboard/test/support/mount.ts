import { defineComponent, h } from 'vue'
import type { Component } from 'vue'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { UApp } from '#components'

/**
 * Mounts a component inside `UApp`, which provides the toast, tooltip and
 * overlay contexts that Nuxt UI components expect.
 */
export function mountInApp(component: Component, options: { props?: Record<string, unknown>, route?: string, attach?: boolean } = {}) {
  const Wrapper = defineComponent({
    setup() {
      return () => h(UApp, () => h(component, options.props))
    }
  })
  return mountSuspended(Wrapper, {
    ...(options.route ? { route: options.route } : {}),
    // Attached mounts are needed when a test asserts focus.
    ...(options.attach ? { attachTo: document.body } : {})
  })
}

/** Resets only the state this app owns, leaving Nuxt plugin state (colour mode, i18n) intact. */
export function resetAppState() {
  for (const key of ['auth:user', 'auth:csrf', 'auth:loaded', 'auth:config', 'sites:list', 'sites:loaded', 'sites:loading']) {
    useState(key).value = null
  }
}
