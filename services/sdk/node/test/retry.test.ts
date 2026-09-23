import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AnalyticsApiError, createClient } from '../src/index';
import { parseRetryAfter } from '../src/http';
import { mockFetch, PK, problem, type Reply } from './helpers';

const OK: Reply = { status: 200, body: { data: { ok: true }, meta: {} } };

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(Date.UTC(2026, 8, 23, 12, 0, 0));
  vi.spyOn(Math, 'random').mockReturnValue(0);
});
afterEach(() => {
  vi.useRealTimers();
});

const setup = (replies: Reply[], opts: { retries?: number; timeoutMs?: number } = {}) => {
  const m = mockFetch(...replies);
  const api = createClient({ serviceUrl: 'https://stats.example.net', apiKey: 'ak_x_y', publicKey: PK, fetch: m.fetch, ...opts });
  return { ...m, api };
};

/** Lets pending promises run without moving the clock. */
const flush = () => vi.advanceTimersByTimeAsync(0);

describe('retries', () => {
  it('waits exactly what Retry-After asks for on a 429', async () => {
    const { api, calls } = setup([problem(429, 'rate_limited', {}, { 'Retry-After': '3' }), OK]);
    const p = api.reports.overview({ period: '7d' });
    await flush();
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(2999);
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls).toHaveLength(2);
    await expect(p).resolves.toEqual({ data: { ok: true }, meta: {} });
  });

  it('understands Retry-After as an HTTP date', async () => {
    const at = new Date(Date.now() + 5000).toUTCString();
    const { api, calls } = setup([problem(503, 'maintenance', {}, { 'Retry-After': at }), OK]);
    const p = api.reports.overview();
    await vi.advanceTimersByTimeAsync(4999);
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    await expect(p).resolves.toBeTruthy();
  });

  it("uses the document's retry_after when there is no header", async () => {
    const { api, calls } = setup([problem(429, 'rate_limited', { retry_after: 2 }), OK]);
    const p = api.reports.overview();
    await vi.advanceTimersByTimeAsync(1999);
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    await expect(p).resolves.toBeTruthy();
  });

  it('backs off exponentially on 5xx without Retry-After', async () => {
    const { api, calls } = setup([problem(500, 'internal'), problem(502, 'bad_gateway'), OK]);
    const p = api.reports.overview();
    await vi.advanceTimersByTimeAsync(499);
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(999);
    expect(calls).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls).toHaveLength(3);
    await expect(p).resolves.toBeTruthy();
  });

  it('gives up after `retries` and throws the last error', async () => {
    const { api, calls } = setup([problem(503, 'a'), problem(503, 'b'), problem(503, 'c'), OK], { retries: 2 });
    const p = api.reports.overview();
    const settled = expect(p).rejects.toMatchObject({ status: 503, code: 'c' });
    await vi.advanceTimersByTimeAsync(10_000);
    await settled;
    expect(calls).toHaveLength(3);
  });

  it('does not retry with retries: 0', async () => {
    const { api, calls } = setup([problem(429, 'rate_limited', {}, { 'Retry-After': '1' }), OK], { retries: 0 });
    await expect(api.reports.overview()).rejects.toMatchObject({ status: 429, retryAfter: 1 });
    expect(calls).toHaveLength(1);
  });

  it('throws at once when Retry-After is longer than a minute', async () => {
    const { api, calls } = setup([problem(429, 'rate_limited', {}, { 'Retry-After': '3600' }), OK]);
    await expect(api.reports.overview()).rejects.toMatchObject({ status: 429, retryAfter: 3600 });
    expect(calls).toHaveLength(1);
  });

  it('retries network errors, keeping the same conversion id', async () => {
    const { api, calls } = setup([new TypeError('fetch failed'), { status: 202, body: { accepted: 1, duplicates: 0, rejected: [] } }]);
    const p = api.conversions.send({ name: 'purchase' });
    await vi.advanceTimersByTimeAsync(500);
    await expect(p).resolves.toMatchObject({ accepted: 1 });
    expect(calls).toHaveLength(2);
    expect((calls[0].body as { id: string }).id).toBe((calls[1].body as { id: string }).id);
  });

  it('reports a network failure once retries are spent', async () => {
    const cause = new TypeError('getaddrinfo ENOTFOUND');
    const { api } = setup([cause], { retries: 0 });
    const e = (await api.reports.overview().catch((x: unknown) => x)) as AnalyticsApiError;
    expect(e).toBeInstanceOf(AnalyticsApiError);
    expect(e).toMatchObject({ status: 0, code: 'network_error', detail: 'getaddrinfo ENOTFOUND', retryable: true });
    expect(e.cause).toBe(cause);
  });

  it('reports a thrown non-Error', async () => {
    const { api } = setup([() => Promise.reject('boom')], { retries: 0 });
    await expect(api.reports.overview()).rejects.toMatchObject({ code: 'network_error', detail: 'boom' });
  });

  it('times out each attempt after timeoutMs', async () => {
    const hang: Reply = (init) =>
      new Promise((_, reject) => init.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    const { api, calls } = setup([hang, hang], { retries: 1, timeoutMs: 1000 });
    const p = api.reports.overview();
    const settled = expect(p).rejects.toMatchObject({ status: 0, code: 'timeout', title: 'No response within 1000 ms' });
    await vi.advanceTimersByTimeAsync(1000);
    expect(calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(500 + 1000);
    await settled;
    expect(calls).toHaveLength(2);
  });
});

describe('parseRetryAfter', () => {
  it.each([
    [null, undefined],
    ['0', 0],
    [' 12 ', 12],
    ['soon', undefined],
    [new Date(Date.UTC(2026, 8, 23, 12, 0, 10)).toUTCString(), 10],
    [new Date(Date.UTC(2026, 8, 23, 11, 0, 0)).toUTCString(), 0],
  ])('%s → %s', (header, seconds) => {
    expect(parseRetryAfter(header, Date.UTC(2026, 8, 23, 12, 0, 0))).toBe(seconds);
  });
});
