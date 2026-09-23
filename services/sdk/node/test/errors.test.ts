import { describe, expect, it } from 'vitest';
import { AnalyticsApiError, createClient } from '../src/index';
import { mockFetch, PK, problem, type Reply } from './helpers';

const client = (...replies: Reply[]) => {
  const m = mockFetch(...replies);
  return { ...m, api: createClient({ serviceUrl: 'https://stats.example.net', apiKey: 'ak_x_y', publicKey: PK, fetch: m.fetch }) };
};

const failure = async (p: Promise<unknown>): Promise<AnalyticsApiError> => {
  const e = await p.then(
    () => {
      throw new Error('expected a rejection');
    },
    (err: unknown) => err,
  );
  expect(e).toBeInstanceOf(AnalyticsApiError);
  return e as AnalyticsApiError;
};

describe('AnalyticsApiError from problem documents', () => {
  it('carries the fields of a 422', async () => {
    const { api, calls } = client(
      problem(422, 'validation_failed', {
        title: 'Validation failed',
        detail: 'One or more fields are invalid.',
        errors: { 'value.currency': ['must be ISO 4217'] },
        request_id: 'req-1',
      }),
    );
    const e = await failure(api.conversions.send({ name: 'purchase' }));
    expect(e).toMatchObject({
      name: 'AnalyticsApiError',
      status: 422,
      code: 'validation_failed',
      title: 'Validation failed',
      detail: 'One or more fields are invalid.',
      errors: { 'value.currency': ['must be ISO 4217'] },
      requestId: 'req-1',
      type: 'about:blank',
      retryAfter: undefined,
      retryable: false,
    });
    expect(e.message).toBe('Validation failed: One or more fields are invalid.');
    expect(e.problem?.code).toBe('validation_failed');
    expect(e).toBeInstanceOf(Error);
    expect(calls).toHaveLength(1); // never retried
  });

  it.each([400, 401, 403, 404, 413])('does not retry a %i', async (status) => {
    const { api, calls } = client(problem(status, 'nope'));
    const e = await failure(api.reports.overview());
    expect(e.status).toBe(status);
    expect(e.code).toBe('nope');
    expect(e.message).toBe('nope');
    expect(calls).toHaveLength(1);
  });

  it('copes with a body that is not a problem document', async () => {
    const { fetch } = mockFetch({ status: 502, statusText: 'Bad Gateway', body: '<html>upstream down</html>' });
    const api = createClient({ serviceUrl: 'https://s.example', apiKey: 'k', publicKey: PK, fetch, retries: 0 });
    const e = await failure(api.reports.realtime());
    expect(e).toMatchObject({
      status: 502,
      code: 'http_502',
      title: 'Bad Gateway',
      detail: '<html>upstream down</html>',
      problem: undefined,
      retryable: true,
    });
  });

  it('falls back to the status when there is no text at all', async () => {
    const { api } = client({ status: 418 });
    const e = await failure(api.reports.overview());
    expect(e).toMatchObject({ status: 418, code: 'http_418', title: 'HTTP 418', detail: undefined, errors: undefined });
  });

  it('refuses a success that is not JSON', async () => {
    const { api } = client({ status: 200, body: '<!doctype html><title>login</title>' });
    const e = await failure(api.reports.overview());
    expect(e).toMatchObject({ status: 200, code: 'invalid_response', retryable: false });
  });

  it('returns null for an empty success', async () => {
    const { api } = client({ status: 202 });
    expect(await api.conversions.send({ name: 'x' })).toBeNull();
  });
});
