import { vi } from 'vitest';
import type { FetchLike } from '../src/index';

export const PK = 'pk_ABCDEFGHIJKLMNOPQRSTU';
export const VID = 'AbCdEfGhIjKlMnOpQrStUv';
export const BASE = `https://stats.example.net/api/v1/server/sites/${PK}`;

export type Reply =
  | { status: number; body?: unknown; headers?: Record<string, string>; statusText?: string }
  | Error
  | ((init: Parameters<FetchLike>[1]) => ReturnType<FetchLike>);

export interface Call {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

/** A fetch that answers with `replies` in order (then 202 `{accepted: 1}`), recording each call. */
export function mockFetch(...replies: Reply[]) {
  const calls: Call[] = [];
  const fetch = vi.fn<FetchLike>(async (url, init) => {
    calls.push({ url, method: init.method, headers: init.headers, body: init.body ? JSON.parse(init.body) : undefined });
    const r = replies.shift() ?? { status: 202, body: { accepted: 1, duplicates: 0, rejected: [] } };
    if (r instanceof Error) throw r;
    if (typeof r == 'function') return r(init);
    const text = r.body === undefined ? null : typeof r.body == 'string' ? r.body : JSON.stringify(r.body);
    return new Response(text, { status: r.status, statusText: r.statusText, headers: r.headers });
  });
  return { fetch, calls };
}

export const problem = (
  status: number,
  code: string,
  extra: Record<string, unknown> = {},
  headers: Record<string, string> = {},
): Reply => ({
  status,
  headers: { 'Content-Type': 'application/problem+json', ...headers },
  body: { type: 'about:blank', title: code.replace(/_/g, ' '), status, code, ...extra },
});
