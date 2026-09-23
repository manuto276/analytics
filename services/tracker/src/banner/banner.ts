import type { BannerFactory, ConsentCfg } from '../config';
import { dialog, h, reopen } from './template';

const d = document;

const locale = (c: ConsentCfg): string => {
  const l = d.documentElement.lang.split('-')[0].toLowerCase();
  return c.texts[l] ? l : c.texts[c.dl] ? c.dl : Object.keys(c.texts)[0];
};

/**
 * The banner UI. All styling comes from `c.css`, compiled by the server; this module only builds
 * the DOM (fixed class names, no inline styles) and wires the buttons to the core's callbacks.
 */
export const make: BannerFactory = (c, decide, open) => {
  let host: HTMLElement | undefined;
  let box: HTMLElement;
  let prev: HTMLElement | null = null;

  /** Create (once) and attach the shadow host, returning the emptied content box. */
  const mount = (): HTMLElement => {
    if (!host) {
      host = h('div', { 'data-analytics-banner': '' });
      const root = host.attachShadow({ mode: 'open' });
      const s = c.css || '';
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

  /** Show the floating reopen button when configured. */
  const fab = (): void => {
    if (!c.fl) return;
    const l = locale(c);
    const b = reopen(c.texts[l], l, c.ri);
    b.onclick = open;
    mount().append(b);
  };

  return {
    show: (focus) => {
      const l = locale(c);
      if (focus) prev = d.activeElement as HTMLElement | null;
      const el = dialog(c.texts[l], l);
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
    },
    fab,
    /** Close the banner after a choice, returning focus to where it was before `consent.open()`. */
    close: () => {
      if (host) host.remove();
      fab();
      if (prev) prev.focus();
      prev = null;
    },
  };
};
