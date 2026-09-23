# @manuto276/analytics-node

A client for the server API of [analytics](https://github.com/manuto276/analytics): send server-side
conversions, read content stats and reports. Node 18 or later (any runtime with `fetch` works), no
dependencies, typed from the API's OpenAPI document.

MIT licensed. Full guide: [docs/integration/sdk-node.md](https://github.com/manuto276/analytics/blob/main/docs/integration/sdk-node.md).

## Install

The package is published to GitHub Packages. Tell npm (or pnpm, or yarn) where the `@manuto276`
scope lives, in the project's `.npmrc`:

```ini
@manuto276:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GITHUB_TOKEN}
```

GitHub Packages needs a token even for public packages: a personal access token (classic) with
`read:packages`, exported as `GITHUB_TOKEN` (in CI, the workflow's `GITHUB_TOKEN` with
`packages: read` works). Then:

```sh
npm install @manuto276/analytics-node
```

## Use

```ts
import { AnalyticsApiError, createClient, visitorIdFromRequest } from '@manuto276/analytics-node';

const analytics = createClient({
  serviceUrl: 'https://stats.example.net',
  apiKey: process.env.ANALYTICS_API_KEY!, // scopes: conversions:write, stats:read, reports:read
  publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX',
});

// Express: after the payment is confirmed
app.post('/checkout/complete', async (req, res) => {
  const order = await completeOrder(req);
  await analytics.conversions.send({
    id: `order-${order.id}`, // idempotency key
    name: 'purchase',
    visitor_id: visitorIdFromRequest(req), // the an_vid cookie, or null
    customer_ref: order.customerId,
    value: { amount_minor: order.totalCents, currency: 'EUR' },
  });
  res.sendStatus(204);
});

const { data } = await analytics.reports.overview({ period: '7d' });
const stats = await analytics.content.stats('author:42', { days: 30 });
```

Errors are `AnalyticsApiError` (`status`, `code`, `title`, `detail`, `errors`, `retryAfter`), built
from the API's RFC 9457 problem documents. Network errors, timeouts, `429` and `5xx` are retried
(`retries`, default 2) with backoff, honouring `Retry-After`.

## License

MIT, see [LICENSE](LICENSE). The analytics service is AGPL-3.0-or-later; this package contains
none of its code (see ADR 0010 in the repository).
