# Cookies

The analytics service domain (`APP_URL`, e.g. `stats.example.net`) **never sets a cookie**.
`NoCookiesGuardMiddleware` strips `Set-Cookie` from every `/t/*` response and clears the incoming
`Cookie` header, and the tracker sends its requests with `credentials: 'omit'`.

All three cookies below are **first-party cookies of the tracked site**, written by the tracker with
`document.cookie` from a page on that site. Source: `services/tracker/src/cookies.ts` and
`services/tracker/src/consent.ts`; contract: [../architecture/tracker.md](../architecture/tracker.md).

There is a fourth, `an_probe`, which exists for a few milliseconds during the domain probe and is
deleted immediately (see below). It is described here for completeness; it carries no information.

## The three cookies

| Name | Written when | Value | Lifetime | Attributes |
|---|---|---|---|---|
| `an_consent` | only after the visitor accepts or rejects | `1.<consentVersion>.<a\|r>.<decidedAt>`, where `decidedAt` is the day number since the epoch in base 36 — e.g. `1.3.a.h4x` | accepted: `accepted_ttl_days` (default 180); rejected: `rejected_ttl_days` (default 180) | `Domain=<cookie domain>; Path=/; Max-Age=…; SameSite=Lax; Secure` |
| `an_vid` | only after "Accept" | 22 characters base64url = 16 random bytes from `crypto.getRandomValues` | `visitor_cookie_days`, capped at 395 days; **not** extended on later visits | same |
| `an_sid` | only after "Accept" | 22 characters base64url = 16 random bytes | 1800 seconds, refreshed on every batch (sliding) | same |
| `an_probe` | only while probing the cookie domain, when `cookie_domain` is not configured | `1` | 60 seconds, deleted within the same tick | same |

`Secure` is omitted only when the page is on `http:` (development and localhost). There is no
`HttpOnly`: these cookies are written and read by the tracker in the page.

### `an_consent` in detail

```
1        . 3              . a            . h4x
version    consentVersion   a=accepted     epoch-days, base 36
of the     of the published  r=rejected     when the choice was made
format     configuration
```

It is treated as "unknown" (banner shown again) when it is missing, malformed, when its
`consentVersion` is **lower** than the published one, or when `today - decidedAt` has reached the
relevant TTL. A cookie with a *higher* version than the published one is still accepted.

### Nothing before a choice

Before the visitor decides, the tracker only **reads** `an_consent`. It writes no cookie, and touches
neither `localStorage`, `sessionStorage` nor IndexedDB. Pinned by
`services/tracker/test/storage.test.ts` — "never touches cookies, Web Storage or IndexedDB until the
visitor decides".

## Cookie domain

Set the site's `cookie_domain` when the tracked site spans several hosts (`www.example.com` and
`app.example.com` sharing one visitor id). The tracker then uses `Domain=.example.com`.

When it is not configured, the tracker probes: it takes the candidate suffixes of
`location.hostname` from longest to shortest, never including a bare TLD and never for an IP address
or a single-label host, writes `an_probe=1` on each, checks whether it came back, deletes it, and
keeps the widest suffix that worked. If none works, cookies are host-only (no `Domain` attribute).

The probe is the only way to discover the registrable domain in the browser without shipping a public
suffix list; the alternative is to configure `cookie_domain` explicitly, which is what production
sites should do. `SiteService` validates that `cookie_domain` is one of the site's domains or a parent
of them.

## Deletion

- **Reject** (button, Close `×`, or Escape) deletes `an_vid` and `an_sid` on the host and on every
  candidate domain — `wipe()` in `cookies.ts` — and writes `an_consent` with `r`.
- **GPC**, `dnt_mode = no_cookie` with `DNT: 1`, or a site with the cookie level switched off do the
  same at startup and never show the banner.
- **`analytics.consent.forget()`** deletes the cookies and additionally calls `POST /t/forget`, which
  erases the visitor's cookie-level rows on the server.

## What to put in a cookie notice

| Cookie | Purpose | Type | Duration |
|---|---|---|---|
| `an_consent` | stores the visitor's choice about analytics cookies and the version of the notice it was given for | technical / consent record | up to 180 days (configurable) |
| `an_vid` | recognises a returning visitor for aggregate analytics and campaign attribution | analytics, consent required | up to 395 days (configurable), not extended |
| `an_sid` | groups the pages of one visit | analytics, consent required | 30 minutes, sliding |

First party, set by the tracked site, not readable by the analytics service domain, not shared with
any third party. No profiling, no cross-site tracking, no advertising use. The exact wording is the
site operator's responsibility — **LEGAL REVIEW**, see [legal-review-points.md](legal-review-points.md).

## Interaction with page caches

`an_*` cookies must **not** be used to vary or bypass a page cache: the HTML is identical for every
anonymous visitor and the cookies are read by JavaScript in the page. See
[../integration/caching-proxies.md](../integration/caching-proxies.md).

## Dashboard cookies (not visitor cookies)

The dashboard, on the service domain, uses its own session cookie for signed-in operators:
`__Host-an_session` (or `an_session` when `APP_URL` is not https), `Path=/`, `HttpOnly`,
`SameSite=Lax`, `Secure`, max-age until the session's absolute expiry (30 days). It is strictly
necessary for authentication and has nothing to do with the tracked sites. The dashboard also stores a
locale preference cookie (`i18n_locale`) client-side.
