# Single-page applications

The tracker handles client-side routing without any framework integration.

## What it does

`services/tracker/src/spa.ts` patches `history.pushState` and `history.replaceState` and listens to
`popstate`. After each of them it compares the current URL with the last one it reported and, when
they differ, sends a pageview.

Before every such pageview the collector flushes an engagement event (`en`) for the page being left,
carrying the visible time and the deepest scroll percentage — so time-on-page works in an SPA exactly
as it does with full page loads.

By default the fragment is stripped from the URL, so `#section` does not create a pageview. With the
site's `hash_routing` switch on, the fragment is kept, `hashchange` is listened to as well, and a
fragment starting with `/` becomes part of the recorded path (`/app/#/orders`).

## Setting it up

Nothing to do for a router that uses the History API — Vue Router, React Router, Nuxt, Next, Angular,
SvelteKit, Turbo, htmx with `push-url`. Load the snippet once in the application shell:

```html
<script defer src="https://stats.example.net/t/pk_XXXXXXXXXXXXXXXXXXXXX.js"></script>
<script>window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}</script>
```

For a hash router (`#/orders`), switch the site to **hash routing** in Settings → Site.

## Manual pageviews

Some navigations are not URL changes — a wizard step, a modal that behaves like a page. Send them
explicitly:

```js
analytics.pageview({ url: '/checkout/step-2' })
```

The URL may be relative; it is resolved against the current location. Without arguments the current
URL is used, with the previous pageview URL as the referrer.

## Content keys

An SPA has no `<meta>` per route. Set the content key as part of the route change, before the
pageview:

```js
router.afterEach((to) => {
  analytics.setContent(to.meta.contentKey ?? null)
})
```

`setContent(null)` falls back to the `<meta name="analytics:content">` tag, if any.

## Consent in an SPA

The banner mounts into a shadow root prepended to `<body>`, outside the framework's root element, so
re-renders do not disturb it. It survives route changes. A framework that replaces `<body>` wholesale
would remove it — if that happens, call `analytics.consent.open()` after the swap.

`analytics.consent.onChange(cb)` is the hook for reacting to a decision (for example, enabling a chat
widget only after consent):

```js
const stop = analytics.consent.onChange((s) => {
  if (s.status === 'accepted') loadOptionalWidgets()
})
onUnmounted(stop)
```

## Duplicate and missing pageviews

| Symptom | Cause |
|---|---|
| Two pageviews per navigation | the router calls both `replaceState` and `pushState`, and the URLs differ (e.g. a redirect). The tracker skips only *consecutive identical* URLs |
| No pageview on a tab change | it is a fragment change and the site is not in hash-routing mode — intended |
| No pageview at all | the route is in the site's `excluded_paths`, or the whole page load is skipped (see [tracker.md](tracker.md#when-nothing-is-sent)) |
| Pageviews with the old URL | the router changed the URL *after* rendering; the tracker reads `location.href` at the moment `pushState` returns, which is correct for the History API |

## Engagement details

Visible time is accumulated between `visibilitychange` transitions, so a backgrounded tab does not
count. Scroll depth is the maximum percentage of `documentElement.scrollHeight` reached; a document
that does not scroll reports 100%. Both are reset at each pageview and flushed on `pagehide` and
before each SPA navigation.

## Server-side rendered applications

Nothing special: each document load fires its own pageview, and the History API patching simply never
triggers.

## Related

[tracker.md](tracker.md) · [consent-banner.md](consent-banner.md) ·
[../architecture/tracker.md](../architecture/tracker.md)
