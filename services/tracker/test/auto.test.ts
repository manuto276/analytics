import { describe, expect, it } from 'vitest';
import { advanceTime, cleanupDom, installDom, load, makeConfig } from './helpers';

const LINKS = `
  <a id="out" href="https://partner.example.org/pricing?ref=1">Partner</a>
  <a id="outdeep" href="https://partner.example.org/whitepaper.PDF">Paper</a>
  <a id="same" href="/internal">Internal</a>
  <a id="sub" href="https://app.example.com/dash">App</a>
  <a id="parent" href="https://example.com/">Root</a>
  <a id="dl" href="/files/report.pdf">Report</a>
  <a id="js" href="javascript:void(0)">JS</a>
  <a id="mail" href="mailto:hi@example.org">Mail</a>
  <a id="tel" href="tel:+3900000">Tel</a>
  <a id="anchor" href="#section">Anchor</a>
  <a id="nested" href="https://partner.example.org/a"><span id="inner">deep</span></a>
  <form id="f" name="newsletter" action="/subscribe?src=footer"><input name="email" value="a@b.c"><button type="submit">Go</button></form>
  <form id="" name="" action="https://forms.example.org/x"><button type="submit">Go</button></form>
`;

/** Stop happy-dom from navigating on link clicks (registered after the tracker's capture listener). */
const noNav = (): void => {
  document.addEventListener(
    'click',
    (e) => {
      e.preventDefault();
    },
    true,
  );
};

const clickOn = (id: string, init: MouseEventInit = {}): void => {
  document.getElementById(id)!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...init }));
};

const submitForm = (el: HTMLFormElement): void => {
  el.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
};

describe('automatic events', () => {
  it('sends nothing when the switches are off (the default config)', async () => {
    const env = installDom({ html: LINKS });
    await load();
    noNav();
    clickOn('out');
    clickOn('dl');
    submitForm(document.getElementById('f') as HTMLFormElement);
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'ev')).toBe(false);
  });

  it('is inert when the config carries no auto section and for non-element targets', async () => {
    let env = installDom({ html: LINKS, cfg: { ...makeConfig(), auto: undefined } });
    await load();
    noNav();
    clickOn('out');
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'ev')).toBe(false);

    cleanupDom();
    env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true, downloads: true, forms: true } }) });
    await load();
    document.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'ev')).toBe(false);
  });

  it('tracks outbound link clicks, ignoring own hosts, schemes and in-page anchors', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true } }) });
    await load();
    noNav();
    for (const id of ['out', 'same', 'sub', 'parent', 'js', 'mail', 'tel', 'anchor']) clickOn(id);
    clickOn('inner');
    advanceTime(1000);
    const ev = env.events().filter((e) => e.t == 'ev');
    expect(ev.map((e) => [e.n, e.p])).toEqual([
      ['outbound_link', { url: 'https://partner.example.org/pricing?ref=1', host: 'partner.example.org' }],
      // a sibling subdomain is not "own" under the configured rule
      ['outbound_link', { url: 'https://app.example.com/dash', host: 'app.example.com' }],
      ['outbound_link', { url: 'https://partner.example.org/a', host: 'partner.example.org' }],
    ]);
    expect(env.sent().every((s) => s.url.endsWith('/e'))).toBe(true);
  });

  it('counts middle clicks and modifier clicks', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true } }) });
    await load();
    noNav();
    clickOn('out', { button: 1 });
    clickOn('out', { ctrlKey: true });
    clickOn('out', { metaKey: true });
    advanceTime(1000);
    expect(env.events().filter((e) => e.n == 'outbound_link')).toHaveLength(3);
  });

  it('truncates the url property at 100 characters', async () => {
    const env = installDom({
      html: `<a id="long" href="https://partner.example.org/${'a'.repeat(200)}">x</a>`,
      cfg: makeConfig({ auto: { outbound: true } }),
    });
    await load();
    noNav();
    clickOn('long');
    advanceTime(1000);
    const p = env.events().find((e) => e.n == 'outbound_link')!.p as Record<string, string>;
    expect(p.url.length).toBe(100);
    expect(p.url).toBe('https://partner.example.org/' + 'a'.repeat(72));
  });

  it('tracks downloads and prefers file_download over outbound_link', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true, downloads: true } }) });
    await load();
    noNav();
    clickOn('dl');
    clickOn('outdeep');
    clickOn('out');
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'ev').map((e) => [e.n, e.p])).toEqual([
      ['file_download', { url: 'https://www.example.com/files/report.pdf', ext: 'pdf' }],
      ['file_download', { url: 'https://partner.example.org/whitepaper.PDF', ext: 'pdf' }],
      ['outbound_link', { url: 'https://partner.example.org/pricing?ref=1', host: 'partner.example.org' }],
    ]);
  });

  it('tracks same-host downloads with downloads only', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { downloads: true } }) });
    await load();
    noNav();
    clickOn('dl');
    clickOn('out');
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'ev').map((e) => e.n)).toEqual(['file_download']);
  });

  it('reports an outbound document link when only outbound is enabled', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true } }) });
    await load();
    noNav();
    clickOn('outdeep');
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'ev').map((e) => e.n)).toEqual(['outbound_link']);
  });

  it('tracks form submits by id or name, with the action path only and no field values', async () => {
    const env = installDom({ html: LINKS, cfg: makeConfig({ auto: { forms: true } }) });
    await load();
    const forms = document.querySelectorAll('form');
    submitForm(forms[0] as HTMLFormElement);
    submitForm(forms[1] as HTMLFormElement);
    document.dispatchEvent(new Event('submit', { bubbles: true }));
    advanceTime(1000);
    const ev = env.events().filter((e) => e.t == 'ev');
    expect(ev.map((e) => [e.n, e.p])).toEqual([
      ['form_submit', { id: 'f', action: '/subscribe' }],
      ['form_submit', { id: '', action: '/x' }],
    ]);
    expect(JSON.stringify(env.sent())).not.toContain('a@b.c');
  });

  it('falls back to the form name when there is no id', async () => {
    const env = installDom({
      html: '<form name="signup" action="/go"><button type="submit">Go</button></form>',
      cfg: makeConfig({ auto: { forms: true } }),
    });
    await load();
    submitForm(document.querySelector('form') as HTMLFormElement);
    advanceTime(1000);
    expect(env.events().find((e) => e.n == 'form_submit')!.p).toEqual({ id: 'signup', action: '/go' });
  });

  it('obeys the usual skips and batching', async () => {
    let env = installDom({ html: LINKS, webdriver: true, cfg: makeConfig({ auto: { outbound: true, forms: true } }) });
    await load();
    noNav();
    clickOn('out');
    submitForm(document.getElementById('f') as HTMLFormElement);
    advanceTime(1000);
    expect(env.sent()).toEqual([]);

    cleanupDom();
    env = installDom({
      html: LINKS,
      url: 'https://www.example.com/admin/x',
      cfg: makeConfig({ xp: ['/admin/*'], auto: { outbound: true } }),
    });
    await load();
    noNav();
    clickOn('out');
    advanceTime(1000);
    expect(env.sent()).toEqual([]);

    cleanupDom();
    env = installDom({ html: LINKS, cfg: makeConfig({ b: false, auto: { outbound: true } }) });
    await load();
    noNav();
    clickOn('out');
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'ev')).toBe(false);

    // queued like any other event: flushed by pagehide with sendBeacon
    cleanupDom();
    env = installDom({ html: LINKS, cfg: makeConfig({ auto: { outbound: true } }) });
    await load();
    advanceTime(1000);
    clickOn('out');
    expect(env.events().some((e) => e.n == 'outbound_link')).toBe(false);
    window.dispatchEvent(new Event('pagehide'));
    const last = env.sent().pop()!;
    expect(last.via).toBe('beacon');
    expect(last.body.e!.some((e) => e.n == 'outbound_link')).toBe(true);
  });
});
