import type { ConsentState } from './generated/tracker.js';
import type { AnalyticsApi, AnalyticsClient, AnalyticsStub, LoadOptions, TrackerApi } from './types.js';

const PUBLIC_KEY = /^pk_[A-Za-z0-9]{21}$/;
const IDENTIFIER = /^[A-Za-z_$][A-Za-z0-9_$]*$/;
const PROXY_PATH = /^[A-Za-z0-9/_-]*$/;
/** Where the tracker installs itself when the configured global is taken by something else. */
const FALLBACK = '__analytics';

type Slots = Record<string, unknown>;
type Fn = (...args: unknown[]) => unknown;

/** One client per global name in this page (a second `load()` returns the first client). */
const clients = new Map<string, AnalyticsClient>();

const unknownState = (): ConsentState => ({ status: 'unknown', version: null, decidedAt: null });

const isTracker = (v: unknown): v is TrackerApi => !!v && typeof v == 'object' && !!(v as { __an?: unknown }).__an;
const isStub = (v: unknown): v is AnalyticsStub => !!v && typeof v == 'object' && Array.isArray((v as { q?: unknown }).q);

function check(options: LoadOptions): string {
  if (!options || !PUBLIC_KEY.test(options.publicKey)) {
    throw new TypeError('analytics: publicKey must be "pk_" followed by 21 letters or digits');
  }
  const name = options.globalName ?? 'analytics';
  if (!IDENTIFIER.test(name)) throw new TypeError(`analytics: globalName "${name}" is not a JavaScript identifier`);
  return name;
}

/**
 * The URL of the tracker script for these options: `{serviceUrl}/t/{publicKey}.js`, or
 * `{proxyPath}{publicKey}.js` on the site's own origin when a proxy path is set.
 */
export function scriptUrl(options: LoadOptions): string {
  check(options);
  if (options.proxyPath != null && options.proxyPath !== '') {
    const trimmed = options.proxyPath.trim().replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
    if (!trimmed || !PROXY_PATH.test(trimmed)) {
      throw new TypeError('analytics: proxyPath must be a path on this origin, like "/stats/"');
    }
    return `/${trimmed}/${options.publicKey}.js`;
  }
  let url: URL;
  try {
    url = new URL(options.serviceUrl ?? '');
  } catch {
    throw new TypeError('analytics: serviceUrl must be an absolute http(s) URL (or set proxyPath)');
  }
  if (url.protocol != 'https:' && url.protocol != 'http:') {
    throw new TypeError('analytics: serviceUrl must be an absolute http(s) URL (or set proxyPath)');
  }
  return `${url.origin}${url.pathname.replace(/\/+$/, '')}/t/${options.publicKey}.js`;
}

/** A queue stub, like the snippet's, with every method that returns nothing. */
function createStub(): AnalyticsStub {
  const q: unknown[][] = [];
  const queue =
    (method: string) =>
    (...args: unknown[]): void => {
      q.push([method, ...args]);
    };
  return {
    q,
    track: queue('track'),
    pageview: queue('pageview'),
    setContent: queue('setContent'),
    consent: {
      open: queue('consent.open'),
      set: queue('consent.set'),
      onChange: queue('consent.onChange') as unknown as AnalyticsApi['consent']['onChange'],
      forget: queue('consent.forget'),
    },
  };
}

/** A client that does nothing: on the server, or wherever there is no `window`. */
function inertClient(globalName: string): AnalyticsClient {
  const noop = (): void => undefined;
  return {
    globalName,
    ready: Promise.resolve(false),
    track: noop,
    pageview: noop,
    setContent: noop,
    getVisitorId: () => null,
    consent: { open: noop, get: unknownState, set: noop, onChange: () => noop, forget: noop },
  };
}

/**
 * Loads the tracker for a site and returns a typed client.
 *
 * Inserts the queue stub and `<script defer src="…/t/{publicKey}.js">` exactly once per global name,
 * even when called many times or when the page already has the snippet. Without `window` (server
 * rendering) it inserts nothing and returns a client whose calls do nothing.
 */
export function load(options: LoadOptions): AnalyticsClient {
  const configured = check(options);
  const src = scriptUrl(options);
  if (typeof window == 'undefined' || typeof document == 'undefined') return inertClient(configured);

  const existing = clients.get(configured);
  if (existing) return existing;

  const w = window as unknown as Slots;
  // The tracker's own rule (services/tracker/src/api.ts `install`): a slot holding anything other
  // than a queue stub or the tracker makes it install itself at `__analytics` instead.
  const current = w[configured];
  const name = current && !isStub(current) && !isTracker(current) ? FALLBACK : configured;

  const live = (): TrackerApi | null => {
    const v = w[name];
    return isTracker(v) ? v : null;
  };

  const call = (method: string, args: unknown[]): unknown => {
    const tracker = live();
    if (tracker) {
      const f = method.split('.').reduce<unknown>((o, k) => (o ? (o as Slots)[k] : undefined), tracker);
      return typeof f == 'function' ? (f as Fn)(...args) : undefined;
    }
    const slot = w[name];
    const stub = isStub(slot) ? slot : (w[name] = createStub());
    stub.q.push([method, ...args]);
    return undefined;
  };

  // Consent listeners live here, so unsubscribing works even before the tracker has loaded; the
  // tracker gets one bridge listener, queued like any other call.
  const listeners = new Set<(s: ConsentState) => void>();
  call('consent.onChange', [
    (s: ConsentState) => {
      for (const f of [...listeners]) f(s);
    },
  ]);

  const ready = new Promise<boolean>((resolve) => {
    if (live()) return resolve(true);
    const settle = (): void => {
      const doc = document as Document & { prerendering?: boolean };
      // A prerendered page starts the tracker only when it is shown.
      if (!live() && doc.prerendering) {
        doc.addEventListener('prerenderingchange', () => resolve(!!live()), { once: true });
      } else resolve(!!live());
    };
    const absolute = new URL(src, location.href).href;
    let script = Array.from(document.scripts).find((s) => s.src === absolute);
    if (!script) {
      script = document.createElement('script');
      script.defer = true;
      script.src = src;
      if (options.nonce) script.nonce = options.nonce;
      script.setAttribute('data-analytics-sdk', '');
      script.addEventListener('load', settle, { once: true });
      script.addEventListener('error', () => resolve(false), { once: true });
      (document.head || document.documentElement).appendChild(script);
    } else {
      // The page already has the snippet (or another copy of this SDK inserted it): wait for its
      // events, or for the page load. After the page load, a script with a resource timing entry
      // has finished; one without is still on its way and its own events will come.
      script.addEventListener('load', settle, { once: true });
      script.addEventListener('error', () => resolve(false), { once: true });
      if (document.readyState != 'complete') window.addEventListener('load', settle, { once: true });
      else if (typeof performance != 'undefined' && performance.getEntriesByName?.(absolute).length) settle();
    }
  });

  const client: AnalyticsClient = {
    globalName: name,
    ready,
    track: (...a) => void call('track', a),
    pageview: (...a) => void call('pageview', a),
    setContent: (...a) => void call('setContent', a),
    getVisitorId: () => (live() ? ((call('getVisitorId', []) as string | null) ?? null) : null),
    consent: {
      open: () => void call('consent.open', []),
      get: () => (live() ? (call('consent.get', []) as ConsentState) : unknownState()),
      set: (...a) => void call('consent.set', a),
      onChange: (f) => {
        listeners.add(f);
        return () => {
          listeners.delete(f);
        };
      },
      forget: () => void call('consent.forget', []),
    },
  };
  clients.set(configured, client);
  return client;
}

/** Forgets the clients created so far (tests only). @internal */
export function __reset(): void {
  clients.clear();
}
