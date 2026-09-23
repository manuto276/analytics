import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { load, scriptUrl } from '../src/index';
import type { LoadOptions } from '../src/index';
import { holdScripts, runFakeTracker } from './fake-tracker';

const PK = 'pk_ABCDEFGHIJKLMNOPQRSTU';
const OPTS: LoadOptions = { serviceUrl: 'https://stats.example.net', publicKey: PK };
const w = window as unknown as Record<string, unknown>;
const scripts = (): HTMLScriptElement[] => Array.from(document.querySelectorAll('script'));

let net: ReturnType<typeof holdScripts>;
beforeEach(() => {
  net = holdScripts();
});
afterEach(() => {
  net.release();
});

describe('scriptUrl', () => {
  it('points at the service', () => {
    expect(scriptUrl(OPTS)).toBe(`https://stats.example.net/t/${PK}.js`);
    expect(scriptUrl({ ...OPTS, serviceUrl: 'https://example.net/analytics//' })).toBe(
      `https://example.net/analytics/t/${PK}.js`,
    );
  });

  it('points at the proxy path on the site when one is set', () => {
    expect(scriptUrl({ publicKey: PK, proxyPath: '/stats/' })).toBe(`/stats/${PK}.js`);
    expect(scriptUrl({ ...OPTS, proxyPath: 'a//b' })).toBe(`/a/b/${PK}.js`);
    expect(scriptUrl({ ...OPTS, proxyPath: '' })).toBe(`https://stats.example.net/t/${PK}.js`);
  });

  it.each([
    [{ ...OPTS, publicKey: 'pk_short' }, /publicKey/],
    [{ ...OPTS, globalName: 'not-an-id' }, /globalName/],
    [{ publicKey: PK, serviceUrl: 'stats.example.net' }, /serviceUrl/],
    [{ publicKey: PK, serviceUrl: 'javascript:alert(1)' }, /serviceUrl/],
    [{ publicKey: PK, proxyPath: '/../x?y' }, /proxyPath/],
    [{ publicKey: PK, proxyPath: '///' }, /proxyPath/],
  ] as [LoadOptions, RegExp][])('rejects %o', (o, msg) => {
    expect(() => scriptUrl(o)).toThrow(msg);
    expect(() => load(o)).toThrow(TypeError);
  });
});

describe('load', () => {
  it('inserts the stub and one deferred script, however often it is called', () => {
    const a = load(OPTS);
    const b = load(OPTS);
    load({ ...OPTS, proxyPath: '/stats/' });
    expect(b).toBe(a);
    expect(scripts()).toHaveLength(1);
    const s = scripts()[0];
    expect(s.src).toBe(`https://stats.example.net/t/${PK}.js`);
    expect(s.defer).toBe(true);
    expect(s.hasAttribute('data-analytics-sdk')).toBe(true);
    expect(s.parentNode).toBe(document.head);
    expect(Array.isArray((w.analytics as { q: unknown[] }).q)).toBe(true);
    expect(a.globalName).toBe('analytics');
  });

  it('sets the CSP nonce', () => {
    load({ ...OPTS, nonce: 'abc123' });
    expect(scripts()[0].nonce).toBe('abc123');
  });

  it('loads the proxy path on the site origin', () => {
    load({ publicKey: PK, proxyPath: '/stats/' });
    expect(scripts()[0].getAttribute('src')).toBe(`/stats/${PK}.js`);
  });

  it('does not insert a second script when the page already has the snippet', async () => {
    const snippet = document.createElement('script');
    snippet.src = `https://stats.example.net/t/${PK}.js`;
    document.head.appendChild(snippet);
    const client = load(OPTS);
    expect(scripts()).toHaveLength(1);
    client.track('queued');
    net.respond(() => runFakeTracker());
    await expect(client.ready).resolves.toBe(true);
  });

  it('settles on the page load when the existing snippet ran already', async () => {
    const snippet = document.createElement('script');
    snippet.src = `https://stats.example.net/t/${PK}.js`;
    document.head.appendChild(snippet);
    net.pending.length = 0; // its load event went by before load() was called
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('complete');
    vi.spyOn(performance, 'getEntriesByName').mockReturnValue([{} as PerformanceEntry]);
    const client = load(OPTS);
    state.mockRestore();
    await expect(client.ready).resolves.toBe(false);
  });

  it('waits for an existing script that is still on its way after the page load', async () => {
    const snippet = document.createElement('script');
    snippet.src = `https://stats.example.net/t/${PK}.js`;
    document.head.appendChild(snippet);
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('complete');
    vi.spyOn(performance, 'getEntriesByName').mockReturnValue([]);
    const client = load(OPTS);
    state.mockRestore();
    net.respond(() => runFakeTracker());
    await expect(client.ready).resolves.toBe(true);
  });

  it('settles with false when an existing script fails', async () => {
    const snippet = document.createElement('script');
    snippet.src = `https://stats.example.net/t/${PK}.js`;
    document.head.appendChild(snippet);
    const client = load(OPTS);
    net.fail();
    await expect(client.ready).resolves.toBe(false);
  });

  it('waits for the window load when the document is still loading', async () => {
    const snippet = document.createElement('script');
    snippet.src = `https://stats.example.net/t/${PK}.js`;
    document.head.appendChild(snippet);
    net.pending.length = 0;
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    const client = load(OPTS);
    state.mockRestore();
    runFakeTracker();
    window.dispatchEvent(new Event('load'));
    await expect(client.ready).resolves.toBe(true);
  });

  it('uses the tracker directly when it is already installed', async () => {
    const fake = runFakeTracker();
    const client = load(OPTS);
    expect(scripts()).toHaveLength(0);
    await expect(client.ready).resolves.toBe(true);
    client.track('now', { a: 1 });
    expect(fake.calls).toContainEqual(['track', 'now', { a: 1 }]);
  });

  it('queues calls made before the tracker loads and the tracker replays them in order', async () => {
    const client = load(OPTS);
    client.track('early', { plan: 'pro' });
    client.pageview({ url: '/virtual' });
    client.setContent('author:7');
    client.consent.open();
    client.consent.set('accepted');
    expect(client.consent.get()).toEqual({ status: 'unknown', version: null, decidedAt: null });
    expect(client.getVisitorId()).toBeNull();

    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await expect(client.ready).resolves.toBe(true);
    expect(fake.installedAt).toBe('analytics');
    expect(fake.calls).toEqual([
      ['consent.onChange'],
      ['track', 'early', { plan: 'pro' }],
      ['pageview', { url: '/virtual' }],
      ['setContent', 'author:7'],
      ['consent.open'],
      ['consent.set', 'accepted'],
    ]);
    expect(client.consent.get().status).toBe('accepted');
    expect(client.getVisitorId()).toBe('AAAAAAAAAAAAAAAAAAAAAA');

    client.track('later');
    client.consent.forget();
    expect(fake.calls.slice(-2)).toEqual([['track', 'later'], ['consent.forget']]);
    expect(client.consent.get().status).toBe('rejected');
  });

  it('keeps calls queued in a snippet stub that was there first', async () => {
    const stub = {
      q: [] as unknown[][],
      track(...a: unknown[]) {
        this.q.push(['track', ...a]);
      },
    };
    w.analytics = stub;
    stub.track('from-snippet');
    const client = load(OPTS);
    expect(w.analytics).toBe(stub);
    client.track('from-sdk');
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await client.ready;
    expect(fake.calls.filter((c) => c[0] == 'track')).toEqual([
      ['track', 'from-snippet'],
      ['track', 'from-sdk'],
    ]);
  });

  it('gives other scripts a full stub to queue on', async () => {
    load(OPTS);
    const stub = w.analytics as {
      track: (...a: unknown[]) => void;
      pageview: (...a: unknown[]) => void;
      setContent: (...a: unknown[]) => void;
      consent: Record<string, (...a: unknown[]) => void>;
    };
    stub.track('x');
    stub.pageview();
    stub.setContent(null);
    stub.consent.open();
    stub.consent.set('rejected');
    stub.consent.onChange(() => undefined);
    stub.consent.forget();
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    expect(fake.calls.map((c) => c[0])).toEqual([
      'consent.onChange',
      'track',
      'pageview',
      'setContent',
      'consent.open',
      'consent.set',
      'consent.onChange',
      'consent.forget',
    ]);
  });

  it('recreates the stub if something removed it before the tracker loaded', () => {
    const client = load(OPTS);
    delete w.analytics;
    client.track('again');
    expect((w.analytics as { q: unknown[][] }).q).toEqual([['track', 'again']]);
  });

  it('follows the tracker to __analytics when the global name is taken', async () => {
    const other = { mine: true };
    w.analytics = other;
    const client = load(OPTS);
    expect(client.globalName).toBe('__analytics');
    expect(w.analytics).toBe(other);
    client.track('queued');
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await expect(client.ready).resolves.toBe(true);
    expect(fake.installedAt).toBe('__analytics');
    expect(fake.calls).toContainEqual(['track', 'queued']);
    client.track('direct');
    expect(fake.calls).toContainEqual(['track', 'direct']);
  });

  it('uses a custom global name', async () => {
    const client = load({ ...OPTS, globalName: 'stats' });
    expect(client.globalName).toBe('stats');
    expect(w.analytics).toBeUndefined();
    client.track('custom');
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker({ g: 'stats' })));
    await expect(client.ready).resolves.toBe(true);
    expect(fake.calls).toContainEqual(['track', 'custom']);
    expect(load({ ...OPTS, globalName: 'stats' })).toBe(client);
    expect(load(OPTS)).not.toBe(client);
  });

  it('resolves ready with false when the script cannot load', async () => {
    const client = load(OPTS);
    net.fail();
    await expect(client.ready).resolves.toBe(false);
    client.track('lost'); // still safe to call
    expect(client.consent.get().status).toBe('unknown');
  });

  it('resolves ready with false when the script ran but no tracker started (tracking disabled)', async () => {
    const client = load(OPTS);
    net.respond();
    await expect(client.ready).resolves.toBe(false);
  });

  it('waits for a prerendered page to be shown', async () => {
    const doc = document as unknown as Record<string, unknown>;
    Object.defineProperty(document, 'prerendering', { configurable: true, value: true });
    try {
      const client = load(OPTS);
      net.respond();
      let settled = false;
      void client.ready.then(() => (settled = true));
      await Promise.resolve();
      expect(settled).toBe(false);
      runFakeTracker();
      document.dispatchEvent(new Event('prerenderingchange'));
      await expect(client.ready).resolves.toBe(true);
    } finally {
      delete doc.prerendering;
    }
  });

  it('delivers consent changes to subscribers registered before the tracker loaded', async () => {
    const client = load(OPTS);
    const seen: string[] = [];
    const other: string[] = [];
    client.consent.onChange((s) => seen.push(s.status));
    const off = client.consent.onChange((s) => other.push(s.status));
    let fake!: ReturnType<typeof runFakeTracker>;
    net.respond(() => (fake = runFakeTracker()));
    await client.ready;
    client.consent.set('accepted');
    off();
    off();
    fake.api.consent.set('rejected'); // e.g. the banner
    expect(seen).toEqual(['accepted', 'rejected']);
    expect(other).toEqual(['accepted']);
    // one bridge listener in the tracker, however many subscribers
    expect(fake.calls.filter((c) => c[0] == 'consent.onChange')).toHaveLength(1);
  });

  it('ignores methods the tracker does not have', async () => {
    const client = load(OPTS);
    net.respond(() => {
      const fake = runFakeTracker();
      delete (fake.api as unknown as Record<string, unknown>).setContent;
    });
    await client.ready;
    expect(() => client.setContent('x')).not.toThrow();
  });
});

describe('server rendering', () => {
  it('is a no-op without window', async () => {
    vi.stubGlobal('window', undefined);
    try {
      const client = load(OPTS);
      const again = load(OPTS);
      expect(again).not.toBe(client); // nothing is cached on the server: no state across requests
      expect(client.globalName).toBe('analytics');
      await expect(client.ready).resolves.toBe(false);
      client.track('x');
      client.pageview();
      client.setContent('k');
      client.consent.open();
      client.consent.set('accepted');
      client.consent.forget();
      client.consent.onChange(() => undefined)();
      expect(client.getVisitorId()).toBeNull();
      expect(client.consent.get()).toEqual({ status: 'unknown', version: null, decidedAt: null });
    } finally {
      vi.unstubAllGlobals();
    }
    expect(document.querySelectorAll('script')).toHaveLength(0);
    expect(w.analytics).toBeUndefined();
  });

  it('still validates the options', () => {
    vi.stubGlobal('window', undefined);
    try {
      expect(() => load({ ...OPTS, publicKey: 'x' })).toThrow(TypeError);
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
