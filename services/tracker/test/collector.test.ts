import { describe, expect, it } from 'vitest';
import { advanceTime, cleanupDom, api, installDom, load, makeConfig } from './helpers';

const hide = (): void => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
  document.dispatchEvent(new Event('visibilitychange'));
};
const unhide = (): void => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
  document.dispatchEvent(new Event('visibilitychange'));
};

describe('pageviews and events', () => {
  it('sends the initial pageview without fragment, with referrer and content key from meta', async () => {
    const env = installDom();
    const meta = document.createElement('meta');
    meta.name = 'analytics:content';
    meta.content = 'author:42';
    document.head.append(meta);
    await load();
    advanceTime(1000);
    const pv = env.events().find((e) => e.t == 'pv')!;
    expect(pv).toMatchObject({
      u: 'https://www.example.com/page?utm_source=x',
      r: 'https://search.example.org/?q=x',
      ck: 'author:42',
      a: 1000,
    });
    expect(pv.id).toMatch(/^[\w-]{16}$/);
  });

  it('keeps the fragment with hash routing', async () => {
    const env = installDom({ cfg: makeConfig({ hr: true }) });
    await load();
    advanceTime(1000);
    expect(env.events().find((e) => e.t == 'pv')!.u).toBe('https://www.example.com/page?utm_source=x#frag');
  });

  it('truncates long URLs to 2048 characters', async () => {
    const env = installDom({ url: 'https://www.example.com/' + 'a'.repeat(3000), referrer: 'https://r.example.org/' + 'b'.repeat(3000) });
    await load();
    advanceTime(1000);
    const pv = env.events().find((e) => e.t == 'pv')!;
    expect((pv.u as string).length).toBe(2048);
    expect((pv.r as string).length).toBe(2048);
  });

  it('pageview() accepts an explicit relative or absolute url', async () => {
    const env = installDom();
    await load();
    api().pageview({ url: '/virtual?x=1' });
    api().pageview({});
    advanceTime(1000);
    const pvs = env.events().filter((e) => e.t == 'pv');
    expect(pvs.map((e) => e.u)).toEqual([
      'https://www.example.com/page?utm_source=x',
      'https://www.example.com/virtual?x=1',
      'https://www.example.com/page?utm_source=x',
    ]);
    expect(pvs[1].r).toBe('https://www.example.com/page?utm_source=x');
  });

  it('validates event names and filters props', async () => {
    const env = installDom();
    await load();
    api().track('Bad Name');
    api().track('');
    api().track('x'.repeat(65));
    const props: Record<string, unknown> = {
      s: 'y'.repeat(150),
      n: 4.5,
      b: false,
      nan: NaN,
      inf: Infinity,
      obj: { a: 1 },
      nul: null,
      ['k'.repeat(33)]: 'long key',
      'a"b': 'quote',
      'caf\u00e9': 'accent',
      'a b': 'space',
      'Plan.2:x-y_z': 'allowed',
    };
    for (let i = 0; i < 12; i++) props['p' + i] = i;
    api().track('signup_click:v1.2-a', props);
    api().track('no_props');
    advanceTime(1000);
    const ev = env.events().filter((e) => e.t == 'ev');
    expect(ev).toHaveLength(2);
    const p = ev[0].p as Record<string, unknown>;
    expect(ev[0].n).toBe('signup_click:v1.2-a');
    expect(Object.keys(p)).toHaveLength(10);
    expect(p.s).toBe('y'.repeat(100));
    expect(p.n).toBe(4.5);
    expect(p.b).toBe(false);
    expect(p).not.toHaveProperty('nan');
    expect(p).not.toHaveProperty('obj');
    expect(p).not.toHaveProperty('k'.repeat(33));
    // A key the collector would refuse costs the whole event server side, so the key is dropped here.
    expect(p).not.toHaveProperty('a"b');
    expect(p).not.toHaveProperty('caf\u00e9');
    expect(p).not.toHaveProperty('a b');
    expect(p['Plan.2:x-y_z']).toBe('allowed');
    expect(ev[1].p).toBeUndefined();
  });

  it('setContent overrides the meta tag, null falls back to it', async () => {
    const env = installDom();
    await load();
    api().track('a');
    api().setContent('author:' + '9'.repeat(200));
    api().track('b');
    api().setContent(null);
    api().track('c');
    advanceTime(1000);
    const ev = env.events().filter((e) => e.t == 'ev');
    expect(ev[0].ck).toBeUndefined();
    expect((ev[1].ck as string).length).toBe(128);
    expect(ev[2].ck).toBeUndefined();
  });

  it('sends nothing on excluded paths, including SPA navigations to them', async () => {
    const env = installDom({ url: 'https://www.example.com/admin/users', cfg: makeConfig({ xp: ['/admin/*', '/a.b(c)'] }) });
    await load();
    api().track('x');
    history.pushState({}, '', '/a.b(c)');
    api().track('y');
    history.pushState({}, '', '/aXb(c)');
    advanceTime(1000);
    const ev = env.events();
    expect(ev.map((e) => e.t)).toEqual(['pv']);
    expect(ev[0].u).toBe('https://www.example.com/aXb(c)');
  });

  it('with base tracking disabled sends only consent statistics before consent', async () => {
    const env = installDom({ cfg: makeConfig({ b: false }) });
    await load();
    api().track('x');
    advanceTime(1000);
    expect(env.events().map((e) => e.t)).toEqual(['cs']);
    api().consent.set('accepted');
    api().track('y');
    advanceTime(1000);
    expect(env.events().map((e) => [e.t, e._l])).toEqual([
      ['cs', 'b'],
      ['cs', 'b'],
      ['cu', 'c'],
      ['ev', 'c'],
    ]);
  });
});

describe('engagement', () => {
  it('counts visible time and max scroll depth, sent on pagehide', async () => {
    const env = installDom();
    Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => 4000 });
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 1000 });
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 0, writable: true });
    await load();
    advanceTime(5000);
    (window as unknown as { scrollY: number }).scrollY = 2200;
    window.dispatchEvent(new Event('scroll'));
    (window as unknown as { scrollY: number }).scrollY = 100;
    window.dispatchEvent(new Event('scroll'));
    hide();
    advanceTime(60000);
    unhide();
    advanceTime(2000);
    window.dispatchEvent(new Event('pagehide'));
    const en = env.events().find((e) => e.t == 'en')!;
    expect(en).toMatchObject({ u: 'https://www.example.com/page?utm_source=x', ms: 7000, sp: 80, _l: 'b' });
    delete (document.documentElement as unknown as Record<string, unknown>).scrollHeight;
  });

  it('reports 100% scroll for an empty document and skips en without visible time', async () => {
    let env = installDom();
    Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => 0 });
    await load();
    advanceTime(10);
    window.dispatchEvent(new Event('pagehide'));
    expect(env.events().find((e) => e.t == 'en')!.sp).toBe(100);
    delete (document.documentElement as unknown as Record<string, unknown>).scrollHeight;

    cleanupDom();
    env = installDom({ visibility: 'hidden' });
    await load();
    advanceTime(5000);
    hide();
    window.dispatchEvent(new Event('pagehide'));
    expect(env.events().filter((e) => e.t == 'en')).toEqual([]);
  });
});
