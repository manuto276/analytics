import { cfg, w } from './config';
import { href, last } from './collector';

export const initSpa = (pageview: (u: string, r: string | undefined) => void): void => {
  const nav = (): void => {
    const u = href();
    if (u != last) pageview(u, last);
  };
  const h = history as History & Record<string, (...a: unknown[]) => void>;
  for (const m of ['pushState', 'replaceState']) {
    const o = h[m];
    h[m] = function (this: History, ...a: unknown[]) {
      o.apply(this, a);
      nav();
    };
  }
  w.addEventListener('popstate', nav);
  if (cfg.hr) w.addEventListener('hashchange', nav);
};
