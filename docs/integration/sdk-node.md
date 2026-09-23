# Node SDK (`@manuto276/analytics-node`)

A client for the server API (`/api/v1/server/sites/{publicKey}/*`): send
[server-side conversions](server-side-conversions.md), read a content key's stats and the site's
reports. Node 18 or later, or any runtime with `fetch` (Deno, Bun, edge workers); no dependencies;
typed from [../api/openapi.yaml](../api/openapi.yaml). MIT licensed
([ADR 0010](../architecture/adr/0010-sdk-packages.md)).

## Install

The package is on GitHub Packages. Map the `@manuto276` scope to it in the project's `.npmrc`:

```ini
@manuto276:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GITHUB_TOKEN}
```

GitHub Packages asks for a token even for public packages: a personal access token (classic) with
`read:packages` exported as `GITHUB_TOKEN` locally and on your build servers; in GitHub Actions, the
workflow's token with `permissions: packages: read`.

```sh
npm install @manuto276/analytics-node
```

## The client

```ts
import { createClient } from '@manuto276/analytics-node';

export const analytics = createClient({
  serviceUrl: 'https://stats.example.net',
  apiKey: process.env.ANALYTICS_API_KEY!,
  publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX',
  // timeoutMs: 10_000,  // per attempt
  // retries: 2,         // after the first attempt
  // fetch,              // another fetch implementation
});
```

The API key is a server-side secret with the scopes you need
(`php bin/analytics api-key:create --site=pk_… --scopes=conversions:write,stats:read,reports:read`):

| Call | Route | Scope |
|---|---|---|
| `conversions.send(one \| many)` | `POST …/conversions` | `conversions:write` |
| `content.stats(contentKey, { days })` | `GET …/content/{contentKey}/stats` | `stats:read` |
| `reports.<name>(params)` | `GET …/reports/<name>` | `reports:read` |

`reports` has one method per server report: `overview`, `timeseries`, `pages`, `sources`, `tech`,
`countries`, `events`, `realtime`, `goals`, `conversions`, `consent`. Parameters and responses are
the ones in [../api/reporting.md](../api/reporting.md), typed; filters are objects:

```ts
const { data, meta } = await analytics.reports.pages({
  period: '30d',
  kind: 'entry',
  limit: 20,
  filter: { page: { prefix: '/blog/' }, country: { is: 'IT' } }, // filter[page][prefix]=/blog/…
});
const { data: stats } = await analytics.content.stats('author:42', { days: 7 });
```

## Conversions

`conversions.send()` takes one conversion or a list, and applies the same rules as the WordPress
plugin's `analytics_connector_track_conversion()`:

| Field | |
|---|---|
| `name` | required, `^[a-z0-9_:.-]{1,64}$` |
| `id` | your business identifier (order number): the idempotency key. Default: a random UUID — fine for one-off events, but pass your own so that a retry of *your* job is not counted twice |
| `occurred_at` | `Date`, epoch milliseconds or an ISO 8601 string; sent as ISO 8601 UTC. Default: now |
| `visitor_id` | the `an_vid` cookie; dropped unless it matches `^[A-Za-z0-9_-]{22}$` |
| `customer_ref` | opaque, stable customer reference (stored only as a keyed hash) |
| `value` | `{ amount_minor, currency }`: an **integer** amount in minor units (cents) and an ISO 4217 code (upper-cased). A non-integer amount or an invalid currency throws a `TypeError` before anything is sent |
| `props`, `declared_source` | as in [../api/conversions.md](../api/conversions.md) |

A list longer than 100 is sent in several requests; the result adds them up and `rejected[].index`
points into the list you passed. Retries reuse the same ids, so a retried batch is never counted
twice.

### Reading the visitor id

```ts
import { visitorIdFromCookie, visitorIdFromRequest } from '@manuto276/analytics-node';

visitorIdFromRequest(req);                      // Node IncomingMessage, Express/Koa/Fastify, Fetch Request
visitorIdFromCookie(req.headers.cookie);        // a raw Cookie header
```

Both return `null` when the cookie is missing or malformed (it is attacker-controlled input). With
`cookie-parser`, `req.cookies.an_vid` is used when it is valid, the header otherwise. When the
checkout runs on another domain, pass the id from the browser instead (`getVisitorId()`, see
[server-side-conversions.md](server-side-conversions.md#the-visitor-id)).

### Express

```ts
import express from 'express';
import { AnalyticsApiError, visitorIdFromRequest } from '@manuto276/analytics-node';
import { analytics } from './analytics';

const app = express();

app.post('/checkout/complete', express.json(), async (req, res) => {
  const order = await confirmPayment(req.body);
  res.status(204).end(); // never make the customer wait for analytics

  try {
    await analytics.conversions.send({
      id: `order-${order.id}`,
      name: 'purchase',
      occurred_at: order.paidAt,
      visitor_id: visitorIdFromRequest(req),
      customer_ref: order.customerId,
      value: { amount_minor: order.totalCents, currency: order.currency },
      props: { plan: order.plan },
    });
  } catch (e) {
    if (e instanceof AnalyticsApiError && !e.retryable) console.error('conversion refused', e.code, e.errors);
    else await queue.push('conversion', order.id); // try again later with the same id
  }
});
```

### A Fetch-style handler (Next.js route handler, Remix, Hono, workers)

```ts
// app/api/checkout/route.ts
import { visitorIdFromRequest } from '@manuto276/analytics-node';
import { analytics } from '@/lib/analytics';

export async function POST(request: Request): Promise<Response> {
  const order = await confirmPayment(await request.json());
  const result = await analytics.conversions.send({
    id: `order-${order.id}`,
    name: 'purchase',
    visitor_id: visitorIdFromRequest(request),
    value: { amount_minor: order.totalCents, currency: 'EUR' },
  });
  return Response.json({ ok: true, duplicate: result.duplicates > 0 });
}
```

### Batches and backfills

```ts
const result = await analytics.conversions.send(
  invoices.map((i) => ({ id: `inv-${i.number}`, name: 'renewal', occurred_at: i.paidAt, customer_ref: i.customerId })),
);
for (const r of result.rejected) console.warn('rejected', invoices[r.index].number, r.error);
```

`occurred_at` must be within the last 30 days (and at most an hour ahead), so older history is
refused per item.

## Errors and retries

Every failure is an `AnalyticsApiError`:

| Property | |
|---|---|
| `status` | HTTP status; `0` for a network error or a timeout |
| `code` | the problem document's stable code (`validation_failed`, `rate_limited`, `insufficient_scope`, …); `network_error`, `timeout`, `invalid_response` (a success that is not JSON), or `http_<status>` without a document |
| `title`, `detail` | human-readable text |
| `errors` | field errors of a `422` |
| `retryAfter` | seconds, from `Retry-After` or the document's `retry_after` |
| `requestId`, `type`, `problem` | the rest of the [problem document](../api/errors.md) |
| `retryable` | `true` for network errors, timeouts, `429` and `5xx` |

The client retries retryable failures up to `retries` times (default 2): it waits what
`Retry-After` asks for, otherwise 0.5 s, 1 s, 2 s… (capped at 30 s, with a little jitter). A
`Retry-After` longer than 60 seconds is not waited out: the error is thrown with `retryAfter` set,
for your queue to reschedule. Other `4xx` are thrown at once — fix the request instead. Each
attempt is bounded by `timeoutMs`.

## Versions

The package follows SemVer and is released on `sdk-node-vX.Y.Z` tags; see its `CHANGELOG.md`. Its
types are generated from the `/server/*` part of the API document (`pnpm openapi-types`), and CI
fails when they are stale, so a change to the server API shows up as a new SDK release.
