import { cfg, w } from './config';
import { href, last, pv, setContent, track } from './collector';
import { forget, get, level, onChange, open, set, visitorId } from './consent';
import { fill } from './dom';

type Fn = (...a: unknown[]) => unknown;
interface Stub {
  q?: unknown[][];
  __an?: number;
}

export const pageview = (u: string, r: string | undefined): void => {
  pv(u, r, level());
  fill();
};

export const api = {
  __an: 1,
  track: (name: string, props?: Record<string, unknown>): void => track(name, props, level()),
  pageview: (o?: { url?: string }): void =>
    pageview(o && o.url ? new URL(o.url, location.href).href : href(), last),
  setContent,
  getVisitorId: visitorId,
  consent: { open, get, set, onChange, forget },
};

/**
 * Expose the API as `window[cfg.g]` (default `analytics`). A queue stub (`{q: [...]}`) is replaced;
 * anything else occupying the name makes the tracker fall back to `window.__analytics`.
 * Returns the replay function for the stub queue, or null when a tracker is already installed.
 */
export const install = (): (() => void) | null => {
  let g = cfg.g || 'analytics';
  let cur = w[g] as Stub | undefined;
  if (cur && !Array.isArray(cur.q)) {
    if (cur.__an) return null;
    cur = w[(g = '__analytics')] as Stub | undefined;
    if (cur && cur.__an) return null;
  }
  w[g] = api;
  return () => {
    for (const [m, ...a] of (cur && cur.q) || []) {
      const f = String(m)
        .split('.')
        .reduce<unknown>((o, k) => o && (o as Record<string, unknown>)[k], api);
      if (typeof f == 'function') (f as Fn)(...a);
    }
  };
};
