import type { components } from './generated/openapi.js';

/** An RFC 9457 problem document, as the API sends it (`application/problem+json`). */
export type ProblemDocument = components['schemas']['Problem'];

/**
 * A failed call: an error response of the API (built from its problem document), or a network
 * failure or timeout (`status` 0, `code` `network_error` / `timeout`).
 */
export class AnalyticsApiError extends Error {
  override readonly name = 'AnalyticsApiError';
  /** HTTP status, or 0 when no response arrived. */
  readonly status: number;
  /** Stable machine-readable code, e.g. `validation_failed`, `rate_limited`, `insufficient_scope`. */
  readonly code: string;
  readonly title: string;
  readonly detail?: string;
  /** Field errors of a 422: `{ "0.visitor_id": ["…"] }`. */
  readonly errors?: Record<string, string[]>;
  /** Seconds to wait before retrying (the `Retry-After` header, else the document's `retry_after`). */
  readonly retryAfter?: number;
  readonly requestId?: string;
  readonly type?: string;
  /** The raw problem document, when there was one. */
  readonly problem?: ProblemDocument;

  constructor(
    init: {
      status: number;
      code: string;
      title: string;
      detail?: string;
      errors?: Record<string, string[]>;
      retryAfter?: number;
      requestId?: string;
      type?: string;
      problem?: ProblemDocument;
    },
    options?: { cause?: unknown },
  ) {
    super(init.detail ? `${init.title}: ${init.detail}` : init.title, options);
    this.status = init.status;
    this.code = init.code;
    this.title = init.title;
    this.detail = init.detail;
    this.errors = init.errors;
    this.retryAfter = init.retryAfter;
    this.requestId = init.requestId;
    this.type = init.type;
    this.problem = init.problem;
  }

  /** Builds the error from a response status, its body (parsed JSON or text) and `Retry-After`. */
  static fromResponse(status: number, statusText: string, body: unknown, retryAfter?: number): AnalyticsApiError {
    const p = (body && typeof body == 'object' ? body : {}) as Partial<ProblemDocument>;
    const str = (v: unknown): string | undefined => (typeof v == 'string' && v !== '' ? v : undefined);
    return new AnalyticsApiError({
      status,
      code: str(p.code) ?? `http_${status}`,
      title: str(p.title) ?? (statusText || `HTTP ${status}`),
      detail: str(p.detail) ?? (typeof body == 'string' && body.trim() ? body.trim().slice(0, 500) : undefined),
      errors: p.errors && typeof p.errors == 'object' ? p.errors : undefined,
      retryAfter: retryAfter ?? (typeof p.retry_after == 'number' ? p.retry_after : undefined),
      requestId: str(p.request_id),
      type: str(p.type),
      problem: typeof p.code == 'string' && typeof p.status == 'number' ? (p as ProblemDocument) : undefined,
    });
  }

  /** True for the errors worth retrying: network failures, timeouts, 429 and 5xx. */
  get retryable(): boolean {
    return this.status == 0 || this.status == 429 || this.status >= 500;
  }
}
