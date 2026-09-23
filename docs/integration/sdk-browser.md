# Browser SDK (`@manuto276/analytics-browser`)

For sites built with a bundler — plain JavaScript, React, Vue, Next.js, Nuxt — the browser SDK loads
the tracker and gives you a typed client. It is the snippet from [tracker.md](tracker.md) as a
package: the same script, the same queue, the same API, with types and framework bindings.

- **It contains no tracker code.** The tracker (with your site's configuration and consent banner)
  is still `GET /t/{publicKey}.js` from your analytics service, or your
  [first-party proxy](first-party-proxy.md). The SDK inserts that `<script>`.
- **MIT licensed**, so it can be bundled into any application
  ([ADR 0010](../architecture/adr/0010-sdk-packages.md)).
- No runtime dependencies; ES modules, CommonJS and type declarations; React and Vue are optional
  peer dependencies used only by their subpaths.

## Install

The package is on GitHub Packages. Map the `@manuto276` scope to it in the project's `.npmrc`:

```ini
@manuto276:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GITHUB_TOKEN}
```

GitHub Packages asks for a token even for public packages:

- **Locally:** a personal access token (classic) with the `read:packages` scope, exported as
  `GITHUB_TOKEN` in your shell (do not commit the token itself — the `${GITHUB_TOKEN}` above is read
  from the environment).
- **In GitHub Actions:** the workflow's own token, with `permissions: packages: read`, passed as
  `GITHUB_TOKEN` (or `NODE_AUTH_TOKEN` with `actions/setup-node`'s `registry-url`).
- **Other CI and hosting (Vercel, Netlify, …):** a `read:packages` token as a build secret named
  `GITHUB_TOKEN`.

```sh
npm install @manuto276/analytics-browser
# or: pnpm add @manuto276/analytics-browser  /  yarn add @manuto276/analytics-browser
```

## Plain JavaScript

```ts
import { load } from '@manuto276/analytics-browser';

export const analytics = load({
  serviceUrl: 'https://stats.example.net',
  publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX',
});

analytics.track('signup_click', { plan: 'pro', trial: true });
analytics.pageview({ url: '/virtual/step-2' });
analytics.setContent('author:42');

const stop = analytics.consent.onChange((s) => console.log(s.status));
document.querySelector('#cookie-settings')?.addEventListener('click', () => analytics.consent.open());
```

### Options

| Option | |
|---|---|
| `publicKey` | the site's public key (`pk_` + 21 characters) |
| `serviceUrl` | the analytics service, e.g. `https://stats.example.net`; the script is `{serviceUrl}/t/{publicKey}.js` |
| `proxyPath` | a first-party proxy path on the site, e.g. `/stats/`; the script becomes `/stats/{publicKey}.js` on the page's own origin. Takes precedence over `serviceUrl` (which may then be omitted) |
| `globalName` | the global name configured for the site on the service (Settings → Site); default `analytics` |
| `nonce` | a CSP nonce for the inserted `<script>` |

Invalid options (a malformed key, a global name that is not a JavaScript identifier, a relative
`serviceUrl`) throw a `TypeError` at once — they are configuration errors, not runtime conditions.

### What `load()` does

- On the first call for a global name, it installs the queue stub (`window.analytics = {q: [...]}`
  with every method that returns nothing) and appends one `<script defer src="…">` to `<head>`.
  Further calls — from other modules, re-renders, hot reloads — return the same client and insert
  nothing.
- If the page already has the snippet, or the tracker is already running, it inserts nothing and
  uses what is there.
- If `window.analytics` is taken by something else, the tracker installs itself at
  `window.__analytics`; the SDK follows the same rule, so the client keeps working
  (`client.globalName` says where).
- Without `window` (server rendering, tests in Node) it inserts nothing and returns a client whose
  calls do nothing.

### The client

| Member | |
|---|---|
| `track(name, props?)` | custom event (rules in [tracker.md](tracker.md#javascript-api)) |
| `pageview({ url? })` | an extra pageview |
| `setContent(key)` | the content key for later events |
| `getVisitorId()` | the `an_vid` value, or `null` without consent or before the tracker has loaded |
| `consent.open()` / `consent.set('accepted' \| 'rejected')` / `consent.forget()` | as in the tracker |
| `consent.get()` | the current state; `{status: 'unknown', …}` until the tracker has loaded |
| `consent.onChange(cb)` | subscribe; returns the unsubscribe function. Works before the tracker loads |
| `ready` | a promise: `true` once the tracker runs, `false` if the script was blocked, failed, the site has tracking off, or on the server. It never rejects |
| `globalName` | where the tracker is (or will be) installed |

Calls made before the tracker loads go into the stub's queue and the tracker replays them in order
when it starts — the same contract as the snippet (`services/tracker/src/api.ts`). Methods that
return a value (`getVisitorId`, `consent.get`) answer from what is known now.

```ts
if (await analytics.ready) {
  const vid = analytics.getVisitorId(); // pass it to your backend for server-side conversions
}
```

### Typings for `window.analytics`

Importing the package types `window.analytics` and `window.__analytics` as the tracker or its
stub, so `window.analytics?.track?.('x')` type-checks. For a custom global name:

```ts
import type { AnalyticsGlobals, AnalyticsWindow } from '@manuto276/analytics-browser';

declare global {
  interface Window extends AnalyticsGlobals<'stats'> {}
}
window.stats?.track?.('signup');

// or for a one-off cast
(window as AnalyticsWindow<'stats'>).stats;
```

## React

```tsx
// main.tsx
import { AnalyticsProvider } from '@manuto276/analytics-browser/react';

createRoot(document.getElementById('root')!).render(
  <AnalyticsProvider serviceUrl="https://stats.example.net" publicKey="pk_XXXXXXXXXXXXXXXXXXXXX">
    <App />
  </AnalyticsProvider>,
);
```

```tsx
import { useAnalytics, useConsent } from '@manuto276/analytics-browser/react';

function Pricing() {
  const analytics = useAnalytics();
  return <button onClick={() => analytics.track('plan_selected', { plan: 'pro' })}>Choose Pro</button>;
}

function CookieSettings() {
  const consent = useConsent(); // re-renders on consent.onChange and when the tracker loads
  return (
    <p>
      Cookies: {consent.status}
      <button onClick={consent.open}>Change</button>
    </p>
  );
}
```

`AnalyticsProvider` takes the `load()` options as props, or `client={…}` from a `load()` call of
your own. `useConsent()` returns `{status, version, decidedAt, open, set, forget}`; it is built on
`useSyncExternalStore` with `unknown` as the server snapshot, so hydration never mismatches.

### Next.js (App Router)

The React entry is marked `'use client'`, so the provider can be used from a server layout
directly. Put it in a small client component if you prefer to keep the options in one place:

```tsx
// app/analytics.tsx
'use client';
import { AnalyticsProvider } from '@manuto276/analytics-browser/react';

export function Analytics({ children }: { children: React.ReactNode }) {
  return (
    <AnalyticsProvider
      serviceUrl={process.env.NEXT_PUBLIC_ANALYTICS_URL!}
      publicKey={process.env.NEXT_PUBLIC_ANALYTICS_KEY!}
    >
      {children}
    </AnalyticsProvider>
  );
}

// app/layout.tsx (a server component)
import { Analytics } from './analytics';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <Analytics>{children}</Analytics>
      </body>
    </html>
  );
}
```

On the server the provider renders its children and does nothing else; in the browser it inserts
the script once. The tracker detects client-side navigations itself ([spa.md](spa.md)), so no
router hook is needed. The Pages Router works the same way from `_app.tsx`.

## Vue

```ts
// main.ts
import { createApp } from 'vue';
import { createAnalytics } from '@manuto276/analytics-browser/vue';
import App from './App.vue';

createApp(App)
  .use(createAnalytics({ serviceUrl: 'https://stats.example.net', publicKey: 'pk_XXXXXXXXXXXXXXXXXXXXX' }))
  .mount('#app');
```

```vue
<script setup lang="ts">
import { useAnalytics, useConsent } from '@manuto276/analytics-browser/vue';

const analytics = useAnalytics();
const { status, open } = useConsent();
</script>

<template>
  <button @click="analytics.track('plan_selected', { plan: 'pro' })">Choose Pro</button>
  <p>Cookies: {{ status }} <button @click="open">Change</button></p>
</template>
```

The client is also `this.$analytics` in the Options API. `useConsent()` returns `state` (a readonly
ref of the whole state), `status` (a computed), `open`, `set` and `forget`; inside a component it
starts as `unknown` and is read on mount, so server and client render the same markup, and it stops
listening when the component (or effect scope) is disposed. Outside components pass the client:
`useConsent(client)`.

### Nuxt

```ts
// plugins/analytics.ts
import { createAnalytics } from '@manuto276/analytics-browser/vue';

export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig().public;
  const plugin = createAnalytics({ serviceUrl: config.analyticsUrl, publicKey: config.analyticsKey });
  nuxtApp.vueApp.use(plugin);
  return { provide: { analytics: plugin.client } };
});
```

The plugin can run on both sides: during SSR the client does nothing and `useConsent()` renders
`unknown`; in the browser it loads the tracker. `useNuxtApp().$analytics` is the client.

## Content Security Policy

The inserted script needs the service (or your proxy's origin) in `script-src`, and its requests
need it in `connect-src`, exactly as for the snippet. With a nonce-based policy, pass `nonce`.
The SDK itself adds no inline script.

## Versions

The package follows SemVer and is released on `sdk-browser-vX.Y.Z` tags; see its `CHANGELOG.md`.
It works with any service version that serves the tracker with the same queue contract; the
tracker's API types are generated from the tracker's source, so a change there shows up as a new
SDK release.
