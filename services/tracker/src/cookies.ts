import { cfg, d } from './config';

const NAMES = ['an_vid', 'an_sid'];

export const getC = (name: string): string | undefined => {
  const m = d.cookie.match('(?:^|; )' + name + '=([^;]*)');
  return m ? m[1] : undefined;
};

const raw = (name: string, val: string, age: number, dom: string): void => {
  d.cookie =
    name +
    '=' +
    val +
    (dom ? '; Domain=' + dom : '') +
    '; Path=/; Max-Age=' +
    age +
    '; SameSite=Lax' +
    (location.protocol == 'http:' ? '' : '; Secure');
};

/** Candidate cookie domains, longest to shortest, never a bare TLD, none for IPs / single labels. */
export const cands = (): string[] => {
  const h = location.hostname;
  const p = h.split('.');
  const r: string[] = [];
  if (!/^[\d.]+$|:/.test(h)) for (let i = 0; i < p.length - 1; i++) r.push(p.slice(i).join('.'));
  return r;
};

let dom: string | undefined;

/** Configured domain, or the widest writable suffix found by probing longest -> shortest ('' = host-only). */
export const domain = (): string => {
  if (dom == null) {
    dom = cfg.cd || '';
    if (!cfg.cd)
      for (const c of cands()) {
        raw('an_probe', '1', 60, c);
        if (getC('an_probe') != '1') break;
        raw('an_probe', '', 0, c);
        dom = c;
      }
  }
  return dom;
};

export const setC = (name: string, val: string, age: number): void => raw(name, val, age, domain());

/** Delete an_vid / an_sid (when present) on the host and every candidate domain. */
export const wipe = (): void => {
  for (const name of NAMES)
    if (getC(name) != null) for (const c of ['', ...cands()]) raw(name, '', 0, c);
};
