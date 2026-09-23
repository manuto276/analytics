import {
  computed,
  getCurrentInstance,
  getCurrentScope,
  inject,
  onMounted,
  onScopeDispose,
  readonly,
  shallowRef,
  type App,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue';
import { load } from './client.js';
import type { AnalyticsClient, AnalyticsConsent, ConsentState, ConsentStatus, LoadOptions } from './types.js';

export type { AnalyticsClient, ConsentState, LoadOptions } from './types.js';

/** The key the plugin provides the client under. */
export const analyticsKey: InjectionKey<AnalyticsClient> = Symbol('analytics');

export interface AnalyticsPlugin {
  /** The client, also available as `useAnalytics()` and `this.$analytics`. */
  client: AnalyticsClient;
  install(app: App): void;
}

/**
 * The Vue plugin: `app.use(createAnalytics({ serviceUrl, publicKey }))`. Loads the tracker once;
 * on the server (Nuxt SSR) the client does nothing.
 */
export function createAnalytics(options: LoadOptions | AnalyticsClient): AnalyticsPlugin {
  const client = 'consent' in options ? options : load(options);
  return {
    client,
    install(app: App) {
      app.provide(analyticsKey, client);
      app.config.globalProperties.$analytics = client;
    },
  };
}

/** The client provided by the plugin. */
export function useAnalytics(): AnalyticsClient {
  const client = inject(analyticsKey, null);
  if (!client) throw new Error('useAnalytics() and useConsent() need app.use(createAnalytics(...))');
  return client;
}

export interface UseConsent extends Pick<AnalyticsConsent, 'open' | 'set' | 'forget'> {
  /** The whole state, updated on every change and once the tracker has loaded. */
  state: Readonly<Ref<ConsentState>>;
  status: ComputedRef<ConsentStatus>;
}

/**
 * Reactive consent state. Inside a component it starts as `unknown` and is read on mount, so server
 * and client render the same markup; it follows `consent.onChange` until the scope is disposed.
 */
export function useConsent(client: AnalyticsClient = useAnalytics()): UseConsent {
  const inComponent = !!getCurrentInstance();
  const state = shallowRef<ConsentState>(
    inComponent ? { status: 'unknown', version: null, decidedAt: null } : client.consent.get(),
  );
  const sync = (): void => {
    const now = client.consent.get();
    const was = state.value;
    if (now.status !== was.status || now.version !== was.version || now.decidedAt !== was.decidedAt) {
      state.value = now;
    }
  };
  let active = true;
  const off = client.consent.onChange(sync);
  void client.ready.then(() => {
    if (active) sync();
  });
  if (inComponent) onMounted(sync);
  if (getCurrentScope()) {
    onScopeDispose(() => {
      active = false;
      off();
    });
  }
  return {
    state: readonly(state),
    status: computed(() => state.value.status),
    open: client.consent.open,
    set: client.consent.set,
    forget: client.consent.forget,
  };
}

declare module 'vue' {
  interface ComponentCustomProperties {
    /** The analytics client (`createAnalytics()` plugin). */
    $analytics: AnalyticsClient;
  }
}
