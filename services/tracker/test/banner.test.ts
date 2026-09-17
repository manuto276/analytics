import { describe, expect, it } from 'vitest';
import { css } from '../src/banner/styles';
import {
  advanceTime,
  api,
  bannerHost,
  cleanupDom,
  click,
  consentCookie,
  dialogEl,
  installDom,
  load,
  makeConfig,
  makeConsent,
  setCookie,
  shadow,
  today,
} from './helpers';

describe('banner', () => {
  it('renders in an open shadow root prepended to body with constructable styles', async () => {
    installDom({ html: '<main>content</main>' });
    await load();
    const host = bannerHost()!;
    expect(document.body.firstElementChild).toBe(host);
    expect(host.getAttribute('data-analytics-banner')).toBe('');
    expect(host.shadowRoot).not.toBeNull();
    expect(shadow().adoptedStyleSheets).toHaveLength(1);
    expect(shadow().querySelector('style')).toBeNull();
    // no blocking overlay: only the dialog is rendered
    expect(shadow().querySelectorAll('[role="dialog"]')).toHaveLength(1);
    expect(document.body.querySelector('main')!.textContent).toBe('content');
  });

  it('falls back to a <style> element without adoptedStyleSheets', async () => {
    installDom();
    const proto = ShadowRoot.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'adoptedStyleSheets')!;
    Object.defineProperty(proto, 'adoptedStyleSheets', { configurable: true, get: () => undefined, set: () => undefined });
    try {
      await load();
      expect(shadow().querySelector('style')!.textContent).toContain('.b,.f{position:fixed');
    } finally {
      Object.defineProperty(proto, 'adoptedStyleSheets', desc);
    }
  });

  it('exposes dialog semantics with labelled and described content', async () => {
    installDom();
    await load();
    const d = dialogEl()!;
    expect(d.getAttribute('aria-modal')).toBe('false');
    expect(d.getAttribute('tabindex')).toBe('-1');
    expect(d.getAttribute('lang')).toBe('en');
    expect(shadow().getElementById(d.getAttribute('aria-labelledby')!)!.textContent).toBe('Cookies');
    expect(shadow().getElementById(d.getAttribute('aria-describedby')!)!.textContent).toBe(
      'We use cookies. Privacy policy',
    );
    const close = d.querySelector('[data-x]')!;
    expect(close.tagName).toBe('BUTTON');
    expect(close.getAttribute('aria-label')).toBe('Close');
    const link = d.querySelector('a')!;
    expect(link.getAttribute('href')).toBe('https://www.example.com/privacy');
    expect(link.getAttribute('rel')).toBe('noopener');
  });

  it('renders Accept and Reject as native buttons with identical styling', async () => {
    installDom();
    await load();
    const a = dialogEl()!.querySelector('[data-a]')!;
    const r = dialogEl()!.querySelector('[data-r]')!;
    expect(a.tagName).toBe('BUTTON');
    expect(r.tagName).toBe('BUTTON');
    expect(a.className).toBe(r.className);
    expect(a.getAttribute('type')).toBe(r.getAttribute('type'));
    expect(a.getAttribute('style')).toBe(r.getAttribute('style'));
    expect(a.parentElement).toBe(r.parentElement);
    expect([a.textContent, r.textContent]).toEqual(['Accept', 'Reject']);
  });

  it.each([
    ['it-IT', 'it', 'Accetta'],
    ['IT', 'it', 'Accetta'],
    ['fr', 'en', 'Accept'],
    ['', 'en', 'Accept'],
  ])('picks the locale from <html lang="%s">', async (lang, expected, accept) => {
    installDom({ lang });
    await load();
    expect(dialogEl()!.getAttribute('lang')).toBe(expected);
    expect(dialogEl()!.querySelector('[data-a]')!.textContent).toBe(accept);
  });

  it('falls back to the first locale when the default one is missing', async () => {
    const c = makeConsent({ dl: 'de' });
    delete (c.texts as Record<string, unknown>).en;
    installDom({ lang: 'fr', cfg: makeConfig({ consent: c }) });
    await load();
    expect(dialogEl()!.getAttribute('lang')).toBe('it');
  });

  it('omits unsafe policy links and uses the url as label when there is no policy text', async () => {
    const c = makeConsent();
    c.texts.en.policyUrl = 'javascript:alert(1)';
    c.texts.it.policyUrl = '/privacy';
    c.texts.it.policy = undefined;
    installDom({ cfg: makeConfig({ consent: c }) });
    await load();
    expect(dialogEl()!.querySelector('a')).toBeNull();
    cleanupDom();
    installDom({ lang: 'it', cfg: makeConfig({ consent: c }) });
    await load();
    expect(dialogEl()!.querySelector('a')!.textContent).toBe('/privacy');
  });

  it('opens via consent.open(), focuses the dialog and returns focus on close', async () => {
    const env = installDom({ html: '<button id="prefs">Prefs</button>' });
    setCookie(env, consentCookie(3, 'r', today()));
    await load();
    expect(bannerHost()).toBeNull();
    const prefs = document.getElementById('prefs')!;
    prefs.focus();
    expect(document.activeElement).toBe(prefs);
    api().consent.open();
    expect(shadow().activeElement).toBe(dialogEl());
    click(dialogEl()!.querySelector('[data-a]'));
    expect(dialogEl()).toBeNull();
    expect(document.activeElement).toBe(prefs);
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'cs').map((e) => e.cs)).toEqual(['reopen', 'accept']);
  });

  it('does not steal focus when shown automatically', async () => {
    installDom({ html: '<input id="q">' });
    const q = document.getElementById('q')!;
    q.focus();
    await load();
    expect(document.activeElement).toBe(q);
    click(dialogEl()!.querySelector('[data-r]'));
    expect(document.activeElement).toBe(q);
  });

  it('shows a floating reopen button after a choice when configured', async () => {
    const env = installDom({ cfg: makeConfig({ consent: makeConsent({ fl: true, theme: { pos: 'bottom-right' } }) }) });
    await load();
    expect(shadow().querySelector('[data-o]')).toBeNull();
    click(dialogEl()!.querySelector('[data-r]'));
    const fab = shadow().querySelector('[data-o]')!;
    expect(fab.textContent).toBe('Cookie settings');
    expect(fab.className).toBe('k f r');
    expect(dialogEl()).toBeNull();
    click(fab);
    expect(dialogEl()).not.toBeNull();
    expect(shadow().querySelector('[data-o]')).toBeNull();
    dialogEl()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(shadow().querySelector('[data-o]')).not.toBeNull();
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'cs').map((e) => e.cs)).toEqual(['shown', 'reject', 'reopen', 'dismiss']);
  });

  it('shows the floating button on later page loads and falls back to the title', async () => {
    const c = makeConsent({ fl: true, theme: undefined });
    c.texts.en.reopen = undefined;
    const env = installDom({ cfg: makeConfig({ consent: c }) });
    setCookie(env, consentCookie(3, 'a', today()));
    await load();
    const fab = shadow().querySelector('[data-o]')!;
    expect(fab.textContent).toBe('Cookies');
    expect(fab.className).toBe('k f');
    expect(dialogEl()).toBeNull();
  });

  it('applies the position class', async () => {
    installDom({ cfg: makeConfig({ consent: makeConsent({ theme: { pos: 'bottom-left' } }) }) });
    await load();
    expect(dialogEl()!.className).toBe('b l');
    cleanupDom();
    installDom({ cfg: makeConfig({ consent: makeConsent({ theme: { pos: 'bottom-right' } }) }) });
    await load();
    expect(dialogEl()!.className).toBe('b r');
  });

  it('waits for the body when loaded from <head>', async () => {
    installDom();
    const body = document.body;
    body.remove();
    await load();
    expect(bannerHost()).toBeNull();
    document.documentElement.append(body);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    expect(document.body.firstElementChild).toBe(bannerHost());
  });
});

describe('banner styles', () => {
  it('uses theme colors and radius, with motion and forced-colors support', () => {
    const s = css({ bg: '#000000', fg: '#fafafa', ac: '#ff0000', acf: '#00ff00', rad: 12 });
    expect(s).toContain('background:#000000');
    expect(s).toContain('color:#fafafa');
    expect(s).toContain('border:2px solid #ff0000');
    expect(s).toContain('color:#00ff00');
    expect(s).toContain('border-radius:12px');
    expect(s).toContain('@media (prefers-reduced-motion:reduce)');
    expect(s).toContain('@media (forced-colors:active)');
    expect(s).toContain(':focus-visible');
  });

  it('rejects unsafe theme values', () => {
    const s = css({ bg: 'red;}*{display:none', fg: '#12', rad: 500 });
    expect(s).not.toContain('display:none');
    expect(s).toContain('background:#fff');
    expect(s).toContain('color:#111');
    expect(s).toContain('border-radius:32px');
    expect(css({ rad: -3 })).toContain('border-radius:0px');
    expect(css()).toContain('border-radius:8px');
  });
});
