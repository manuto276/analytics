import { d, type Texts } from '../config';

export const h = (tag: string, at: Record<string, string>, ...kids: (Node | string)[]): HTMLElement => {
  const e = d.createElement(tag);
  for (const k in at) e.setAttribute(k, at[k]);
  e.append(...kids);
  return e;
};

const btn = (cls: string, text: string, extra: Record<string, string> = {}): HTMLElement =>
  h('button', { type: 'button', class: cls, ...extra }, text);

export const dialog = (tx: Texts, lang: string, pos?: string): HTMLElement => {
  const u = tx.policyUrl;
  return h(
    'div',
    {
      class: 'b' + (pos == 'bottom-left' ? ' l' : pos == 'bottom-right' ? ' r' : ''),
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

export const reopen = (tx: Texts, lang: string, pos?: string): HTMLElement =>
  btn('k f' + (pos == 'bottom-right' ? ' r' : ''), tx.reopen || tx.title, { lang, 'data-o': '' });
