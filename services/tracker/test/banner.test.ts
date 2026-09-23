import { describe, expect, it } from 'vitest';
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
      expect(shadow().querySelector('style')!.textContent).toBe('.b{position:fixed}.k{color:#fff}');
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
    // Stronger than "same class": apart from the hook that tells them apart for the click handler
    // (data-a / data-r, which the compiled stylesheet never references), the two buttons carry
    // exactly the same attributes, no inline style and no children other than their label, and
    // they are the only two children of the actions row. Nothing a stylesheet can select
    // (class, type, attribute, :first-child aside) distinguishes one from the other.
    const attrs = (el: Element): string[][] =>
      Array.from(el.attributes)
        .filter((x) => x.name != 'data-a' && x.name != 'data-r')
        .map((x) => [x.name, x.value]);
    expect(attrs(a)).toEqual(attrs(r));
    expect(attrs(a)).toEqual([
      ['type', 'button'],
      ['class', 'k'],
    ]);
    expect(a.hasAttribute('style') || r.hasAttribute('style')).toBe(false);
    expect([a.childNodes.length, r.childNodes.length]).toEqual([1, 1]);
    expect(Array.from(a.parentElement!.children)).toEqual([r, a]);
    expect(a.parentElement!.className).toBe('a');
    expect(shadow().querySelectorAll('.k')).toHaveLength(2);
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
    const env = installDom({ cfg: makeConfig({ consent: makeConsent({ fl: true }) }) });
    await load();
    expect(shadow().querySelector('[data-o]')).toBeNull();
    click(dialogEl()!.querySelector('[data-r]'));
    const fab = shadow().querySelector('[data-o]')!;
    expect(fab.textContent).toBe('Cookie settings');
    expect(fab.tagName).toBe('BUTTON');
    expect(fab.className).toBe('f');
    expect(fab.getAttribute('type')).toBe('button');
    expect(fab.getAttribute('aria-label')).toBe('Cookie settings');
    expect(fab.getAttribute('lang')).toBe('en');
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
    const c = makeConsent({ fl: true });
    c.texts.en.reopen = undefined;
    const env = installDom({ cfg: makeConfig({ consent: c }) });
    setCookie(env, consentCookie(3, 'a', today()));
    await load();
    const fab = shadow().querySelector('[data-o]')!;
    expect(fab.textContent).toBe('Cookies');
    expect(fab.getAttribute('aria-label')).toBe('Cookies');
    expect(fab.className).toBe('f');
    expect(dialogEl()).toBeNull();
  });

  it('renders the reopen icon from the configured path, hidden from assistive technology', async () => {
    const c = makeConsent({ fl: true });
    const env = installDom({ cfg: makeConfig({ consent: c }) });
    setCookie(env, consentCookie(3, 'r', today()));
    await load();
    const fab = shadow().querySelector('[data-o]')!;
    const svg = fab.querySelector('svg')!;
    expect(svg.namespaceURI).toBe('http://www.w3.org/2000/svg');
    expect(svg.getAttribute('class')).toBe('i');
    expect(svg.getAttribute('viewBox')).toBe('0 0 24 24');
    expect(svg.getAttribute('aria-hidden')).toBe('true');
    expect(svg.querySelector('path')!.getAttribute('d')).toBe(c.ri);
    expect(fab.querySelector('span.t')!.textContent).toBe('Cookie settings');
    // icon first, then the label: the stylesheet decides which one is visible per device
    expect(Array.from(fab.children).map((e) => e.tagName.toLowerCase())).toEqual(['svg', 'span']);
    expect(fab.hasAttribute('style')).toBe(false);
  });

  it('renders a text-only reopen button when no icon path is configured', async () => {
    const env = installDom({ cfg: makeConfig({ consent: makeConsent({ fl: true, ri: undefined }) }) });
    setCookie(env, consentCookie(3, 'a', today()));
    await load();
    const fab = shadow().querySelector('[data-o]')!;
    expect(fab.querySelector('svg')).toBeNull();
    expect(fab.textContent).toBe('Cookie settings');
  });

  it('uses fixed class names and no inline styles: layout comes from the stylesheet', async () => {
    installDom();
    await load();
    const d = dialogEl()!;
    expect(d.className).toBe('b');
    expect(d.querySelector('.a')).not.toBeNull();
    expect(d.querySelector('.x')).not.toBeNull();
    expect(shadow().querySelectorAll('[style]')).toHaveLength(0);
  });

  it('applies an empty stylesheet when the config carries none', async () => {
    installDom({ cfg: makeConfig({ consent: makeConsent({ css: undefined }) }) });
    await load();
    expect(shadow().adoptedStyleSheets).toHaveLength(1);
    expect(dialogEl()).not.toBeNull();
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

describe('core without the banner module', () => {
  it('keeps consent working when the banner module is absent', async () => {
    const env = installDom();
    await load({ banner: false });
    expect(bannerHost()).toBeNull();
    expect(api().consent.get().status).toBe('unknown');
    api().consent.open();
    expect(bannerHost()).toBeNull();
    api().consent.set('accepted');
    expect(api().consent.get().status).toBe('accepted');
    expect(api().getVisitorId()).toMatch(/^[\w-]{22}$/);
    advanceTime(1000);
    // no banner was displayed, so no `shown` counter
    expect(env.events().filter((e) => e.t == 'cs').map((e) => e.cs)).toEqual(['reopen', 'accept']);
  });

  it('keeps a stored decision without the banner module: ids at the cookie level, no floating button', async () => {
    const env = installDom({ cfg: makeConfig({ consent: makeConsent({ fl: true }) }) });
    setCookie(env, consentCookie(3, 'a', today()));
    await load({ banner: false });
    expect(bannerHost()).toBeNull();
    expect(api().consent.get().status).toBe('accepted');
    expect(api().getVisitorId()).toMatch(/^[\w-]{22}$/);
  });

  it('shows the banner only for the configuration the core was given', async () => {
    installDom({ cfg: makeConfig({ c: false }) });
    await load();
    expect(bannerHost()).toBeNull();
    expect(typeof (window as unknown as Record<string, unknown>).__an_b).toBe('function');
  });
});
