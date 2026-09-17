import { describe, expect, it } from 'vitest';
import { advanceTime, cleanupDom, api, installDom, load, makeConfig } from './helpers';

const flushPromises = async (): Promise<void> => {
  for (let i = 0; i < 10; i++) await Promise.resolve();
};

describe('transport', () => {
  it('sends batches with sendBeacon as a text/plain blob to {base}e', async () => {
    const env = installDom();
    await load();
    advanceTime(1000);
    const s = env.sent();
    expect(s).toHaveLength(1);
    expect(s[0]).toMatchObject({ url: 'https://stats.example.net/t/e', via: 'beacon', type: 'text/plain' });
    expect(env.fetch).not.toHaveBeenCalled();
  });

  it.each([
    ['https://stats.example.net/t/pk_ABCDEFGHIJKLMNOPQRSTU.js', 'https://stats.example.net/t/e'],
    ['/stats/pk_ABCDEFGHIJKLMNOPQRSTU.js', 'https://www.example.com/stats/e'],
  ])('derives the endpoint from the script src %s', async (src, expected) => {
    const env = installDom({ cfg: makeConfig({ ep: null }) });
    const script = document.createElement('script');
    script.setAttribute('src', src);
    Object.defineProperty(document, 'currentScript', { configurable: true, get: () => script });
    try {
      await load();
      advanceTime(1000);
      expect(env.sent()[0].url).toBe(expected);
    } finally {
      delete (document as unknown as Record<string, unknown>).currentScript;
    }
  });

  it('uses a relative endpoint when there is no script and no ep', async () => {
    const env = installDom({ cfg: makeConfig({ ep: null }) });
    await load();
    advanceTime(1000);
    expect(env.beacon.mock.calls[0][0]).toBe('e');
  });

  it('falls back to fetch keepalive when sendBeacon refuses', async () => {
    const env = installDom({ beacon: false });
    await load();
    advanceTime(1000);
    expect(env.fetch).toHaveBeenCalledTimes(1);
    const [url, init] = env.fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://stats.example.net/t/e');
    expect(init).toMatchObject({
      method: 'POST',
      keepalive: true,
      credentials: 'omit',
      headers: { 'Content-Type': 'text/plain' },
    });
    expect(env.sent().find((s) => s.via == 'fetch')!.body.e).toHaveLength(2);
    await flushPromises();
    expect(env.fetch).toHaveBeenCalledTimes(1);
  });

  it('falls back to fetch when sendBeacon is missing', async () => {
    const env = installDom({ beacon: 'missing' });
    await load();
    advanceTime(1000);
    expect(env.fetch).toHaveBeenCalledTimes(1);
  });

  it('retries once on a network error', async () => {
    const env = installDom({ beacon: false, fetchStatus: 'error' });
    await load();
    advanceTime(1000);
    await flushPromises();
    expect(env.fetch).toHaveBeenCalledTimes(2);
    expect(env.fetch.mock.calls[1]).toEqual(env.fetch.mock.calls[0]);
  });

  it('retries once on a 5xx and not on a 4xx', async () => {
    let env = installDom({ beacon: false, fetchStatus: (i) => (i == 0 ? 503 : 202) });
    await load();
    advanceTime(1000);
    await flushPromises();
    expect(env.fetch).toHaveBeenCalledTimes(2);

    cleanupDom();
    env = installDom({ beacon: false, fetchStatus: 400 });
    await load();
    advanceTime(1000);
    await flushPromises();
    expect(env.fetch).toHaveBeenCalledTimes(1);
  });
});

describe('queue and flush', () => {
  it('flushes after 1 s idle, restarting the timer on each event', async () => {
    const env = installDom();
    await load();
    advanceTime(900);
    api().track('a');
    advanceTime(900);
    expect(env.beacon).not.toHaveBeenCalled();
    advanceTime(100);
    expect(env.beacon).toHaveBeenCalledTimes(1);
    const ev = env.events();
    expect(ev.map((e) => e.t)).toEqual(['cs', 'pv', 'ev']);
    expect(ev[0].a).toBe(1900);
    expect(ev[2].a).toBe(1000);
  });

  it('flushes immediately at 10 queued events', async () => {
    const env = installDom();
    await load();
    for (let i = 0; i < 7; i++) api().track('e' + i);
    expect(env.beacon).not.toHaveBeenCalled();
    api().track('e7');
    expect(env.beacon).toHaveBeenCalledTimes(1);
    expect(env.sent()[0].body.e).toHaveLength(10);
    advanceTime(5000);
    expect(env.beacon).toHaveBeenCalledTimes(1);
  });

  it('flushes on visibilitychange=hidden and on pagehide', async () => {
    const env = installDom();
    await load();
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
    expect(env.beacon).toHaveBeenCalledTimes(1);
    api().track('x');
    window.dispatchEvent(new Event('pagehide'));
    expect(env.beacon).toHaveBeenCalledTimes(2);
  });

  it('never sends an empty batch', async () => {
    const env = installDom({ cfg: makeConfig({ c: false }) });
    await load();
    advanceTime(1000);
    window.dispatchEvent(new Event('pagehide'));
    window.dispatchEvent(new Event('pagehide'));
    expect(env.sent().every((s) => (s.body.e as unknown[]).length > 0)).toBe(true);
  });

  it('splits base and cookie level events into separate requests', async () => {
    const env = installDom();
    await load();
    api().consent.set('accepted');
    api().track('after');
    advanceTime(1000);
    const s = env.sent();
    expect(s.map((x) => x.body.l)).toEqual(['b', 'c']);
    expect(s[1].body.e!.map((e) => e.t)).toEqual(['cu', 'ev']);
  });
});
