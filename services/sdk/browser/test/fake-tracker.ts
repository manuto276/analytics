import type { ConsentState, ConsentStatus, TrackerApi } from '../src/generated/tracker';

type Slots = Record<string, unknown>;
type Fn = (...a: unknown[]) => unknown;

export interface FakeTracker {
  api: TrackerApi;
  /** Every call the tracker received, `[method, ...args]`, in order (replayed ones included). */
  calls: unknown[][];
  /** Where it installed itself, or null when a tracker was already there. */
  installedAt: string | null;
}

export interface FakeOptions {
  /** `cfg.g`, the global name configured on the service. */
  g?: string;
  status?: ConsentStatus;
  visitorId?: string | null;
}

/**
 * A stand-in for the tracker bundle, with the real queue contract of services/tracker/src/api.ts:
 * `install()` replaces a `{q: [...]}` stub at `window[cfg.g]` (falling back to `__analytics` when the
 * name holds something else), then replays the queue, resolving dotted names like `consent.open`
 * against the API and skipping anything that is not a function.
 */
export function runFakeTracker(o: FakeOptions = {}): FakeTracker {
  const w = window as unknown as Slots;
  const calls: unknown[][] = [];
  const listeners: ((s: ConsentState) => void)[] = [];
  let state: ConsentState = { status: o.status ?? 'unknown', version: o.status && o.status != 'unknown' ? 3 : null, decidedAt: null };
  const record =
    (m: string) =>
    (...a: unknown[]): void => {
      calls.push([m, ...a]);
    };
  const decide = (s: ConsentStatus): void => {
    state = { status: s, version: 3, decidedAt: 1_700_000_000_000 };
    for (const f of listeners) f({ ...state });
  };
  const api: TrackerApi = {
    __an: 1,
    track: record('track'),
    pageview: record('pageview'),
    setContent: record('setContent'),
    getVisitorId: () => (state.status == 'accepted' ? (o.visitorId ?? 'AAAAAAAAAAAAAAAAAAAAAA') : null),
    consent: {
      open: record('consent.open'),
      get: () => ({ ...state }),
      set: (s) => {
        calls.push(['consent.set', s]);
        decide(s);
      },
      onChange: (f) => {
        calls.push(['consent.onChange']);
        listeners.push(f);
        return () => {
          listeners.splice(listeners.indexOf(f), 1);
        };
      },
      forget: () => {
        calls.push(['consent.forget']);
        decide('rejected');
      },
    },
  };

  // services/tracker/src/api.ts install(), verbatim in behaviour.
  let g = o.g || 'analytics';
  let cur = w[g] as { q?: unknown[][]; __an?: number } | undefined;
  if (cur && !Array.isArray(cur.q)) {
    if (cur.__an) return { api, calls, installedAt: null };
    cur = w[(g = '__analytics')] as typeof cur;
    if (cur && cur.__an) return { api, calls, installedAt: null };
  }
  w[g] = api;
  for (const [m, ...a] of (cur && cur.q) || []) {
    const f = String(m)
      .split('.')
      .reduce<unknown>((x, k) => x && (x as Slots)[k], api);
    if (typeof f == 'function') (f as Fn)(...a);
  }
  return { api, calls, installedAt: g };
}

/**
 * Holds back the `load` event of inserted scripts (happy-dom fires it on insertion, see
 * vitest.config.ts), so a test decides when "the network" answers and what the script does.
 */
export function holdScripts() {
  const pending: HTMLScriptElement[] = [];
  const released = new WeakSet<Event>();
  const hold = (e: Event): void => {
    if (e.target instanceof HTMLScriptElement && !released.has(e)) {
      e.stopImmediatePropagation();
      pending.push(e.target);
    }
  };
  document.addEventListener('load', hold, true);
  const next = (): HTMLScriptElement => {
    const s = pending.shift();
    if (!s) throw new Error('no script is loading');
    return s;
  };
  return {
    pending,
    /** The script "downloads" and runs `body` (the tracker), then fires `load`. */
    respond(body: () => void = () => undefined): void {
      const s = next();
      body();
      const ev = new Event('load');
      released.add(ev);
      s.dispatchEvent(ev);
    },
    /** The request fails (blocked, offline). */
    fail(): void {
      next().dispatchEvent(new Event('error'));
    },
    release(): void {
      document.removeEventListener('load', hold, true);
    },
  };
}
