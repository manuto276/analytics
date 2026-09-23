# Server-side conversions

A conversion is reported by your backend, not by the browser, so it cannot be blocked, lost to a
closed tab or faked from the client. The API reference is
[../api/conversions.md](../api/conversions.md); this page is the integration recipe.

## What you need

1. A site with the **cookie level enabled** — attribution needs `attribution_touches`, which only
   exist for consented visitors. Without it, conversions are still counted, just unattributed.
2. An **API key** with the `conversions:write` scope, stored as a secret on the server:

   ```sh
   php bin/analytics api-key:create --site=pk_XXXXXXXXXXXXXXXXXXXXX --name=shop --scopes=conversions:write
   ```

   The secret is printed once. It must never reach a browser.
3. A **stable identifier** per conversion — the order number, the invoice id. It is the idempotency
   key.

## The visitor id

`an_vid` is a first-party cookie of the tracked site, written only after the visitor accepted.

### Same registrable domain

The checkout runs on `www.example.com` or `shop.example.com` and the cookie domain covers both: read
the cookie.

```php
$visitorId = $_COOKIE['an_vid'] ?? null;
if (!is_string($visitorId) || preg_match('/^[A-Za-z0-9_-]{22}$/', $visitorId) !== 1) {
    $visitorId = null;
}
```

```js
// Node / Express
const raw = req.cookies?.an_vid
const visitorId = /^[A-Za-z0-9_-]{22}$/.test(raw ?? '') ? raw : null
```

```js
// Node SDK: an IncomingMessage, an Express request or a Fetch Request
import { visitorIdFromRequest } from '@manuto276/analytics-node'
const visitorId = visitorIdFromRequest(req)
```

Always validate the format: it is attacker-controlled input, and an invalid value is rejected by the
API anyway.

### Different domain, or no cookie access

Pass it through your own form or API call.

```html
<form method="post" action="/checkout">
  <input type="hidden" data-analytics-visitor>
  …
</form>
```

The tracker fills that input after consent and clears it on rejection. Or read it in JavaScript:

```js
const vid = window.analytics?.getVisitorId?.() ?? null   // null without consent
await fetch('/api/checkout', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ ...order, visitor_id: vid })
})
```

### No visitor id at all

Send the conversion anyway. It is counted, and it can still be attributed later through
`customer_ref`.

## Sending it

```php
$payload = [
    'id'           => 'order-' . $order->id,       // idempotency key
    'name'         => 'purchase',                   // ^[a-z0-9_:.-]{1,64}$
    'occurred_at'  => $order->paidAt->format(DATE_ATOM),
    'visitor_id'   => $visitorId,                   // or omit
    'customer_ref' => hash('sha256', $order->customerId),  // any opaque, stable string
    'value'        => ['amount_minor' => $order->totalCents, 'currency' => 'EUR'],
    'props'        => ['plan' => $order->plan],
];

$ch = curl_init('https://stats.example.net/api/v1/server/sites/pk_XXXXXXXXXXXXXXXXXXXXX/conversions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . getenv('ANALYTICS_API_KEY'),
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
curl_exec($ch);   // 202 {"accepted":1,"duplicates":0,"rejected":[]}
```

In Node, the [Node SDK](sdk-node.md) (`@manuto276/analytics-node`, MIT) applies the same rules —
the payload, the `visitor_id` check, minor units, ISO 8601 — and retries `429`/`5xx` honouring
`Retry-After`:

```ts
import { AnalyticsApiError, createClient, visitorIdFromRequest } from '@manuto276/analytics-node'

const analytics = createClient({
  serviceUrl: 'https://stats.example.net',
  apiKey: process.env.ANALYTICS_API_KEY,        // conversions:write
  publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX',
})

try {
  const result = await analytics.conversions.send({
    id: `order-${order.id}`,                      // idempotency key
    name: 'purchase',
    occurred_at: order.paidAt,                    // Date, epoch ms or ISO 8601
    visitor_id: visitorIdFromRequest(req),        // dropped unless it is a valid an_vid
    customer_ref: order.customerId,
    value: { amount_minor: order.totalCents, currency: 'EUR' },
    props: { plan: order.plan },
  })                                              // {accepted: 1, duplicates: 0, rejected: []}
} catch (e) {
  if (e instanceof AnalyticsApiError && e.retryable) await jobs.retryLater(order.id)  // same id
  else throw e                                    // 4xx: fix the payload (e.code, e.errors)
}
```

WordPress has a helper: `analytics_connector_track_conversion()` — see [wordpress.md](wordpress.md).

## Making it reliable

- **Send it from a queue or a job**, not inline in the checkout request. A slow analytics service must
  never slow down an order.
- **Retry on failure.** `202` is the only success. Retry on a network error, `5xx` or `429` (honour
  `Retry-After`). Do not retry `4xx` other than `429` — fix the payload instead.
- **Reuse the same `id`.** A duplicate is reported as `duplicates`, never double-counted.
- **Batch backfills.** Up to 100 per request; each item reports its own outcome in `rejected[]`.
- `occurred_at` must be within the last 30 days and not more than an hour in the future, so a backfill
  older than a month is refused.

## `customer_ref`

An opaque, stable reference to the person — a customer id, or a hash of their e-mail address. It is
stored **only** as an HMAC-SHA-256 with a per-site subkey derived from `APP_SECRET`; it cannot be read
back, and the same value produces different hashes on different sites.

It buys one thing: when a later conversion arrives with no visitor id (a different device, a phone
order, a renewal), the attribution of that customer's earliest attributed conversion is copied onto
it. That is the "follow-up within the window" case.

Prefer a value you already have. Do not send a raw e-mail address if you can send an id.

## Attribution in short

1. Visitor touches — the visitor's first touch (`first_touch`) and their last non-direct touch
   (`last_non_direct`) before the conversion.
2. Otherwise the same `customer_ref`'s earliest attributed conversion.
3. Otherwise `unattributed`, still counted.

Attribution is snapshotted at insert time. Recompute after importing history or changing the model
with `php bin/analytics conversions:reattribute --site=… [--from=…]`.

Full rules: [../api/conversions.md](../api/conversions.md#attribution).

## Goals and funnels

A goal of type `conversion` matches conversions by name and contributes their value to the goals
report; funnel steps can mix pageview, event and conversion goals. Create them in Settings → Goals and
Settings → Funnels, or through the API.

## Costs, CAC and ROAS

Import campaign spend in Settings → Costs (manually or from CSV, with a dry-run preview). The
attribution report then reports cost, customer acquisition cost and return on ad spend per channel,
source or campaign, using `base`/`target` goal names to distinguish, for example, leads from
purchases.

## Checklist

- [ ] The key is a server-side secret, scoped `conversions:write`, and is rotatable
- [ ] `id` is your business identifier, not a random value
- [ ] The call happens after the payment is confirmed, off the request path
- [ ] Retries are in place for network errors, `5xx` and `429`
- [ ] `visitor_id` is validated before being sent
- [ ] `currency` matches the site's currency, or you accept a revenue of 0 for it
- [ ] The dashboard shows the conversion after the next `rollup:run` (up to 5 minutes)
