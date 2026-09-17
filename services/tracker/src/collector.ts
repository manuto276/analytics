import { cfg, d, n, now, w } from './config';
import { cv, ids } from './consent';
import { rid } from './ids';
import { send } from './transport';

export type Level = 'b' | 'c';
export type Props = Record<string, string | number | boolean>;

export interface Ev {
  id: string;
  t: 'pv' | 'ev' | 'en' | 'cu' | 'cs';
  u: string;
  r?: string;
  a?: number;
  n?: string;
  p?: Props;
  ck?: string;
  ms?: number;
  sp?: number;
  cs?: string;
  lu?: string;
  lr?: string;
}

type Queued = [Level, Ev, number];

let q: Queued[] = [];
let tm: ReturnType<typeof setTimeout> | undefined;
/** Tracking disabled for this page load (webdriver, local, DNT no_tracking). */
export let off = false;
/** Last pageview URL. */
export let last: string | undefined;
let ckSet: string | null | undefined;
let pu: string | undefined;
let vis = 0;
let vs = 0;
let sp = 0;

const cut = (s: string | null | undefined): string | undefined => (s ? s.slice(0, 2048) : undefined);

export const href = (): string => {
  const h = location.href;
  return cut(cfg.hr ? h : h.split('#')[0]) as string;
};

const excluded = (u: string): boolean => {
  const p = new URL(u).pathname;
  return (cfg.xp || []).some((g) =>
    RegExp('^' + g.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$').test(p),
  );
};

export const setContent = (k?: string | null): void => {
  ckSet = k;
};

const ck = (): string | undefined => {
  const m = d.querySelector<HTMLMetaElement>('meta[name="analytics:content"]');
  const v = ckSet != null ? ckSet : m && m.content;
  return v ? String(v).slice(0, 128) : undefined;
};

export const flush = (): void => {
  clearTimeout(tm);
  const all = q;
  q = [];
  for (const l of ['b', 'c'] as const) {
    const t = now();
    const e = all.filter((x) => x[0] == l).map((x) => ((x[1].a = Math.max(0, t - x[2])), x[1]));
    if (!e.length) continue;
    const x = l == 'c' ? ids() : {};
    if (!x) continue;
    while (e.length) send({ v: 1, k: cfg.k, l, ...x, cv: cv(), sw: screen.width | 0, e: e.splice(0, 50) });
  }
};

export const push = (t: Ev['t'], o: Partial<Ev>, l: Level): void => {
  const u = o.u || href();
  if (off || excluded(u) || (l == 'b' && t != 'cs' && cfg.b === false)) return;
  q.push([l, { id: rid(12), t, u, ...o }, now()]);
  clearTimeout(tm);
  if (q.length > 9) flush();
  else tm = setTimeout(flush, 1e3);
};

const scroll = (): void => {
  const h = d.documentElement.scrollHeight;
  sp = Math.max(sp, h ? Math.min(100, Math.round(((w.scrollY + w.innerHeight) / h) * 100)) : 100);
};

const visible = (): boolean => d.visibilityState != 'hidden';

/** Send `en` for the current page (if any engaged time) and reset the counters. */
export const engage = (l: Level): void => {
  const ms = Math.round(vis + (vs ? now() - vs : 0));
  if (pu && ms > 0) push('en', { u: pu, ms, sp, ck: ck() }, l);
  vis = sp = 0;
  vs = visible() ? now() : 0;
};

export const pv = (u: string, r: string | undefined, l: Level): void => {
  engage(l);
  pu = last = cut(u) as string;
  push('pv', { u: pu, r: cut(r), ck: ck() }, l);
  scroll();
};

export const track = (name: string, props: Record<string, unknown> | undefined, l: Level): void => {
  if (!/^[a-z0-9_:.-]{1,64}$/.test(name)) return;
  const p: Props = {};
  let c = 0;
  for (const k in props) {
    const v = props[k];
    const ty = typeof v;
    if (c < 10 && k.length < 33 && (ty == 'string' || ty == 'boolean' || (ty == 'number' && isFinite(v as number)))) {
      p[k] = ty == 'string' ? (v as string).slice(0, 100) : (v as number | boolean);
      c++;
    }
  }
  push('ev', { n: name, p: c ? p : undefined, ck: ck() }, l);
};

export const initCollector = (level: () => Level): void => {
  off = !!(
    n.webdriver ||
    (!cfg.loc && (location.protocol == 'file:' || /^(localhost|127\.0\.0\.1|\[::1\])$|\.localhost$/.test(location.hostname))) ||
    (cfg.dnt == 'no_tracking' && n.doNotTrack == '1')
  );
  vs = visible() ? now() : 0;
  w.addEventListener('scroll', scroll, { passive: true });
  d.addEventListener('visibilitychange', () => {
    if (visible()) vs = now();
    else {
      if (vs) vis += now() - vs;
      vs = 0;
      flush();
    }
  });
  w.addEventListener('pagehide', () => {
    engage(level());
    flush();
  });
};
