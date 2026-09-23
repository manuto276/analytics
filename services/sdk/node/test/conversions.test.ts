import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createClient, normalizeConversion } from '../src/index';
import { BASE, mockFetch, PK, VID } from './helpers';

const NOW = Date.UTC(2026, 8, 23, 10, 30, 15, 123);
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
});
afterEach(() => {
  vi.useRealTimers();
});

const client = (fetch: ReturnType<typeof mockFetch>['fetch']) =>
  createClient({ serviceUrl: 'https://stats.example.net', apiKey: 'ak_abc_secret', publicKey: PK, fetch });

describe('normalizeConversion (the WordPress plugin rules)', () => {
  it('fills id and occurred_at, and sends only what is set', async () => {
    const c = await normalizeConversion({ name: 'signup' });
    expect(c).toEqual({ id: expect.stringMatching(UUID), name: 'signup', occurred_at: '2026-09-23T10:30:15Z' });
    expect((await normalizeConversion({ name: 'signup' })).id).not.toBe(c.id);
  });

  it('keeps every field of a full conversion', async () => {
    expect(
      await normalizeConversion({
        id: 'order-1042',
        name: 'purchase',
        occurred_at: new Date(Date.UTC(2026, 8, 20, 8, 0, 0, 999)),
        visitor_id: VID,
        customer_ref: 42,
        value: { amount_minor: 4990, currency: 'eur' },
        props: { plan: 'pro', seats: 3, trial: false },
        declared_source: { utm_source: 'newsletter' },
      }),
    ).toEqual({
      id: 'order-1042',
      name: 'purchase',
      occurred_at: '2026-09-20T08:00:00Z',
      visitor_id: VID,
      customer_ref: '42',
      value: { amount_minor: 4990, currency: 'EUR' },
      props: { plan: 'pro', seats: 3, trial: false },
      declared_source: { utm_source: 'newsletter' },
    });
  });

  it.each([
    ['epoch milliseconds', Date.UTC(2026, 0, 2, 3, 4, 5), '2026-01-02T03:04:05Z'],
    ['ISO 8601 with offset', '2026-01-02T05:04:05+02:00', '2026-01-02T03:04:05Z'],
    ['ISO 8601 UTC', '2026-01-02T03:04:05.678Z', '2026-01-02T03:04:05Z'],
  ])('accepts occurred_at as %s', async (_, input, expected) => {
    expect((await normalizeConversion({ name: 'x', occurred_at: input })).occurred_at).toBe(expected);
  });

  it.each([
    ['too short', 'abc'],
    ['too long', `${VID}x`],
    ['bad characters', 'AbCdEfGhIjKlMnOpQrSt+/'],
    ['not a string', 42],
    ['null', null],
  ])('drops a visitor_id that is %s', async (_, visitor_id) => {
    const c = await normalizeConversion({ name: 'x', visitor_id: visitor_id as string });
    expect(c).not.toHaveProperty('visitor_id');
  });

  it('drops empty optional fields and uses a UUID for an empty id', async () => {
    const c = await normalizeConversion({ name: 'x', id: '', customer_ref: '', value: null, props: {}, declared_source: {} });
    expect(Object.keys(c).sort()).toEqual(['id', 'name', 'occurred_at']);
    expect(c.id).toMatch(UUID);
    expect((await normalizeConversion({ name: 'x', id: 7 })).id).toBe('7');
  });

  it.each([
    [{ name: '' }, /name/],
    [{ name: 'x', occurred_at: 'yesterday' }, /occurred_at/],
    [{ name: 'x', value: { amount_minor: 49.9, currency: 'EUR' } }, /minor units/],
    [{ name: 'x', value: { amount_minor: '4990' as unknown as number, currency: 'EUR' } }, /minor units/],
    [{ name: 'x', value: { amount_minor: 4990, currency: 'euro' } }, /ISO 4217/],
    [{ name: 'x', value: { amount_minor: 4990 } as { amount_minor: number; currency: string } }, /ISO 4217/],
  ])('refuses %o', async (input, message) => {
    await expect(normalizeConversion(input)).rejects.toThrow(message);
  });

  it('falls back to node:crypto where there is no global crypto (Node 18)', async () => {
    vi.stubGlobal('crypto', undefined);
    try {
      expect((await normalizeConversion({ name: 'x' })).id).toMatch(UUID);
    } finally {
      vi.unstubAllGlobals();
    }
  });
});

describe('conversions.send', () => {
  it('posts one conversion as an object, with the key and JSON headers', async () => {
    const { fetch, calls } = mockFetch({ status: 202, body: { accepted: 1, duplicates: 0, rejected: [] } });
    const result = await client(fetch).conversions.send({ id: 'order-1', name: 'purchase', visitor_id: VID });
    expect(result).toEqual({ accepted: 1, duplicates: 0, rejected: [] });
    expect(calls).toHaveLength(1);
    expect(calls[0]).toMatchObject({
      url: `${BASE}/conversions`,
      method: 'POST',
      headers: {
        Authorization: 'Bearer ak_abc_secret',
        'Content-Type': 'application/json',
        Accept: 'application/json, application/problem+json',
        'User-Agent': 'analytics-node/0.1.0',
      },
      body: { id: 'order-1', name: 'purchase', occurred_at: '2026-09-23T10:30:15Z', visitor_id: VID },
    });
  });

  it('posts a list as an array', async () => {
    const { fetch, calls } = mockFetch({
      status: 202,
      body: { accepted: 1, duplicates: 1, rejected: [{ index: 2, error: 'name' }] },
    });
    const r = await client(fetch).conversions.send([{ name: 'a' }, { name: 'b', id: 'dup' }, { name: 'c' }]);
    expect(r).toEqual({ accepted: 1, duplicates: 1, rejected: [{ index: 2, error: 'name' }] });
    expect((calls[0].body as { name: string }[]).map((c) => c.name)).toEqual(['a', 'b', 'c']);
  });

  it('sends more than 100 in chunks and adds up the results', async () => {
    const { fetch, calls } = mockFetch(
      { status: 202, body: { accepted: 99, duplicates: 1, rejected: [] } },
      { status: 202, body: { accepted: 40, duplicates: 0, rejected: [{ index: 3, error: 'occurred_at' }] } },
    );
    const list = Array.from({ length: 144 }, (_, i) => ({ name: 'lead', id: `lead-${i}` }));
    const r = await client(fetch).conversions.send(list);
    expect(calls.map((c) => (c.body as unknown[]).length)).toEqual([100, 44]);
    expect((calls[1].body as { id: string }[])[0].id).toBe('lead-100');
    expect(r).toEqual({ accepted: 139, duplicates: 1, rejected: [{ index: 103, error: 'occurred_at' }] });
  });

  it('sends nothing for an empty list', async () => {
    const { fetch } = mockFetch();
    expect(await client(fetch).conversions.send([])).toEqual({ accepted: 0, duplicates: 0, rejected: [] });
    expect(fetch).not.toHaveBeenCalled();
  });

  it('validates before sending anything', async () => {
    const { fetch } = mockFetch();
    await expect(client(fetch).conversions.send([{ name: 'ok' }, { name: '' }])).rejects.toThrow(TypeError);
    expect(fetch).not.toHaveBeenCalled();
  });
});
