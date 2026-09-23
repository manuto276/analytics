# @manuto276/analytics-browser

A typed client for the [analytics](https://github.com/manuto276/analytics) tracker, for sites that are
built with a bundler: it loads the tracker once, queues calls until it is ready, and has bindings
for React and Vue. It works during server rendering (Next.js, Nuxt) by doing nothing there.

The tracker itself is still served by your analytics service (`GET /t/{publicKey}.js`, with your
site's configuration and consent banner). This package contains no tracker code; it only inserts
that script, with the same queue contract as the snippet.

MIT licensed. Full guide: [docs/integration/sdk-browser.md](https://github.com/manuto276/analytics/blob/main/docs/integration/sdk-browser.md).

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
npm install @manuto276/analytics-browser
```

## Use

```ts
import { load } from '@manuto276/analytics-browser';

const analytics = load({
  serviceUrl: 'https://stats.example.net',
  publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX',
  // globalName: 'analytics',  // the global name configured for the site, if not the default
  // proxyPath: '/stats/',     // load through a first-party proxy on this origin instead
});

analytics.track('signup_click', { plan: 'pro' });
analytics.pageview({ url: '/virtual/step-2' });
analytics.consent.onChange((s) => console.log(s.status));

if (await analytics.ready) {
  console.log(analytics.getVisitorId()); // null without consent
}
```

`load()` is idempotent: call it wherever it is convenient; the second call returns the first
client. `ready` resolves to `false` (it never rejects) when the script is blocked or the site has
tracking off.

### React

```tsx
import { AnalyticsProvider, useAnalytics, useConsent } from '@manuto276/analytics-browser/react';

<AnalyticsProvider serviceUrl="https://stats.example.net" publicKey="pk_XXXXXXXXXXXXXXXXXXXXX">
  <App />
</AnalyticsProvider>;

function CookieSettings() {
  const consent = useConsent(); // re-renders on every change
  return <button onClick={consent.open}>Cookies: {consent.status}</button>;
}
```

### Vue

```ts
import { createAnalytics, useConsent } from '@manuto276/analytics-browser/vue';

app.use(createAnalytics({ serviceUrl: 'https://stats.example.net', publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX' }));

// in setup():
const { status, open } = useConsent();
```

### `window.analytics`

Importing the package types `window.analytics` (and `window.__analytics`). For a custom global name:

```ts
import type { AnalyticsGlobals } from '@manuto276/analytics-browser';

declare global {
  interface Window extends AnalyticsGlobals<'stats'> {}
}
```

## License

MIT, see [LICENSE](LICENSE). The analytics service and the tracker are AGPL-3.0-or-later; this
package contains none of their code (see ADR 0010 in the repository).
