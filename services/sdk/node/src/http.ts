import { AnalyticsApiError } from './errors.js';

export const VERSION = '0.1.0';

/** Longest wait a `Retry-After` may ask for before the error is thrown instead of waited out. */
export const MAX_RETRY_WAIT_MS = 60_000;

export type FetchLike = (input: string, init: {
  method: string;
  headers: Record<string, string>;
  body?: string;
  signal: AbortSignal;
}) => Promise<{
  ok: boolean;
  status: number;
  statusText: string;
  headers: { get(name: string): string | null };
  text(): Promise<string>;
}>;

export interface HttpOptions {
  baseUrl: string;
  apiKey: string;
  fetch: FetchLike;
  timeoutMs: number;
  retries: number;
}

export type Query = Record<string, unknown>;

/** Seconds from a `Retry-After` header: delay-seconds or an HTTP date. */
export function parseRetryAfter(header: string | null, now: number = Date.now()): number | undefined {
  if (header == null) return undefined;
  const v = header.trim();
  if (/^\d+$/.test(v)) return Number(v);
  const at = Date.parse(v);
  return Number.isNaN(at) ? undefined : Math.max(0, Math.ceil((at - now) / 1000));
}

/** Exponential backoff: 0.5 s, 1 s, 2 s… capped at 30 s, plus up to 250 ms of jitter. */
export const backoff = (attempt: number): number =>
  Math.min(30_000, 500 * 2 ** attempt) + Math.floor(Math.random() * 250);

/** `filter: {page: {contains: '/blog'}}` becomes `filter[page][contains]=/blog` (deepObject). */
export function toQueryString(query: Query | undefined): string {
  if (!query) return '';
  const sp = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value == null) continue;
    if (Array.isArray(value)) {
      for (const v of value) sp.append(key, String(v));
    } else if (value instanceof Date) {
      sp.append(key, value.toISOString().slice(0, 10));
    } else if (typeof value == 'object') {
      for (const [dim, ops] of Object.entries(value as Record<string, unknown>)) {
        if (ops == null) continue;
        if (typeof ops == 'object') {
          for (const [op, v] of Object.entries(ops as Record<string, unknown>)) {
            if (v != null) sp.append(`${key}[${dim}][${op}]`, String(v));
          }
        } else sp.append(`${key}[${dim}]`, String(ops));
      }
    } else sp.append(key, String(value));
  }
  const s = sp.toString();
  return s ? `?${s}` : '';
}

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

const parseBody = (text: string): unknown => {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
};

/**
 * One API call with the client's retry policy: network errors, timeouts, 429 and 5xx are retried up
 * to `retries` times, waiting what `Retry-After` asks for (else exponential backoff). Anything else
 * throws at once. Every other outcome is an `AnalyticsApiError`.
 */
export async function request<T>(
  http: HttpOptions,
  method: 'GET' | 'POST',
  path: string,
  init: { query?: Query; body?: unknown } = {},
): Promise<T> {
  const url = http.baseUrl + path + toQueryString(init.query);
  const headers: Record<string, string> = {
    Authorization: `Bearer ${http.apiKey}`,
    Accept: 'application/json, application/problem+json',
    'User-Agent': `analytics-node/${VERSION}`,
  };
  let body: string | undefined;
  if (init.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(init.body);
  }

  for (let attempt = 0; ; attempt++) {
    let error: AnalyticsApiError;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), http.timeoutMs);
    try {
      const res = await http.fetch(url, { method, headers, body, signal: controller.signal });
      const text = await res.text();
      if (res.ok) {
        if (!text) return null as T;
        const parsed = parseBody(text);
        if (typeof parsed == 'string') {
          throw new AnalyticsApiError({
            status: res.status,
            code: 'invalid_response',
            title: 'The response is not JSON',
            detail: text.slice(0, 200),
          });
        }
        return parsed as T;
      }
      error = AnalyticsApiError.fromResponse(
        res.status,
        res.statusText,
        text ? parseBody(text) : undefined,
        parseRetryAfter(res.headers.get('retry-after')),
      );
    } catch (e) {
      if (e instanceof AnalyticsApiError) throw e;
      const timedOut = controller.signal.aborted;
      error = new AnalyticsApiError(
        {
          status: 0,
          code: timedOut ? 'timeout' : 'network_error',
          title: timedOut ? `No response within ${http.timeoutMs} ms` : 'The analytics service could not be reached',
          detail: timedOut ? undefined : e instanceof Error ? e.message : String(e),
        },
        { cause: e },
      );
    } finally {
      clearTimeout(timer);
    }
    if (!error.retryable || attempt >= http.retries) throw error;
    const wait = error.retryAfter != null ? error.retryAfter * 1000 : backoff(attempt);
    if (wait > MAX_RETRY_WAIT_MS) throw error;
    await sleep(wait);
  }
}
