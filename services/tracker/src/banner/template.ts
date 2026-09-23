import type { Texts } from '../config';

const d = document;

export const h = (tag: string, at: Record<string, string>, ...kids: (Node | string)[]): HTMLElement => {
  const e = d.createElement(tag);
  for (const k in at) e.setAttribute(k, at[k]);
  e.append(...kids);
  return e;
};

/** Accept and Reject are built by this one function: same tag, class and attributes (Garante B2). */
const btn = (cls: string, text: string, extra: Record<string, string> = {}): HTMLElement =>
  h('button', { type: 'button', class: cls, ...extra }, text);

export const dialog = (tx: Texts, lang: string): HTMLElement => {
  const u = tx.policyUrl;
  return h(
    'div',
    {
      class: 'b',
      role: 'dialog',
      'aria-modal': 'false',
      'aria-labelledby': 't',
      'aria-describedby': 'd',
      tabindex: '-1',
      lang,
    },
    btn('x', '×', { 'aria-label': tx.close, 'data-x': '' }),
    h('h2', { id: 't' }, tx.title),
    h(
      'p',
      { id: 'd' },
      tx.body,
      ...(u && /^(https?:)?\//i.test(u) ? [' ', h('a', { href: u, target: '_blank', rel: 'noopener' }, tx.policy || u)] : []),
    ),
    h('div', { class: 'a' }, btn('k', tx.reject, { 'data-r': '' }), btn('k', tx.accept, { 'data-a': '' })),
  );
};

const NS = 'http://www.w3.org/2000/svg';

/**
 * Floating reopen button: icon (`.i`) and label (`.t`); the stylesheet shows either or both per
 * device. The aria-label keeps an icon-only button named.
 */
export const reopen = (tx: Texts, lang: string, path?: string): HTMLElement => {
  const t = tx.reopen || tx.title;
  const kids: Node[] = [];
  if (path) {
    const s = d.createElementNS(NS, 'svg');
    const p = d.createElementNS(NS, 'path');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('class', 'i');
    p.setAttribute('d', path);
    s.append(p);
    kids.push(s);
  }
  kids.push(h('span', { class: 't' }, t));
  return h('button', { type: 'button', class: 'f', 'aria-label': t, lang, 'data-o': '' }, ...kids);
};
