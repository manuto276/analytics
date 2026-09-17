import { describe, expect, it, vi } from 'vitest';
import {
  advanceTime,
  cleanupDom,
  api,
  bannerHost,
  click,
  consentCookie,
  DAY_MS,
  dialogEl,
  installDom,
  KEY,
  load,
  makeConfig,
  makeConsent,
  setCookie,
  today,
} from './helpers';

const VID = 'AAAAAAAAAAAAAAAAAAAAAA';
const SID = 'BBBBBBBBBBBBBBBBBBBBBB';

describe('consent state machine', () => {
  it('starts unknown without a cookie: banner shown, cs shown sent at base level', async () => {
    const env = installDom();
    await load();
    expect(api().consent.get()).toEqual({ status: 'unknown', version: null, decidedAt: null });
    expect(dialogEl()).not.toBeNull();
    advanceTime(1000);
    const ev = env.events();
    expect(ev.find((e) => e.t == 'cs')).toMatchObject({ cs: 'shown', _l: 'b' });
    expect(ev.find((e) => e.t == 'pv')).toMatchObject({ _l: 'b' });
    expect(env.sent()[0].body).toMatchObject({ v: 1, k: KEY, l: 'b', cv: 0, sw: 1024 });
  });

  it.each(['garbage', '1.3.x.abc', '2.3.a.abc', '1.3.a.'])('treats malformed cookie %s as unknown', async (v) => {
    const env = installDom();
    setCookie(env, 'an_consent=' + v);
    await load();
    expect(api().consent.get().status).toBe('unknown');
    expect(dialogEl()).not.toBeNull();
  });

  it('honours a valid accepted cookie: cookie level with ids, no banner', async () => {
    const env = installDom();
    setCookie(env, consentCookie(3, 'a', today() - 10));
    setCookie(env, 'an_vid=' + VID + '; Domain=example.com');
    setCookie(env, 'an_sid=' + SID + '; Domain=example.com');
    await load();
    expect(bannerHost()).toBeNull();
    expect(api().consent.get()).toEqual({ status: 'accepted', version: 3, decidedAt: (today() - 10) * DAY_MS });
    expect(api().getVisitorId()).toBe(VID);
    advanceTime(1000);
    const s = env.sent();
    expect(s).toHaveLength(1);
    expect(s[0].body).toMatchObject({ l: 'c', vid: VID, sid: SID, cv: 3 });
    // an_vid is never extended, an_sid slides
    expect(env.jar.writes.some((w) => w.startsWith('an_vid='))).toBe(false);
    expect(env.jar.writes.filter((w) => w.startsWith('an_sid=' + SID)).length).toBeGreaterThan(0);
    expect(env.jar.writes.find((w) => w.startsWith('an_sid='))).toContain('Max-Age=1800');
  });

  it('creates missing or invalid ids for an accepted visitor', async () => {
    const env = installDom();
    setCookie(env, consentCookie(3, 'a', today()));
    setCookie(env, 'an_sid=short; Domain=example.com');
    await load();
    const vid = api().getVisitorId();
    expect(vid).toMatch(/^[\w-]{22}$/);
    expect(env.jar.get('an_sid')!.value).toMatch(/^[\w-]{22}$/);
    advanceTime(1000);
    expect(env.sent()[0].body.vid).toBe(vid);
  });

  it('a newer published consent version resets the state to unknown', async () => {
    const env = installDom();
    setCookie(env, consentCookie(2, 'a', today()));
    await load();
    expect(api().consent.get().status).toBe('unknown');
    expect(dialogEl()).not.toBeNull();
    expect(api().getVisitorId()).toBeNull();
  });

  it('a cookie version above the published one is still valid', async () => {
    const env = installDom();
    setCookie(env, consentCookie(4, 'r', today()));
    await load();
    expect(api().consent.get()).toMatchObject({ status: 'rejected', version: 4 });
  });

  it('rejection is remembered for 180 days, then asked again', async () => {
    let env = installDom();
    setCookie(env, consentCookie(3, 'r', today() - 179));
    await load();
    expect(api().consent.get().status).toBe('rejected');
    expect(bannerHost()).toBeNull();

    cleanupDom();
    env = installDom();
    setCookie(env, consentCookie(3, 'r', today() - 180));
    await load();
    expect(api().consent.get().status).toBe('unknown');
    expect(dialogEl()).not.toBeNull();
  });

  it('accepted consent expires after the accepted TTL', async () => {
    const env = installDom({ cfg: makeConfig({ consent: makeConsent({ at: 30 }) }) });
    setCookie(env, consentCookie(3, 'a', today() - 30));
    await load();
    expect(api().consent.get().status).toBe('unknown');
  });

  it('accept writes consent + ids, sends cs accept and cu with landing data', async () => {
    const env = installDom({ referrer: 'https://news.example.org/a' });
    await load();
    const changes: unknown[] = [];
    api().consent.onChange((s) => changes.push(s));
    history.pushState({}, '', '/second');
    click(dialogEl()!.querySelector('[data-a]'));

    const c = env.jar.writes.find((w) => w.startsWith('an_consent='))!;
    expect(c).toBe(
      `an_consent=1.3.a.${today().toString(36)}; Domain=example.com; Path=/; Max-Age=${180 * 86400}; SameSite=Lax; Secure`,
    );
    expect(env.jar.writes.find((w) => w.startsWith('an_vid='))).toMatch(
      new RegExp(`^an_vid=[\\w-]{22}; Domain=example.com; Path=/; Max-Age=${395 * 86400}; SameSite=Lax; Secure$`),
    );
    expect(env.jar.get('an_sid')).toBeDefined();
    expect(bannerHost()).toBeNull();
    const state = { status: 'accepted', version: 3, decidedAt: today() * DAY_MS };
    expect(api().consent.get()).toEqual(state);
    expect(changes).toEqual([state]);

    advanceTime(1000);
    const ev = env.events();
    expect(ev.find((e) => e.t == 'cs' && e.cs == 'accept')).toMatchObject({ _l: 'b' });
    expect(ev.find((e) => e.t == 'cu')).toMatchObject({
      _l: 'c',
      u: 'https://www.example.com/second',
      lu: 'https://www.example.com/page?utm_source=x',
      lr: 'https://news.example.org/a',
    });
    const cBatch = env.sent().find((s) => s.body.l == 'c')!;
    expect(cBatch.body.vid).toBe(api().getVisitorId());
    expect(cBatch.body.cv).toBe(3);
    // cs is always sent without ids
    const bBatch = env.sent().find((s) => s.body.l == 'b' && s.body.e!.some((e) => e.t == 'cs'))!;
    expect(bBatch.body.vid).toBeUndefined();

    // accepting again does not send another cu
    api().consent.set('accepted');
    advanceTime(1000);
    expect(env.events().filter((e) => e.t == 'cu')).toHaveLength(1);
    expect(env.events().filter((e) => e.t == 'cs' && e.cs == 'accept')).toHaveLength(2);
  });

  it('cu omits lr when there was no referrer', async () => {
    const env = installDom({ referrer: '' });
    await load();
    api().consent.set('accepted');
    advanceTime(1000);
    const cu = env.events().find((e) => e.t == 'cu')!;
    expect(cu.lr).toBeUndefined();
    expect(env.events().find((e) => e.t == 'pv')!.r).toBeUndefined();
  });

  it('reject writes the rejected cookie and deletes ids on every candidate domain', async () => {
    const env = installDom({ url: 'https://a.www.example.com/' });
    setCookie(env, 'an_vid=' + VID);
    setCookie(env, 'an_vid=' + VID + '; Domain=example.com');
    setCookie(env, 'an_sid=' + SID + '; Domain=www.example.com');
    await load();
    click(dialogEl()!.querySelector('[data-r]'));
    expect(env.jar.get('an_consent')!.value).toBe('1.3.r.' + today().toString(36));
    expect(env.jar.get('an_vid')).toBeUndefined();
    expect(env.jar.get('an_sid')).toBeUndefined();
    for (const dom of ['', '; Domain=a.www.example.com', '; Domain=www.example.com', '; Domain=example.com'])
      expect(env.jar.writes).toContain(`an_vid=${dom}; Path=/; Max-Age=0; SameSite=Lax; Secure`);
    expect(api().consent.get().status).toBe('rejected');
    advanceTime(1000);
    expect(env.events().find((e) => e.t == 'cs' && e.cs == 'reject')).toBeDefined();
    expect(env.events().some((e) => e.t == 'cu')).toBe(false);
  });

  it('close button and Escape count as dismiss and reject', async () => {
    let env = installDom();
    await load();
    click(dialogEl()!.querySelector('[data-x]'));
    expect(api().consent.get().status).toBe('rejected');
    advanceTime(1000);
    expect(env.events().find((e) => e.t == 'cs' && e.cs == 'dismiss')).toBeDefined();

    cleanupDom();
    env = installDom();
    await load();
    dialogEl()!.querySelector('h2')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(api().consent.get().status).toBe('unknown');
    dialogEl()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(api().consent.get().status).toBe('rejected');
    expect(env.jar.get('an_consent')!.value).toMatch(/^1\.3\.r\./);
  });

  it('consent.set validates input and onChange can unsubscribe', async () => {
    installDom();
    await load();
    const cb = vi.fn();
    const off = api().consent.onChange(cb);
    api().consent.set('maybe');
    expect(api().consent.get().status).toBe('unknown');
    api().consent.set('rejected');
    expect(cb).toHaveBeenCalledTimes(1);
    off();
    api().consent.set('accepted');
    expect(cb).toHaveBeenCalledTimes(1);
    expect(api().consent.get().status).toBe('accepted');
  });

  it('forget sends /forget, deletes ids and rejects', async () => {
    const env = installDom();
    setCookie(env, consentCookie(3, 'a', today()));
    setCookie(env, 'an_vid=' + VID + '; Domain=example.com');
    await load();
    api().consent.forget();
    const f = env.sent().find((s) => s.url.endsWith('/forget'))!;
    expect(f).toMatchObject({ url: 'https://stats.example.net/t/forget', via: 'beacon', type: 'text/plain' });
    expect(f.body).toEqual({ k: KEY, vid: VID });
    expect(env.jar.get('an_vid')).toBeUndefined();
    expect(api().consent.get().status).toBe('rejected');
    expect(api().getVisitorId()).toBeNull();
  });

  it('forget without a visitor id does not call /forget', async () => {
    const env = installDom();
    await load();
    api().consent.forget();
    expect(env.sent().some((s) => s.url.endsWith('/forget'))).toBe(false);
    expect(api().consent.get().status).toBe('rejected');
  });

  it('drops queued cookie-level events when consent is withdrawn before the flush', async () => {
    const env = installDom();
    setCookie(env, consentCookie(3, 'a', today()));
    await load();
    api().track('clicked');
    api().consent.set('rejected');
    advanceTime(1000);
    expect(env.sent().every((s) => s.body.l != 'c')).toBe(true);
  });

  it('caps an_vid lifetime at 395 days and defaults it when missing', async () => {
    let env = installDom({ cfg: makeConfig({ vd: 800 }) });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.find((w) => w.startsWith('an_vid='))).toContain(`Max-Age=${395 * 86400};`);

    cleanupDom();
    env = installDom({ cfg: makeConfig({ vd: 30 }) });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.find((w) => w.startsWith('an_vid='))).toContain(`Max-Age=${30 * 86400};`);

    cleanupDom();
    env = installDom({ cfg: { ...makeConfig(), vd: undefined } });
    await load();
    api().consent.set('accepted');
    expect(env.jar.writes.find((w) => w.startsWith('an_vid='))).toContain(`Max-Age=${395 * 86400};`);
  });

  it('cookie level disabled: no banner, API choices are no-ops', async () => {
    let env = installDom({ cfg: makeConfig({ c: false }) });
    await load();
    expect(bannerHost()).toBeNull();
    api().consent.set('accepted');
    api().consent.open();
    expect(api().consent.get().status).toBe('unknown');
    expect(env.jar.writes).toEqual([]);

    cleanupDom();
    env = installDom({ cfg: makeConfig({ consent: null }) });
    setCookie(env, consentCookie(3, 'a', today()));
    await load();
    expect(bannerHost()).toBeNull();
    expect(api().consent.get().status).toBe('unknown');
    advanceTime(1000);
    expect(env.sent()[0].body.l).toBe('b');
  });
});
