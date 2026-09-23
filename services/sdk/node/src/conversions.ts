import type { components, paths } from './generated/openapi.js';
import { isVisitorId } from './visitor.js';

/** One conversion as the API takes it (`ConversionInput`). */
export type ApiConversion = components['schemas']['ConversionInput'];

/** The API's answer to a batch: `202 {accepted, duplicates, rejected: [{index, error}]}`. */
export type ConversionResult =
  paths['/server/sites/{publicKey}/conversions']['post']['responses'][202]['content']['application/json'];

/**
 * A conversion to send. The same fields as the API, with the rules of the WordPress plugin's
 * `analytics_connector_track_conversion()`:
 *
 * - `id` defaults to a random UUID (pass your order or invoice number: it is the idempotency key);
 * - `occurred_at` defaults to now and may be a `Date`, epoch milliseconds or an ISO 8601 string;
 *   it is sent as ISO 8601 UTC (`2026-09-23T10:00:00Z`);
 * - `visitor_id` is dropped unless it matches `^[A-Za-z0-9_-]{22}$`;
 * - `value.amount_minor` is in minor units (cents) and must be an integer; `value.currency` is an
 *   ISO 4217 code, upper-cased.
 */
export interface Conversion {
  /** `^[a-z0-9_:.-]{1,64}$`, e.g. `purchase`. */
  name: string;
  id?: string | number | null;
  occurred_at?: Date | number | string | null;
  visitor_id?: string | null;
  customer_ref?: string | number | null;
  value?: { amount_minor: number; currency: string } | null;
  props?: ApiConversion['props'] | null;
  declared_source?: ApiConversion['declared_source'] | null;
}

const CURRENCY = /^[A-Z]{3}$/;

async function uuid(): Promise<string> {
  const web = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto;
  if (web && typeof web.randomUUID == 'function') return web.randomUUID();
  // Node 18 has no global crypto by default.
  const { randomUUID } = await import('node:crypto');
  return randomUUID();
}

function isoSeconds(v: Date | number | string): string {
  const d = v instanceof Date ? v : new Date(v);
  if (Number.isNaN(d.getTime())) throw new TypeError(`occurred_at is not a valid date: ${String(v)}`);
  return d.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** Applies the rules above; throws `TypeError` on a conversion that can never be accepted. */
export async function normalizeConversion(c: Conversion, now: () => number = Date.now): Promise<ApiConversion> {
  if (!c || typeof c.name != 'string' || c.name === '') throw new TypeError('a conversion needs a name');
  const out: ApiConversion = {
    id: c.id != null && String(c.id) !== '' ? String(c.id) : await uuid(),
    name: c.name,
    occurred_at: isoSeconds(c.occurred_at ?? now()),
  };
  if (isVisitorId(c.visitor_id)) out.visitor_id = c.visitor_id;
  if (c.customer_ref != null && String(c.customer_ref) !== '') out.customer_ref = String(c.customer_ref);
  if (c.value != null) {
    const amount = c.value.amount_minor;
    const currency = String(c.value.currency ?? '').toUpperCase();
    if (!Number.isSafeInteger(amount)) {
      throw new TypeError(`value.amount_minor must be an integer in minor units (cents), got ${String(amount)}`);
    }
    if (!CURRENCY.test(currency)) throw new TypeError(`value.currency must be an ISO 4217 code, got ${String(c.value.currency)}`);
    out.value = { amount_minor: amount, currency };
  }
  if (c.props && typeof c.props == 'object' && Object.keys(c.props).length) out.props = c.props;
  if (c.declared_source && typeof c.declared_source == 'object' && Object.keys(c.declared_source).length) {
    out.declared_source = c.declared_source;
  }
  return out;
}
