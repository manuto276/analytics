import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { serverDocument } from '../scripts/openapi-types.mjs';
import { createClient, REPORTS, type ClientOptions } from '../src/index';
import { toQueryString, VERSION } from '../src/http';
import { BASE, mockFetch, PK } from './helpers';

const OPTS: ClientOptions = { serviceUrl: 'https://stats.example.net', apiKey: 'ak_x_y', publicKey: PK };

describe('createClient', () => {
  it.each([
    [{ serviceUrl: 'stats.example.net' }, /serviceUrl/],
    [{ serviceUrl: 'ftp://stats.example.net' }, /serviceUrl/],
    [{ publicKey: 'pk_nope' }, /publicKey/],
    [{ apiKey: ' ' }, /apiKey/],
    [{ retries: -1 }, /retries/],
    [{ retries: 1.5 }, /retries/],
    [{ timeoutMs: 0 }, /timeoutMs/],
  ] as [Partial<ClientOptions>, RegExp][])('refuses %o', (o, message) => {
    expect(() => createClient({ ...OPTS, fetch: mockFetch().fetch, ...o })).toThrow(message);
  });

  it('needs a fetch', () => {
    const original = globalThis.fetch;
    // @ts-expect-error -- Node < 18
    delete globalThis.fetch;
    try {
      expect(() => createClient(OPTS)).toThrow(/fetch/);
    } finally {
      globalThis.fetch = original;
    }
    expect(() => createClient(OPTS)).not.toThrow();
  });

  it('keeps a path prefix of the service URL', async () => {
    const { fetch, calls } = mockFetch({ status: 200, body: {} });
    await createClient({ ...OPTS, serviceUrl: 'https://example.net/analytics/', fetch }).reports.realtime();
    expect(calls[0].url).toBe(`https://example.net/analytics/api/v1/server/sites/${PK}/reports/realtime`);
  });

  it('sends the version of package.json as the user agent', () => {
    const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string };
    expect(VERSION).toBe(pkg.version);
  });
});

describe('reports', () => {
  it('has a method for every /server/sites/{publicKey}/reports/* route of the API document', () => {
    const doc = serverDocument(parse(readFileSync(new URL('../../../../docs/api/openapi.yaml', import.meta.url), 'utf8'), { maxAliasCount: -1 }));
    const routes = Object.keys(doc.paths)
      .filter((p) => p.startsWith('/server/sites/{publicKey}/reports/'))
      .map((p) => p.slice('/server/sites/{publicKey}/reports/'.length));
    expect([...REPORTS].sort()).toEqual(routes.sort());
    const api = createClient({ ...OPTS, fetch: mockFetch().fetch });
    expect(Object.keys(api.reports).sort()).toEqual(routes.sort());
  });

  it.each(REPORTS.map((r) => [r]))('%s GETs its route', async (name) => {
    const { fetch, calls } = mockFetch({ status: 200, body: { data: {}, meta: {} } });
    const api = createClient({ ...OPTS, fetch });
    await expect(api.reports[name]()).resolves.toEqual({ data: {}, meta: {} });
    expect(calls[0]).toMatchObject({
      url: `${BASE}/reports/${name}`,
      method: 'GET',
      headers: { Authorization: 'Bearer ak_x_y' },
      body: undefined,
    });
    expect(calls[0].headers).not.toHaveProperty('Content-Type');
  });

  it('serialises the query, filters as deep objects', async () => {
    const { fetch, calls } = mockFetch({ status: 200, body: { data: { rows: [] }, meta: { next_cursor: null } } });
    await createClient({ ...OPTS, fetch }).reports.pages({
      period: 'custom',
      from: '2026-09-01',
      to: '2026-09-22',
      kind: 'entry',
      limit: 10,
      filter: { page: { contains: '/blog' }, country: { is: 'IT' } },
    });
    const url = new URL(calls[0].url);
    expect(url.pathname).toBe(`/api/v1/server/sites/${PK}/reports/pages`);
    expect([...url.searchParams]).toEqual([
      ['period', 'custom'],
      ['from', '2026-09-01'],
      ['to', '2026-09-22'],
      ['kind', 'entry'],
      ['limit', '10'],
      ['filter[page][contains]', '/blog'],
      ['filter[country][is]', 'IT'],
    ]);
  });
});

describe('toQueryString', () => {
  it('skips empty values and handles arrays, dates and flat objects', () => {
    expect(toQueryString(undefined)).toBe('');
    expect(toQueryString({ a: undefined, b: null })).toBe('');
    expect(
      decodeURIComponent(
        toQueryString({
          ids: [1, 2],
          day: new Date(Date.UTC(2026, 8, 1)),
          filter: { page: null, level: 'c', country: { is: 'IT', not: undefined } },
        }),
      ),
    ).toBe('?ids=1&ids=2&day=2026-09-01&filter[level]=c&filter[country][is]=IT');
  });
});

describe('content.stats', () => {
  it('encodes the key and passes days', async () => {
    const body = {
      data: {
        content_key: 'author:42/x',
        days: 7,
        from: '2026-09-16',
        to: '2026-09-22',
        pageviews: 120,
        visitors: 80,
        contacts: null,
        suppressed: false,
        channels: { search: 40 },
      },
    };
    const { fetch, calls } = mockFetch({ status: 200, body });
    const api = createClient({ ...OPTS, fetch });
    await expect(api.content.stats('author:42/x', { days: 7 })).resolves.toEqual(body);
    expect(calls[0].url).toBe(`${BASE}/content/author%3A42%2Fx/stats?days=7`);
    await api.content.stats('home');
    expect(calls[1].url).toBe(`${BASE}/content/home/stats`);
  });

  it('needs a key', () => {
    const api = createClient({ ...OPTS, fetch: mockFetch().fetch });
    expect(() => api.content.stats('')).toThrow(TypeError);
  });
});
