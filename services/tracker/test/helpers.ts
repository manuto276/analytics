import Ajv2020 from 'ajv/dist/2020.js';
import { vi } from 'vitest';
import type { Cfg, ConsentCfg } from '../src/config';

import schema from '../../../docs/api/tracking-payload.v1.schema.json';
const ajv = new Ajv2020({ allErrors: true, strict: false });
export const validatePayload = ajv.compile(schema);

export const KEY = 'pk_ABCDEFGHIJKLMNOPQRSTU';
export const NOW = Date.UTC(2026, 8, 17, 12, 0, 0);
export const DAY_MS = 864e5;
export const today = (): number => Math.floor(Date.now() / DAY_MS);

type DeepPartial<T> = { [K in keyof T]?: T[K] extends object ? DeepPartial<T[K]> : T[K] };

export const makeConsent = (o: Partial<ConsentCfg> = {}): ConsentCfg => ({
  v: 3,
  rev: 7,
  dl: 'en',
  at: 180,
  rt: 180,
  fl: false,
  theme: { bg: '#ffffff', fg: '#111827', ac: '#1d4ed8', acf: '#ffffff', rad: 8, pos: 'bottom' },
  texts: {
    en: {
      title: 'Cookies',
      body: 'We use cookies.',
      accept: 'Accept',
      reject: 'Reject',
      close: 'Close',
      policy: 'Privacy policy',
      policyUrl: 'https://www.example.com/privacy',
      reopen: 'Cookie settings',
    },
    it: {
      title: 'Cookie',
      body: 'Usiamo i cookie.',
      accept: 'Accetta',
      reject: 'Rifiuta',
      close: 'Chiudi',
      policy: 'Informativa',
      policyUrl: 'https://www.example.com/privacy-it',
      reopen: 'Impostazioni cookie',
    },
  },
  ...o,
});

export const makeConfig = (o: DeepPartial<Cfg> & { consent?: ConsentCfg | null } = {}): Cfg => ({
  k: KEY,
  g: 'analytics',
  b: true,
  c: true,
  cd: null,
  vd: 395,
  hr: false,
  dnt: 'ignore',
  gpc: true,
  xp: [],
  loc: false,
  ep: 'https://stats.example.net/t/',
  auto: { outbound: false, downloads: false, forms: false },
  consent: makeConsent(),
  ...(o as Partial<Cfg>),
});

/* ------------------------------------------------------------------ cookie jar */

interface Cookie {
  name: string;
  value: string;
  domain: string;
  hostOnly: boolean;
  expires: number;
}

export class CookieJar {
  cookies: Cookie[] = [];
  writes: string[] = [];
  constructor(public suffixes: string[] = ['com', 'net', 'org', 'test', 'uk', 'co.uk']) {}

  write(str: string): void {
    this.writes.push(str);
    const [pair, ...attrs] = str.split(/;\s*/);
    const eq = pair.indexOf('=');
    const name = pair.slice(0, eq);
    const value = pair.slice(eq + 1);
    const host = location.hostname;
    let domain = host;
    let hostOnly = true;
    let expires = Infinity;
    let secure = false;
    for (const a of attrs) {
      const [k, v = ''] = a.split('=');
      const key = k.toLowerCase();
      if (key == 'domain') {
        domain = v.replace(/^\./, '').toLowerCase();
        hostOnly = false;
      } else if (key == 'max-age') expires = Date.now() + Number(v) * 1000;
      else if (key == 'secure') secure = true;
    }
    if (secure && location.protocol != 'https:') return;
    if (!hostOnly) {
      if (this.suffixes.includes(domain)) return;
      if (host != domain && !host.endsWith('.' + domain)) return;
    }
    this.cookies = this.cookies.filter((c) => !(c.name == name && c.domain == domain && c.hostOnly == hostOnly));
    if (expires > Date.now()) this.cookies.push({ name, value, domain, hostOnly, expires });
  }

  read(): string {
    const host = location.hostname;
    return this.cookies
      .filter((c) => c.expires > Date.now())
      .filter((c) => (c.hostOnly ? c.domain == host : host == c.domain || host.endsWith('.' + c.domain)))
      .map((c) => c.name + '=' + c.value)
      .join('; ');
  }

  get(name: string): Cookie | undefined {
    return this.cookies.find((c) => c.name == name && c.expires > Date.now());
  }
}

/* ------------------------------------------------------------------ DOM */

export interface Sent {
  url: string;
  via: 'beacon' | 'fetch';
  body: Record<string, unknown> & { e?: Record<string, unknown>[] };
  type?: string;
  init?: RequestInit;
}

export interface Env {
  jar: CookieJar;
  beacon: ReturnType<typeof vi.fn>;
  fetch: ReturnType<typeof vi.fn>;
  sent: () => Sent[];
  events: () => (Record<string, unknown> & { _l: unknown })[];
}

export interface DomOptions {
  url?: string;
  lang?: string;
  referrer?: string;
  webdriver?: boolean;
  gpc?: boolean;
  dnt?: string | null;
  prerendering?: boolean;
  visibility?: DocumentVisibilityState;
  beacon?: boolean | 'missing';
  fetchStatus?: number | 'error' | ((n: number) => number | 'error');
  suffixes?: string[];
  html?: string;
  cfg?: Cfg | null;
}

class FakeBlob {
  constructor(
    public parts: string[],
    public opts: { type: string },
  ) {}
}

const cleanups: (() => void)[] = [];

const defineProp = (obj: object, key: string, value: unknown): void => {
  const own = Object.getOwnPropertyDescriptor(obj, key);
  Object.defineProperty(obj, key, { configurable: true, get: () => value });
  cleanups.push(() => {
    if (own) Object.defineProperty(obj, key, own);
    else Reflect.deleteProperty(obj, key);
  });
};

const trackListeners = (target: EventTarget): void => {
  const orig = target.addEventListener;
  const added: [string, EventListenerOrEventListenerObject, unknown][] = [];
  target.addEventListener = function (this: EventTarget, t: string, l: EventListenerOrEventListenerObject, o?: unknown) {
    added.push([t, l, o]);
    return orig.call(this, t, l, o as AddEventListenerOptions);
  };
  cleanups.push(() => {
    target.addEventListener = orig;
    for (const [t, l, o] of added) target.removeEventListener(t, l, o as EventListenerOptions);
  });
};

export const installDom = (o: DomOptions = {}): Env => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
  vi.setSystemTime(NOW);
  (window as unknown as { happyDOM: { setURL(u: string): void } }).happyDOM.setURL(
    o.url ?? 'https://www.example.com/page?utm_source=x#frag',
  );
  document.documentElement.lang = o.lang ?? 'en';
  document.head.innerHTML = '';
  document.body.innerHTML = o.html ?? '';

  const jar = new CookieJar(o.suffixes);
  Object.defineProperty(document, 'cookie', {
    configurable: true,
    get: () => jar.read(),
    set: (v: string) => jar.write(v),
  });
  cleanups.push(() => delete (document as unknown as Record<string, unknown>).cookie);

  defineProp(document, 'referrer', o.referrer ?? 'https://search.example.org/?q=x');
  defineProp(document, 'visibilityState', o.visibility ?? 'visible');
  if (o.prerendering != null) defineProp(document, 'prerendering', o.prerendering);
  defineProp(navigator, 'webdriver', o.webdriver ?? false);
  defineProp(navigator, 'globalPrivacyControl', o.gpc);
  defineProp(navigator, 'doNotTrack', o.dnt ?? null);

  const beacon = vi.fn(() => o.beacon !== false);
  defineProp(navigator, 'sendBeacon', o.beacon == 'missing' ? undefined : beacon);
  let calls = 0;
  const fetchMock = vi.fn(() => {
    const st = typeof o.fetchStatus == 'function' ? o.fetchStatus(calls++) : (o.fetchStatus ?? 202);
    return st == 'error' ? Promise.reject(new TypeError('network')) : Promise.resolve({ status: st, ok: st < 300 });
  });
  vi.stubGlobal('fetch', fetchMock);
  vi.stubGlobal('Blob', FakeBlob);

  const h = history as unknown as Record<string, unknown>;
  const push = h.pushState;
  const replace = h.replaceState;
  cleanups.push(() => {
    h.pushState = push;
    h.replaceState = replace;
  });
  trackListeners(window);
  trackListeners(document);

  if (o.cfg !== null) (window as unknown as Record<string, unknown>).__an_cfg = o.cfg ?? makeConfig();

  const sent = (): Sent[] => {
    const out: Sent[] = [];
    for (const c of beacon.mock.calls as unknown as [string, FakeBlob][])
      out.push({ url: c[0], via: 'beacon', body: JSON.parse(c[1].parts[0]), type: c[1].opts.type });
    for (const c of fetchMock.mock.calls as unknown as [string, RequestInit][])
      out.push({ url: c[0], via: 'fetch', body: JSON.parse(c[1].body as string), init: c[1] });
    for (const s of out)
      if (s.url.endsWith('/e') && !validatePayload(s.body))
        throw new Error('invalid payload: ' + JSON.stringify(validatePayload.errors) + ' ' + JSON.stringify(s.body));
    return out;
  };
  const events = () =>
    sent()
      .filter((s) => s.url.endsWith('/e') && s.via == 'beacon')
      .flatMap((s) => (s.body.e ?? []).map((e) => ({ ...e, _l: s.body.l })));

  return { jar, beacon, fetch: fetchMock, sent, events };
};

export const cleanupDom = (): void => {
  while (cleanups.length) (cleanups.pop() as () => void)();
  const wr = window as unknown as Record<string, unknown>;
  delete wr.__an_cfg;
  delete wr.analytics;
  delete wr.__analytics;
  document.body.innerHTML = '';
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
};

export const advanceTime = (ms: number): void => {
  vi.advanceTimersByTime(ms);
};

/** Import a fresh tracker instance (runs src/index.ts). */
export const load = async (): Promise<void> => {
  vi.resetModules();
  await import('../src/index');
};

export interface Api {
  track(n: string, p?: Record<string, unknown>): void;
  pageview(o?: { url?: string }): void;
  setContent(k: string | null): void;
  getVisitorId(): string | null;
  consent: {
    open(): void;
    get(): { status: string; version: number | null; decidedAt: number | null };
    set(s: string): void;
    onChange(cb: (s: unknown) => void): () => void;
    forget(): void;
  };
  __an: number;
}

export const api = (name = 'analytics'): Api => (window as unknown as Record<string, Api>)[name];

export const bannerHost = (): HTMLElement | null => document.querySelector('[data-analytics-banner]');
export const shadow = (): ShadowRoot => bannerHost()!.shadowRoot!;
export const dialogEl = (): HTMLElement | null => bannerHost()?.shadowRoot?.querySelector('[role="dialog"]') ?? null;
export const click = (el: Element | null | undefined): void => {
  (el as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, cancelable: true }));
};
export const consentCookie = (version: number, decision: 'a' | 'r', days: number): string =>
  `an_consent=1.${version}.${decision}.${days.toString(36)}`;
export const setCookie = (env: Env, str: string): void => {
  env.jar.write(str + '; Path=/');
  env.jar.writes = [];
};
