import { cfg, d, type ConsentCfg } from '../config';
import { decide, open } from '../consent';
import { css } from './styles';
import { dialog, h, reopen } from './template';

let host: HTMLElement | undefined;
let box: HTMLElement;
let prev: HTMLElement | null = null;

const locale = (c: ConsentCfg): string => {
  const l = d.documentElement.lang.split('-')[0].toLowerCase();
  return c.texts[l] ? l : c.texts[c.dl] ? c.dl : Object.keys(c.texts)[0];
};

/** Create (once) and attach the shadow host, returning the emptied content box. */
const mount = (): HTMLElement => {
  if (!host) {
    host = h('div', { 'data-analytics-banner': '' });
    const root = host.attachShadow({ mode: 'open' });
    const s = css((cfg.consent as ConsentCfg).theme);
    try {
      if (!root.adoptedStyleSheets) throw 0;
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(s);
      root.adoptedStyleSheets = [sheet];
    } catch {
      root.append(h('style', {}, s));
    }
    root.append((box = h('div', {})));
  }
  const hs = host;
  if (!hs.isConnected) {
    if (d.body) d.body.prepend(hs);
    else d.addEventListener('DOMContentLoaded', () => d.body.prepend(hs));
  }
  box.textContent = '';
  return box;
};

export const show = (focus: boolean): void => {
  const c = cfg.consent as ConsentCfg;
  const l = locale(c);
  if (focus) prev = d.activeElement as HTMLElement | null;
  const el = dialog(c.texts[l], l, c.theme && c.theme.pos);
  const on = (sel: string, f: () => void): void => {
    (el.querySelector(sel) as HTMLElement).onclick = f;
  };
  on('[data-a]', () => decide('accepted', 'accept'));
  on('[data-r]', () => decide('rejected', 'reject'));
  on('[data-x]', () => decide('rejected', 'dismiss'));
  el.onkeydown = (e) => {
    if (e.key == 'Escape') decide('rejected', 'dismiss');
  };
  mount().append(el);
  if (focus) el.focus();
};

/** Show the floating reopen button when configured. */
export const fab = (): void => {
  const c = cfg.consent as ConsentCfg;
  if (!c.fl) return;
  const l = locale(c);
  const b = reopen(c.texts[l], l, c.theme && c.theme.pos);
  b.onclick = open;
  mount().append(b);
};

/** Close the banner after a choice, returning focus to where it was before `consent.open()`. */
export const close = (): void => {
  if (host) host.remove();
  fab();
  if (prev) prev.focus();
  prev = null;
};
