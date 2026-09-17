# Tracker integration

The tracker is a single JavaScript file served by the analytics service at
`GET /t/{publicKey}.js`, with the site's configuration embedded. It has no dependencies, needs no
build step on your side, and is ≤ 5.0 KB gzipped including the consent banner.

The exact contract (configuration keys, transport, cookie formats) is
[../architecture/tracker.md](../architecture/tracker.md). This page is how to use it.

## Snippet

```html
<script defer src="https://stats.example.net/t/pk_XXXXXXXXXXXXXXXXXXXXX.js"></script>
<script>window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}</script>
```

The second line is the queue stub: calls made before the tracker finishes loading are buffered and
replayed. Drop it if you never call the API before `DOMContentLoaded`.

Get both lines for your site from the dashboard (Settings → Site) or from the console:

```sh
php bin/analytics site:show pk_XXXXXXXXXXXXXXXXXXXXX
```

Put them in `<head>`. `defer` keeps them off the critical path. Do not add `async` — with `defer` the
execution order of the two scripts is guaranteed.

Before anything is collected, the site's hosts must be registered under the site's domains (Settings →
Site, or `site:domain:add`); requests from any other origin are refused with `403 origin_not_allowed`.

## What is collected automatically

- **Pageviews** on load and on SPA navigation ([spa.md](spa.md)).
- **Engagement**: the time the page was actually visible and the deepest scroll percentage, sent as
  one `en` event on `pagehide` and before each SPA navigation.
- **Automatic events**, each behind its own per-site switch (Settings → Site → automatic events,
  served in `window.__an_cfg.auto`; all off by default). They are ordinary custom events, so they
  follow the same batching, skips (`navigator.webdriver`, excluded paths, DNT `no_tracking`, base
  tracking off) and limits as `analytics.track()`:

  | Switch | Event | Props | Fires on |
  |---|---|---|---|
  | `outbound` | `outbound_link` | `url` (absolute, 100 chars), `host` | click on the nearest `a[href]` with an `http(s)` link to another host, including middle-click and modifier-click |
  | `downloads` | `file_download` | `url`, `ext` (lower case) | click on a link whose path ends in `pdf, doc, docx, xls, xlsx, ppt, pptx, csv, zip, rar, 7z, gz, tar, dmg, pkg, exe, msi, apk, mp3, mp4, mov, avi, wav, txt, rtf, key, numbers, pages`, on any host |
  | `forms` | `form_submit` | `id` (form id, else name, else empty), `action` (path only) | `submit` on a `<form>`. Field values are never read |

  A download link is reported as `file_download` only (never also as `outbound_link`) when the
  downloads switch is on. `javascript:`, `mailto:`, `tel:` and in-page anchors are ignored.
  "Another host" means the link host is neither `location.hostname` nor a parent or child of it, so
  **sibling subdomains are outbound**: on `www.example.com`, a link to `app.example.com` is reported
  while `example.com` is not. Anything else you want measured goes through `analytics.track()` or a
  `data-analytics-event` attribute.

## JavaScript API

Available as `window.analytics` (or the name configured per site; see *Global name* below).

| Call | Effect |
|---|---|
| `analytics.track(name, props?)` | custom event. `name` must match `^[a-z0-9_:.-]{1,64}$` or the call is ignored. Up to 10 scalar props, keys < 33 characters, strings truncated at 100 |
| `analytics.pageview({url?})` | extra pageview. `url` may be relative; it is resolved against the current location. Without it, the current URL is used |
| `analytics.setContent(key)` | sets the content key for later events; `null` falls back to the `<meta>` tag |
| `analytics.getVisitorId()` | the `an_vid` value, or `null` without consent |
| `analytics.consent.open()` | reopens the banner |
| `analytics.consent.get()` | `{status: 'unknown'\|'accepted'\|'rejected', version, decidedAt}` |
| `analytics.consent.set('accepted'\|'rejected')` | records a choice made in your own UI |
| `analytics.consent.onChange(cb)` | subscribe; returns an unsubscribe function |
| `analytics.consent.forget()` | erases the visitor server-side, deletes the cookies, sets rejected |

```js
analytics.track('signup_click', { plan: 'pro', trial: true })
analytics.pageview({ url: '/virtual/step-2' })
analytics.setContent('author:42')

const stop = analytics.consent.onChange(s => console.log(s.status))
```

Invalid inputs are dropped silently rather than thrown — the tracker never breaks a page.

## Declarative attributes

No JavaScript needed.

| Markup | Effect |
|---|---|
| `<button data-analytics-event="signup_click" data-analytics-prop-plan="pro">` | a click sends the event with `{plan: "pro"}`. Every `data-analytics-prop-*` attribute becomes a property |
| `<a href="#analytics-consent">Cookie settings</a>` or any `[data-analytics-consent]` | a click opens the consent preferences (default prevented) |
| `<input type="hidden" data-analytics-visitor>` | filled with the visitor id after consent, cleared on rejection — the simplest way to get the id into a form post |
| `<meta name="analytics:content" content="author:42">` | sets the content key for the page |

Clicks are handled on `document`, so elements added later work, and `closest()` means a click on an
icon inside a button still counts.

## Content groups

A content key groups pages that belong together regardless of their URL — an author, a category, a
product line. Set it with the `<meta>` tag, or with `analytics.setContent()` in an SPA. It appears in
the Content report and in the server-side content stats endpoint
([../api/conversions.md](../api/conversions.md#content-stats)).

Events named in the site's `content_contact_events` list count as "contacts" for the content key they
were fired on, which is how "this author's pages produced 17 enquiries" is measured.

## Transport

The tracker queues events and flushes after 1 second of inactivity, at 10 queued events, on
`visibilitychange = hidden` and on `pagehide`. A flush is at most 50 events per request; base-level
and cookie-level events always go in separate requests.

It uses `navigator.sendBeacon(url, new Blob([json], {type: 'text/plain'}))`, falling back to
`fetch(url, {method: 'POST', keepalive: true, credentials: 'omit', headers: {'Content-Type': 'text/plain'}})`
with **one** retry on a network error or a 5xx. `text/plain` avoids a CORS preflight; `credentials:
'omit'` means your site's cookies are never sent to the analytics service.

The endpoint is derived from the script's own `src` with the filename removed, so a
[first-party proxy](first-party-proxy.md) works without configuration.

## When nothing is sent

The tracker stays completely silent when:

- `navigator.webdriver` is true (automated browsers — this trips up end-to-end tests: see
  [../development/testing.md](../development/testing.md));
- the page is `file:` or on localhost, unless the site has `allow_localhost`;
- the path matches one of the site's `excluded_paths` globs;
- `DNT: 1` and the site's `dnt_mode` is `no_tracking`;
- there is no configuration or no site key.

During prerendering it waits for activation (`prerenderingchange`) before initialising.

At the base level it also sends nothing when the site has `base_tracking_enabled = false` — except
consent statistics, which are counters.

## Global name

Default `window.analytics`. If something else already owns that name and is not a queue stub, the
tracker installs itself at `window.__analytics` instead. Configure a different name per site
(`tracker_global`, a valid JavaScript identifier) when you know there is a clash; the snippet from the
dashboard uses the configured name.

## Consent

If the site has the cookie level enabled and a published consent configuration, the tracker shows the
banner itself and manages the cookies. See [consent-banner.md](consent-banner.md) and
[../privacy/cookies.md](../privacy/cookies.md).

Until the visitor decides, the tracker writes nothing to the browser: no cookie, no `localStorage`, no
`sessionStorage`, no IndexedDB.

## Content Security Policy

On the tracked site, allow the script and the endpoint:

```
script-src 'self' https://stats.example.net;
connect-src 'self' https://stats.example.net;
```

With a [first-party proxy](first-party-proxy.md), `'self'` alone is enough.

The banner uses a shadow root with `adoptedStyleSheets`, falling back to a `<style>` element, so it
works under a strict CSP without `style-src 'unsafe-inline'`. The end-to-end suite has a
strict-CSP fixture (`services/e2e/fixtures/www.site.test/strict-csp.html`) for exactly this.

## Verifying an installation

1. Load a page and check the network tab: `GET /t/pk_….js` should be `200` (or `304`), and a
   `POST /t/e` should appear within a second or two with status `202`.
2. `403 origin_not_allowed` means the host is not registered under the site's domains.
3. `404` on the script means the public key is wrong or the site is archived.
4. Nothing at all: check the exclusion list above — most often `navigator.webdriver` or an ad blocker.
5. In the dashboard, Realtime shows an active visitor within a few seconds. Other reports only update
   after `rollup:run`, which runs every 5 minutes ([../operations/cron.md](../operations/cron.md)).

## Related

[consent-banner.md](consent-banner.md) · [spa.md](spa.md) ·
[server-side-conversions.md](server-side-conversions.md) · [first-party-proxy.md](first-party-proxy.md) ·
[caching-proxies.md](caching-proxies.md) · [wordpress.md](wordpress.md)
