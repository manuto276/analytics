import { normalizeConversion, type Conversion, type ConversionResult } from './conversions.js';
import type { paths } from './generated/openapi.js';
import { request, type FetchLike, type HttpOptions, type Query } from './http.js';

const PUBLIC_KEY = /^pk_[A-Za-z0-9]{21}$/;
/** The API takes at most this many conversions per request; longer lists are sent in chunks. */
export const MAX_BATCH = 100;

export interface ClientOptions {
  /** Base URL of the analytics service, e.g. `https://stats.example.net`. */
  serviceUrl: string;
  /** API key (`ak_<prefix>_<secret>`) with the scopes the calls need. Server-side only. */
  apiKey: string;
  /** The site's public key, `pk_` followed by 21 letters and digits. */
  publicKey: string;
  /** A `fetch` implementation; defaults to the global one (Node ≥ 18). */
  fetch?: FetchLike;
  /** Timeout of each attempt, in milliseconds. Default 10 000. */
  timeoutMs?: number;
  /** Retries after the first attempt, for network errors, timeouts, 429 and 5xx. Default 2. */
  retries?: number;
}

type ServerPaths = keyof paths;
type ReportPath = Extract<ServerPaths, `/server/sites/{publicKey}/reports/${string}`>;

/** Every report under `/server/sites/{publicKey}/reports/*`. */
export type ReportName = ReportPath extends `/server/sites/{publicKey}/reports/${infer N}` ? N : never;

type GetOf<N extends ReportName> = paths[`/server/sites/{publicKey}/reports/${N}`]['get'];
type QueryOf<G> = G extends { parameters: { query?: infer Q } } ? Q : never;

/** Query parameters of a report (period, from, to, filter, limit, …). */
export type ReportParams<N extends ReportName> = [NonNullable<QueryOf<GetOf<N>>>] extends [never]
  ? Record<string, never>
  : NonNullable<QueryOf<GetOf<N>>>;

/** The JSON body a report answers with (`{data, meta}`). */
export type ReportResponse<N extends ReportName> = GetOf<N>['responses'][200]['content']['application/json'];

export type Reports = { [N in ReportName]: (params?: ReportParams<N>) => Promise<ReportResponse<N>> };

type StatsGet = paths['/server/sites/{publicKey}/content/{contentKey}/stats']['get'];
/** `GET …/content/{contentKey}/stats` (`{data: {pageviews, visitors, contacts, suppressed, channels, …}}`). */
export type ContentStats = StatsGet['responses'][200]['content']['application/json'];

export const REPORTS = [
  'overview',
  'timeseries',
  'pages',
  'sources',
  'tech',
  'countries',
  'events',
  'realtime',
  'goals',
  'conversions',
  'consent',
] as const satisfies readonly ReportName[];

// Compile-time guard: a report added to the API document must be added to REPORTS.
type Missing = Exclude<ReportName, (typeof REPORTS)[number]>;
const complete: [Missing] extends [never] ? true : Missing = true;
void complete;

export interface AnalyticsClient {
  conversions: {
    /**
     * Sends one conversion or many (`POST …/conversions`, scope `conversions:write`). Lists longer
     * than 100 go in several requests; the result adds them up, with `rejected[].index` pointing
     * into the list you passed.
     */
    send(conversions: Conversion | readonly Conversion[]): Promise<ConversionResult>;
  };
  content: {
    /** Stats of one content key over the last `days` (1–395, default 30); scope `stats:read`. */
    stats(contentKey: string, options?: { days?: number }): Promise<ContentStats>;
  };
  /** The site's reports, scope `reports:read`: `reports.overview({ period: '7d' })`. */
  reports: Reports;
}

/** A client for the server API of one site. */
export function createClient(options: ClientOptions): AnalyticsClient {
  let base: URL;
  try {
    base = new URL(options.serviceUrl);
  } catch {
    throw new TypeError('serviceUrl must be an absolute http(s) URL');
  }
  if (base.protocol != 'https:' && base.protocol != 'http:') throw new TypeError('serviceUrl must be an absolute http(s) URL');
  if (!PUBLIC_KEY.test(options.publicKey ?? '')) throw new TypeError('publicKey must be "pk_" followed by 21 letters or digits');
  if (typeof options.apiKey != 'string' || options.apiKey.trim() === '') throw new TypeError('apiKey is required');
  const fetchImpl = options.fetch ?? (globalThis as { fetch?: FetchLike }).fetch;
  if (typeof fetchImpl != 'function') throw new TypeError('no global fetch: use Node 18 or later, or pass options.fetch');
  const retries = options.retries ?? 2;
  const timeoutMs = options.timeoutMs ?? 10_000;
  if (!Number.isInteger(retries) || retries < 0) throw new TypeError('retries must be a non-negative integer');
  if (!(timeoutMs > 0)) throw new TypeError('timeoutMs must be positive');

  const http: HttpOptions = {
    baseUrl: `${base.origin}${base.pathname.replace(/\/+$/, '')}/api/v1/server/sites/${options.publicKey}`,
    apiKey: options.apiKey.trim(),
    fetch: fetchImpl,
    timeoutMs,
    retries,
  };

  const reports = Object.fromEntries(
    REPORTS.map((name) => [name, (params?: Query) => request(http, 'GET', `/reports/${name}`, { query: params })]),
  ) as unknown as Reports;

  return {
    conversions: {
      async send(input) {
        const list: readonly Conversion[] = Array.isArray(input) ? input : [input as Conversion];
        const payload = await Promise.all(list.map((c) => normalizeConversion(c)));
        if (!Array.isArray(input)) return request<ConversionResult>(http, 'POST', '/conversions', { body: payload[0] });
        const total: ConversionResult = { accepted: 0, duplicates: 0, rejected: [] };
        for (let i = 0; i < payload.length; i += MAX_BATCH) {
          const r = await request<ConversionResult>(http, 'POST', '/conversions', { body: payload.slice(i, i + MAX_BATCH) });
          total.accepted += r.accepted;
          total.duplicates += r.duplicates;
          for (const x of r.rejected) total.rejected.push({ ...x, index: x.index + i });
        }
        return total;
      },
    },
    content: {
      stats(contentKey, opts = {}) {
        if (typeof contentKey != 'string' || contentKey === '') throw new TypeError('contentKey is required');
        return request<ContentStats>(http, 'GET', `/content/${encodeURIComponent(contentKey)}/stats`, {
          query: { days: opts.days },
        });
      },
    },
    reports,
  };
}
