import { describe, expect, it, vi } from 'vitest';
import { advanceTime, api, cleanupDom, dialogEl, installDom, load, makeConfig } from './helpers';

type Stub = { q: unknown[][]; track(...a: unknown[]): void };
const stub = (): Stub => ({
  q: [],
  track(...a: unknown[]) {
    this.q.push(['track', ...a]);
  },
});

describe('global API', () => {
  it('replays the stub queue in order, including dotted methods, ignoring unknown ones', async () => {
    const env = installDom();
    const s = stub();
    s.track('early', { a: 1 });
    s.q.push(['setContent', 'author:7'], ['nope'], ['consent.nope'], [42], ['consent.set', 'accepted']);
    s.track('later');
    (window as unknown as Record<string, unknown>).analytics = s;
    await load();
    expect(api().__an).toBe(1);
    expect(api().consent.get().status).toBe('accepted');
    advanceTime(1000);
    const ev = env.events();
    expect(ev.filter((e) => e.t == 'ev').map((e) => [e.n, e.ck, e._l])).toEqual([
      ['early', undefined, 'b'],
      ['later', 'author:7', 'c'],
    ]);
    expect(ev.find((e) => e.t == 'pv')).toMatchObject({ ck: 'author:7', _l: 'c' });
  });

  it('uses the configured global name', async () => {
    installDom({ cfg: makeConfig({ g: 'stats' }) });
    await load();
    expect(api('stats').track).toBeTypeOf('function');
    expect(api()).toBeUndefined();
  });

  it('defaults the global name to analytics', async () => {
    installDom({ cfg: { ...makeConfig(), g: undefined } });
    await load();
    expect(api().track).toBeTypeOf('function');
  });

  it('falls back to __analytics when the name is taken by something else', async () => {
    const env = installDom();
    const other = { mine: true };
    (window as unknown as Record<string, unknown>).analytics = other;
    await load();
    expect((window as unknown as Record<string, unknown>).analytics).toBe(other);
    api('__analytics').track('works');
    advanceTime(1000);
    expect(env.events().some((e) => e.n == 'works')).toBe(true);
  });

  it('replays a stub placed at __analytics after a collision', async () => {
    const env = installDom();
    (window as unknown as Record<string, unknown>).analytics = () => 0;
    const s = stub();
    s.track('queued');
    (window as unknown as Record<string, unknown>).__analytics = s;
    await load();
    advanceTime(1000);
    expect(env.events().some((e) => e.n == 'queued')).toBe(true);
  });

  it('does not initialise twice', async () => {
    const env = installDom();
    await load();
    const first = api();
    await load();
    expect(api()).toBe(first);
    expect(document.querySelectorAll('[data-analytics-banner]')).toHaveLength(1);
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'pv')).toHaveLength(1);

    cleanupDom();
    installDom();
    (window as unknown as Record<string, unknown>).analytics = { other: 1 };
    await load();
    const fallback = api('__analytics');
    await load();
    expect(api('__analytics')).toBe(fallback);
  });

  it('getVisitorId returns the id only with consent', async () => {
    installDom();
    await load();
    expect(api().getVisitorId()).toBeNull();
    api().consent.set('accepted');
    expect(api().getVisitorId()).toMatch(/^[\w-]{22}$/);
  });
});

describe('declarative attributes', () => {
  it('[data-analytics-event] clicks send events with data-analytics-prop-* props', async () => {
    const env = installDom({
      html: '<a href="#" data-analytics-event="cta_click" data-analytics-prop-plan="pro" data-analytics-prop-billing-cycle="year" data-other="x"><span id="inner">Go</span></a>',
    });
    await load();
    document.getElementById('inner')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    advanceTime(1000);
    expect(env.events().find((e) => e.t == 'ev')).toMatchObject({
      n: 'cta_click',
      p: { plan: 'pro', 'billing-cycle': 'year' },
    });
  });

  it('[data-analytics-consent] and #analytics-consent links reopen preferences', async () => {
    const env = installDom({
      html: '<button data-analytics-consent>Prefs</button><a id="l" href="#analytics-consent">Cookies</a>',
    });
    await load();
    api().consent.set('rejected');
    expect(dialogEl()).toBeNull();
    const ev = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector('button')!.dispatchEvent(ev);
    expect(ev.defaultPrevented).toBe(true);
    expect(dialogEl()).not.toBeNull();
    api().consent.set('rejected');
    document.getElementById('l')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(dialogEl()).not.toBeNull();
    advanceTime(1000);
    expect(env.events().filter((e) => e.cs == 'reopen')).toHaveLength(2);
  });

  it('ignores clicks whose target is not an element', async () => {
    const env = installDom();
    await load();
    const spy = vi.fn();
    document.addEventListener('click', spy);
    document.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(spy).toHaveBeenCalled();
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'ev')).toBe(false);
  });

  it('fills input[data-analytics-visitor] after consent and clears it on reject', async () => {
    installDom({ html: '<form><input type="hidden" name="vid" data-analytics-visitor></form>' });
    await load();
    const input = document.querySelector('input')!;
    expect(input.value).toBe('');
    api().consent.set('accepted');
    expect(input.value).toMatch(/^[\w-]{22}$/);
    api().consent.set('rejected');
    expect(input.value).toBe('');
  });
});
