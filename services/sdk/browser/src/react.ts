'use client';
import {
  createContext,
  createElement,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
  type ReactElement,
  type ReactNode,
} from 'react';
import { load } from './client.js';
import type { AnalyticsClient, AnalyticsConsent, ConsentState, LoadOptions } from './types.js';

export type { AnalyticsClient, ConsentState, LoadOptions } from './types.js';

const AnalyticsContext = createContext<AnalyticsClient | null>(null);

/** Either the load options (`serviceUrl`, `publicKey`, …) or a client from `load()`. */
export type AnalyticsProviderProps = { children?: ReactNode } & (
  | (LoadOptions & { client?: undefined })
  | { client: AnalyticsClient }
);

/**
 * Loads the tracker once and makes the client available to `useAnalytics()` and `useConsent()`.
 * Safe in server rendering: on the server the client does nothing and consent is `unknown`.
 */
export function AnalyticsProvider(props: AnalyticsProviderProps): ReactElement {
  const { children, ...rest } = props;
  // load() is idempotent, so running it again (strict mode, remounts) returns the same client.
  const [client] = useState<AnalyticsClient>(() =>
    'client' in rest && rest.client ? rest.client : load(rest as LoadOptions),
  );
  return createElement(AnalyticsContext.Provider, { value: client }, children);
}

/** The client of the nearest `<AnalyticsProvider>`. */
export function useAnalytics(): AnalyticsClient {
  const client = useContext(AnalyticsContext);
  if (!client) throw new Error('useAnalytics() and useConsent() need an <AnalyticsProvider> above them');
  return client;
}

export type UseConsent = ConsentState & Pick<AnalyticsConsent, 'open' | 'set' | 'forget'>;

const SERVER_STATE: ConsentState = Object.freeze({ status: 'unknown', version: null, decidedAt: null });

const same = (a: ConsentState, b: ConsentState): boolean =>
  a.status === b.status && a.version === b.version && a.decidedAt === b.decidedAt;

/**
 * The visitor's consent, re-rendering on every change (`consent.onChange`) and once the tracker has
 * loaded. It is `unknown` during server rendering and hydration, so the markup always matches.
 */
export function useConsent(): UseConsent {
  const client = useAnalytics();
  const last = useRef<ConsentState>(SERVER_STATE);

  const subscribe = useCallback(
    (notify: () => void) => {
      let active = true;
      const off = client.consent.onChange(notify);
      void client.ready.then(() => {
        if (active) notify();
      });
      return () => {
        active = false;
        off();
      };
    },
    [client],
  );
  const getSnapshot = (): ConsentState => {
    const now = client.consent.get();
    if (same(now, last.current)) return last.current;
    return (last.current = now);
  };
  const state = useSyncExternalStore(subscribe, getSnapshot, () => SERVER_STATE);

  return useMemo(
    () => ({
      status: state.status,
      version: state.version,
      decidedAt: state.decidedAt,
      open: client.consent.open,
      set: client.consent.set,
      forget: client.consent.forget,
    }),
    [state, client],
  );
}
