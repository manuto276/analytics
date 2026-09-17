import { describe, expect, it } from 'vitest';
import {
  advanceTime,
  api,
  bannerHost,
  cleanupDom as cleanup,
  consentCookie,
  dialogEl,
  installDom,
  load,
  makeConfig,
  setCookie,
  today,
} from './helpers';

describe('skips', () => {
  it('sends nothing and shows no banner under navigator.webdriver', async () => {
    const env = installDom({ webdriver: true });
    await load();
    api().track('x');
    api().consent.set('accepted');
    advanceTime(1000);
    window.dispatchEvent(new Event('pagehide'));
    expect(env.sent()).toEqual([]);
    expect(bannerHost()).toBeNull();
    expect(env.jar.writes).toEqual([]);
  });

  it.each(['http://localhost:3000/', 'http://127.0.0.1/', 'http://app.localhost/', 'http://[::1]:8000/', 'file:///tmp/index.html'])(
    'skips local page %s unless loc is enabled',
    async (url) => {
      const env = installDom({ url });
      await load();
      advanceTime(1000);
      expect(env.sent()).toEqual([]);
      expect(bannerHost()).toBeNull();
    },
  );

  it('tracks localhost when loc is enabled', async () => {
    const env = installDom({ url: 'http://localhost:3000/', cfg: makeConfig({ loc: true }) });
    await load();
    advanceTime(1000);
    expect(env.sent()).toHaveLength(1);
    expect(dialogEl()).not.toBeNull();
  });

  it('DNT no_tracking with DNT=1 sends nothing', async () => {
    const env = installDom({ dnt: '1', cfg: makeConfig({ dnt: 'no_tracking' }) });
    await load();
    advanceTime(1000);
    expect(env.sent()).toEqual([]);
    expect(bannerHost()).toBeNull();
  });

  it('DNT no_cookie with DNT=1 behaves like reject', async () => {
    const env = installDom({ dnt: '1', cfg: makeConfig({ dnt: 'no_cookie' }) });
    await load();
    expect(bannerHost()).toBeNull();
    expect(api().consent.get().status).toBe('rejected');
    api().consent.set('accepted');
    api().consent.open();
    expect(bannerHost()).toBeNull();
    advanceTime(1000);
    expect(env.events().map((e) => [e.t, e._l])).toEqual([['pv', 'b']]);
    expect(env.jar.writes).toEqual([]);
  });

  it('DNT is ignored in ignore mode and without DNT=1', async () => {
    installDom({ dnt: '1', cfg: makeConfig({ dnt: 'ignore' }) });
    await load();
    expect(dialogEl()).not.toBeNull();
    cleanup();
    installDom({ dnt: '0', cfg: makeConfig({ dnt: 'no_tracking' }) });
    await load();
    expect(dialogEl()).not.toBeNull();
  });

  it('GPC means reject: no banner, base only, stored ids removed', async () => {
    const env = installDom({ gpc: true });
    setCookie(env, consentCookie(3, 'a', today()));
    setCookie(env, 'an_vid=AAAAAAAAAAAAAAAAAAAAAA; Domain=example.com');
    await load();
    expect(bannerHost()).toBeNull();
    expect(api().consent.get().status).toBe('rejected');
    expect(api().getVisitorId()).toBeNull();
    expect(env.jar.get('an_vid')).toBeUndefined();
    advanceTime(1000);
    expect(env.sent().every((s) => s.body.l == 'b' && !s.body.vid)).toBe(true);
  });

  it('GPC does not write anything when there is nothing to delete', async () => {
    const env = installDom({ gpc: true });
    await load();
    expect(env.jar.writes).toEqual([]);
  });

  it('GPC is ignored when the site does not respect it', async () => {
    installDom({ gpc: true, cfg: makeConfig({ gpc: false }) });
    await load();
    expect(dialogEl()).not.toBeNull();
  });

  it('waits for prerender activation', async () => {
    const env = installDom({ prerendering: true });
    await load();
    advanceTime(5000);
    expect(api()).toBeUndefined();
    expect(env.sent()).toEqual([]);
    document.dispatchEvent(new Event('prerenderingchange'));
    expect(api()).toBeDefined();
    advanceTime(1000);
    expect(env.events().some((e) => e.t == 'pv')).toBe(true);
  });

  it('does nothing without a configuration or key', async () => {
    const env = installDom({ cfg: null });
    await load();
    expect(api()).toBeUndefined();
    advanceTime(1000);
    expect(env.sent()).toEqual([]);
    cleanup();
    installDom({ cfg: { ...makeConfig(), k: '' } });
    await load();
    expect(api()).toBeUndefined();
  });
});
