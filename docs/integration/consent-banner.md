# Consent banner

The banner is part of the tracker. There is no second script to load and no third-party consent
platform. It appears only when the site has `cookie_level_enabled` **and** a published consent
configuration.

## States

The tracker reads the `an_consent` cookie at startup and derives one of three states
(`services/tracker/src/consent.ts`):

| State | When | Behaviour |
|---|---|---|
| `unknown` | no cookie, malformed cookie, cookie version **lower** than the published `consent_version`, or the TTL has elapsed | banner shown; base level only; a `cs: shown` counter is sent |
| `accepted` | cookie says `a`, version ≥ published, within `accepted_ttl_days` | cookie level; `an_vid`/`an_sid` created or refreshed; no banner; floating reopen button if configured |
| `rejected` | cookie says `r`, within `rejected_ttl_days` | base level only; `an_vid`/`an_sid` deleted; no banner until the TTL elapses or the version increases |

A cookie whose version is **higher** than the published one is still honoured.

Three conditions force `rejected` regardless of the cookie, and suppress the banner entirely:
`Sec-GPC`/`navigator.globalPrivacyControl` when the site has `respect_gpc`; `DNT: 1` when
`dnt_mode = no_cookie`; and anything that disables tracking for the page load.

### Transitions

| Action | Cookie written | Events sent |
|---|---|---|
| Accept | `an_consent` = `1.<v>.a.<day>`, `an_vid`, `an_sid` | `cs: accept` (base), then `cu` (cookie level) with the landing URL and referrer |
| Reject | `an_consent` = `1.<v>.r.<day>`; `an_vid`/`an_sid` deleted everywhere | `cs: reject` (base) |
| Close `×` or Escape | same as Reject | `cs: dismiss` (base) |
| Reopen | — | `cs: reopen` (base) |
| `consent.forget()` | cookies deleted | `POST /t/forget`, then the reject path |

Counters land in `consent_stats_daily` per day and consent version and are shown by the consent
report. They contain no identifier.

## Texts

Per locale, all required:

| Key | Max length | Shown as |
|---|---|---|
| `title` | 120 | `<h2>` |
| `body` | 1200 | `<p>` |
| `accept` | 40 | button label |
| `reject` | 40 | button label |
| `close` | 40 | `aria-label` of the `×` button |
| `policy` | 60 | link label; the URL comes from `policy_urls[locale]` |
| `reopen` | 60 | floating button label |

English and Italian defaults ship in `ConsentService::defaults()`. Locale codes look like `en` or
`pt-BR`. The banner picks the language from `<html lang>` (primary subtag), falls back to
`default_locale`, then to the first locale with texts.

A policy link is rendered only when `policy_urls` holds an `http(s)` URL (or a site-relative one) for
that locale; otherwise the label is shown as plain text.

## Theming

| Key | Meaning |
|---|---|
| `bg` | background, `#rrggbb` |
| `fg` | text, `#rrggbb` |
| `ac` | accent / button background, `#rrggbb` |
| `acf` | text on the accent, `#rrggbb` |
| `rad` | corner radius, 0–24 |
| `pos` | `bottom`, `bottom-left`, `bottom-right` |

The API **refuses** to save a theme whose `fg`/`bg` or `acf`/`ac` contrast is below 4.5:1
(`ContrastChecker::MINIMUM`), with a `422 validation_failed` naming the field. Fonts are inherited
from the page.

The banner lives in an open shadow root (`<div data-analytics-banner>` prepended to `<body>`) with
`adoptedStyleSheets` and a `<style>` fallback, so page styles cannot break it and a strict CSP does
not block it. It supports `prefers-reduced-motion` and `forced-colors`.

## Accessibility and fairness

- Accept and Reject are native `<button>` elements with the same class and therefore identical
  styling and weight.
- Close (`×`) and Escape count as a rejection, never as acceptance. Scrolling is never consent.
- No blocking overlay: the page stays usable.
- `role="dialog"`, `aria-modal="false"`, `aria-labelledby`/`aria-describedby`, `tabindex="-1"` on the
  dialog. When opened by the visitor it takes focus and returns it on close; when shown automatically
  it does not steal focus.
- Covered by `services/tracker/test/banner.test.ts` and by axe checks in the end-to-end suite.

## Reopening

Three ways, all equivalent:

- `analytics.consent.open()`;
- any element with `data-analytics-consent`, or a link to `#analytics-consent` — handy for a footer
  link or a menu item;
- the floating button, when `show_floating_reopen` is on. It is rendered after a choice has been
  made, and on later page loads.

## Editing and publishing

Dashboard: **Settings → Consent** (permission `site:manage`). API:

| Route | Purpose |
|---|---|
| `GET /api/v1/sites/{siteId}/consent` | current published configuration and draft |
| `PUT /api/v1/sites/{siteId}/consent/draft` | create or update the draft |
| `DELETE /api/v1/sites/{siteId}/consent/draft` | discard the draft |
| `POST /api/v1/sites/{siteId}/consent/publish` | publish, body `{"material_change": true\|false}` |
| `GET /api/v1/sites/{siteId}/consent/history` | published and archived revisions |

The editor shows a live preview rendered with the real banner code and refuses to save a failing
contrast ratio.

## Versioning

Two numbers, and the difference matters:

- **`revision`** increments on every publish. It is bookkeeping; it does not affect visitors.
- **`consent_version`** increments **only** when you publish with `material_change: true`. Every
  visitor whose cookie records a lower version returns to `unknown` and is asked again.

The first publish sets `consent_version` to 1. The previously published revision becomes `archived`;
published revisions are never modified, so the exact text a visitor agreed to remains on record with
`published_at` and `published_by`. Publishing is written to the audit log (`consent.published`).

Use `material_change: true` when the purposes, the recipients, the retention or the cookies change.
Use `false` for a typo or a colour.

## Propagation

The published configuration is embedded in `GET /t/{publicKey}.js` and the response `ETag` changes
with it, so browsers pick it up on their next revalidation. The script is cached
`public, max-age=300, stale-while-revalidate=600`, and the server caches the consent block for 60
seconds, so a publish reaches visitors within roughly five to ten minutes. Publishing also clears the
cached block immediately.

## Consent statistics

The consent report (`GET …/reports/consent`) gives, per day and version: `shown`, `accepted`,
`rejected`, `dismissed`, `reopened`, plus an overall acceptance rate
(`accepted / (accepted + rejected + dismissed)`). The overview's `consent_rate` is the share of visits
at the cookie level.

## Using your own banner instead

**Not supported today.** There is no switch that keeps the cookie level but suppresses the built-in
banner: the tracker treats "cookie level enabled and a published configuration exists" as the single
condition for both. Without a published configuration, `analytics.consent.set()` and
`analytics.consent.open()` are no-ops and the tracker stays at the base level
(`services/tracker/test/consent.test.ts` → "cookie level disabled: no banner, API choices are
no-ops").

If you already run a consent management platform, the options are:

- publish a configuration here and let this banner be the one that asks — it is the only path that
  produces `an_consent`, the counters and the versioning; or
- leave the cookie level off and stay at the base level, using your platform for everything else.

`analytics.consent.set()` remains useful for driving the state from your own UI **in addition to** a
published configuration — for example a "manage cookies" page that replaces the banner's buttons.

## Related

[../privacy/cookies.md](../privacy/cookies.md) · [../privacy/two-levels.md](../privacy/two-levels.md) ·
[../architecture/tracker.md](../architecture/tracker.md) · [tracker.md](tracker.md)
