import { describe, expect, it } from 'vitest';
import { advanceTime, cleanupDom, api, installDom, load, makeConfig } from './helpers';

describe('SPA navigation', () => {
  it('tracks pushState/replaceState/popstate, sending en for the previous page and skipping duplicates', async () => {
    const env = installDom({ html: '<input data-analytics-visitor>' });
    await load();
    api().consent.set('accepted');
    advanceTime(3000);
    history.pushState({ a: 1 }, '', '/two');
    expect(location.pathname).toBe('/two');
    history.replaceState({ a: 2 }, '', '/two');
    history.pushState({}, '', '/two#section');
    advanceTime(1000);
    history.replaceState({}, '', '/three');
    history.back();
    window.dispatchEvent(new Event('popstate'));
    advanceTime(1000);
    const ev = env.events().filter((e) => e.t == 'pv' || e.t == 'en');
    expect(ev.map((e) => [e.t, e.u, e.r])).toEqual([
      ['pv', 'https://www.example.com/page?utm_source=x', 'https://search.example.org/?q=x'],
      ['en', 'https://www.example.com/page?utm_source=x', undefined],
      ['pv', 'https://www.example.com/two', 'https://www.example.com/page?utm_source=x'],
      ['en', 'https://www.example.com/two', undefined],
      ['pv', 'https://www.example.com/three', 'https://www.example.com/two'],
      ['pv', 'https://www.example.com/two', 'https://www.example.com/three'],
    ]);
    expect(ev[1].ms).toBe(3000);
    expect(ev[3].ms).toBe(1000);
    expect(ev.slice(1).every((e) => e._l == 'c')).toBe(true);
    expect((document.querySelector('input') as HTMLInputElement).value).toBe(api().getVisitorId());
  });

  it('listens to hashchange with hash routing only', async () => {
    let env = installDom({ cfg: makeConfig({ hr: true }) });
    await load();
    location.hash = '#/route';
    window.dispatchEvent(new Event('hashchange'));
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'pv').map((e) => e.u)).toEqual([
      'https://www.example.com/page?utm_source=x#frag',
      'https://www.example.com/page?utm_source=x#/route',
    ]);

    cleanupDom();
    env = installDom();
    await load();
    location.hash = '#/route';
    window.dispatchEvent(new Event('hashchange'));
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'pv')).toHaveLength(1);
  });
});
