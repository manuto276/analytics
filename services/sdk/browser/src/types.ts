import type { ConsentState, ConsentStatus, TrackerApi } from './generated/tracker.js';

export type { ConsentState, ConsentStatus, TrackerApi };

/** Where the tracker is loaded from: the analytics service, or a first-party proxy on the site. */
export type LoadOptions = LoadOptionsBase &
  (
    | {
        /** Base URL of the analytics service, e.g. `https://stats.example.net`. */
        serviceUrl: string;
        /**
         * Path of a first-party proxy on the site's own origin that forwards to the service's `/t/`,
         * e.g. `/stats/` (the script becomes `/stats/{publicKey}.js`). Takes precedence over `serviceUrl`.
         */
        proxyPath?: string;
      }
    | { serviceUrl?: string; proxyPath: string }
  );

interface LoadOptionsBase {
  /** The site's public key, `pk_` followed by 21 letters and digits. */
  publicKey: string;
  /**
   * The JavaScript global the tracker installs itself as. It must match the global name configured
   * for the site on the service (default `analytics`).
   */
  globalName?: string;
  /** CSP nonce for the inserted `<script>`, when the page's policy uses nonces. */
  nonce?: string;
}

/** The API of the tracker, minus its internal marker. */
export type AnalyticsApi = Omit<TrackerApi, '__an'>;

/**
 * The typed client returned by `load()`. Every method is safe to call at any time: before the
 * tracker has loaded, calls are queued and replayed by the tracker; on the server they do nothing.
 */
export interface AnalyticsClient {
  /** Custom event. `name` must match `^[a-z0-9_:.-]{1,64}$`; up to 10 scalar props. */
  track: AnalyticsApi['track'];
  /** An extra pageview; `url` may be relative. */
  pageview: AnalyticsApi['pageview'];
  /** Content key for the following events; `null` falls back to the `<meta>` tag. */
  setContent: AnalyticsApi['setContent'];
  /** The `an_vid` cookie value, or `null` without consent (and before the tracker has loaded). */
  getVisitorId: AnalyticsApi['getVisitorId'];
  consent: AnalyticsConsent;
  /**
   * Settles once the tracker script has run: `true` when the tracker is installed, `false` when the
   * script could not load (blocked, offline), the site has tracking disabled, or on the server.
   * It never rejects.
   */
  ready: Promise<boolean>;
  /** The global the tracker is (or will be) installed as. */
  readonly globalName: string;
}

export interface AnalyticsConsent {
  /** Reopens the banner (no-op when the site has no cookie level). */
  open: AnalyticsApi['consent']['open'];
  /** The current choice; `unknown` until the tracker has loaded. */
  get: AnalyticsApi['consent']['get'];
  /** Records a choice made in your own UI. */
  set: AnalyticsApi['consent']['set'];
  /** Subscribes to changes; returns the unsubscribe function. Works before the tracker loads. */
  onChange: AnalyticsApi['consent']['onChange'];
  /** Erases the visitor server-side, deletes the cookies and records a rejection. */
  forget: AnalyticsApi['consent']['forget'];
}

/** The queue stub that stands in for the tracker until it loads (`{q: [[method, ...args], ...]}`). */
export interface AnalyticsStub {
  q: unknown[][];
  /** The snippet's stub has only `track`; the SDK's stub has every method that returns nothing. */
  track?: AnalyticsApi['track'];
  pageview?: AnalyticsApi['pageview'];
  setContent?: AnalyticsApi['setContent'];
  consent?: Partial<Pick<AnalyticsApi['consent'], 'open' | 'set' | 'onChange' | 'forget'>>;
}

/** What a global slot holds: the queue stub before the tracker runs, the tracker API after. */
export type AnalyticsGlobal = TrackerApi | AnalyticsStub;

/**
 * Window typings for a custom global name:
 *
 * ```ts
 * declare global {
 *   interface Window extends AnalyticsGlobals<'stats'> {}
 * }
 * window.stats?.track('signup')
 * ```
 */
export type AnalyticsGlobals<N extends string = 'analytics'> = { [K in N]?: AnalyticsGlobal };

/** `Window` with the tracker at `N`, for one-off casts: `(window as AnalyticsWindow<'stats'>).stats`. */
export type AnalyticsWindow<N extends string = 'analytics'> = Window & AnalyticsGlobals<N>;

declare global {
  interface Window {
    /** The analytics tracker (or its queue stub before it loads). */
    analytics?: AnalyticsGlobal;
    /** Where the tracker installs itself when `window.analytics` is taken by something else. */
    __analytics?: AnalyticsGlobal;
  }
}
