import { cfg, d, DAY, n, today, w, type BannerFactory, type BannerUi } from './config';
import { href, off, push, type Level } from './collector';
import { getC, setC, wipe } from './cookies';
import { fill } from './dom';
import { isId, rid } from './ids';
import { send } from './transport';

export type Status = 'unknown' | 'accepted' | 'rejected';
export interface ConsentState {
  status: Status;
  version: number | null;
  decidedAt: number | null;
}

let st: Status = 'unknown';
let ver: number | null = null;
let at: number | null = null;
/** GPC, DNT no_cookie or disabled tracking: behave as rejected, never ask, never write. */
let forced = false;
let landing: [string, string];
const ls: ((s: ConsentState) => void)[] = [];
/** The banner module, when the server bundled it (sites with the cookie level on). */
let ui: BannerUi | undefined;

/** Cookie level configured for this site. */
const enabled = (): boolean => !!(cfg.c && cfg.consent) && !forced;

export const level = (): Level => (enabled() && st == 'accepted' ? 'c' : 'b');

export const cv = (): number => ver || 0;

export const get = (): ConsentState => ({ status: forced ? 'rejected' : st, version: ver, decidedAt: at });

/** Parse `an_consent`: `1.<version>.<a|r>.<epoch-days base36>`. */
export const read = (): void => {
  const c = cfg.consent;
  const m = /^1\.(\d+)\.([ar])\.([\da-z]+)$/.exec(getC('an_consent') || '');
  st = 'unknown';
  ver = at = null;
  if (c && m) {
    const a = m[2] == 'a';
    const days = parseInt(m[3], 36);
    if (+m[1] >= c.v && today() - days < (a ? c.at : c.rt)) {
      st = a ? 'accepted' : 'rejected';
      ver = +m[1];
      at = days * DAY;
    }
  }
};

/** Visitor/session ids for cookie-level requests (creates an_vid, slides an_sid); null without consent. */
export const ids = (): { vid: string; sid: string } | null => {
  if (level() != 'c') return null;
  let vid = getC('an_vid');
  let sid = getC('an_sid');
  if (!isId(vid)) setC('an_vid', (vid = rid(16)), Math.min(cfg.vd || 395, 395) * 86400);
  if (!isId(sid)) sid = rid(16);
  setC('an_sid', sid, 1800);
  return { vid, sid };
};

export const visitorId = (): string | null => (level() == 'c' && getC('an_vid')) || null;

export const decide = (s: Status, kind: string): void => {
  const c = cfg.consent;
  if (!enabled() || !c || (s != 'accepted' && s != 'rejected')) return;
  const was = st;
  const a = s == 'accepted';
  const days = today();
  setC('an_consent', '1.' + c.v + '.' + (a ? 'a' : 'r') + '.' + days.toString(36), (a ? c.at : c.rt) * 86400);
  st = s;
  ver = c.v;
  at = days * DAY;
  push('cs', { cs: kind }, 'b');
  if (a) {
    ids();
    if (was != s) push('cu', { lu: landing[0], lr: landing[1] || undefined }, 'c');
  } else wipe();
  fill();
  if (ui) ui.close();
  for (const f of ls) f(get());
};

export const set = (s: Status): void => decide(s, s.slice(0, 6));

export const open = (): void => {
  if (!enabled()) return;
  push('cs', { cs: 'reopen' }, 'b');
  if (ui) ui.show(true);
};

export const onChange = (f: (s: ConsentState) => void): (() => void) => {
  ls.push(f);
  return () => {
    ls.splice(ls.indexOf(f), 1);
  };
};

export const forget = (): void => {
  const vid = getC('an_vid');
  if (vid) send({ k: cfg.k, vid }, 'forget');
  wipe();
  decide('rejected', 'reject');
};

export const initConsent = (): void => {
  landing = [href(), d.referrer];
  forced =
    off || (!!cfg.gpc && n.globalPrivacyControl === true) || (cfg.dnt == 'no_cookie' && n.doNotTrack == '1');
  read();
  if (forced || st == 'rejected') wipe();
  const mk = w.__an_b as BannerFactory | undefined;
  if (enabled()) {
    ui = mk && mk(cfg.consent as NonNullable<typeof cfg.consent>, decide, open);
    if (st == 'unknown') {
      if (ui) {
        ui.show(false);
        push('cs', { cs: 'shown' }, 'b');
      }
    } else {
      ids();
      if (ui) ui.fab();
    }
  }
  fill();
};
