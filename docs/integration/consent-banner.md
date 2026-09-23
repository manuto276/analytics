# Consent banner

The banner is part of the tracker. There is no second script to load and no third-party consent
platform: for sites with `cookie_level_enabled` the service puts the banner module into the same
`/t/{publicKey}.js` response ([../architecture/tracker.md](../architecture/tracker.md)); sites
without it get the smaller core alone. It appears only when the site has `cookie_level_enabled`
**and** a published consent configuration.

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

The theme is JSON, validated and compiled by the service into the banner's stylesheet (sent as
`consent.css` in the tracker configuration). The tracker only builds the DOM; everything visual comes
from the compiled stylesheet, inside the banner's shadow root, so page styles cannot break it and it
cannot leak into the page. No request leaves the page for the banner: fonts are never downloaded and
there are no images other than the inline reopen icon.

### Theme v2

Schema: [../api/consent-theme.v2.schema.json](../api/consent-theme.v2.schema.json) (`ConsentThemeV2`
in [../api/openapi.yaml](../api/openapi.yaml)). Every field is optional on write — missing fields
take the defaults below — and the stored theme is complete.

| Field | Values | Default |
|---|---|---|
| `colors.background` | `#rrggbb` | `#ffffff` |
| `colors.text` | `#rrggbb` | `#111827` |
| `colors.accent` | `#rrggbb` — Accept **and** Reject background/border, focus ring | `#1d4ed8` |
| `colors.accentText` | `#rrggbb` — text on Accept and Reject | `#ffffff` |
| `colors.border` | `#rrggbb` — banner border (see `border.width`) | `#e5e7eb` |
| `colors.link` | `#rrggbb` — privacy policy link | `#1d4ed8` |
| `colors.backdrop` | `#rrggbb`, `#rrggbbaa` or `null` — tint around a `center` banner; painted only, the page stays usable | `null` |
| `font.family` | `inherit` (the page font), `system` (system-font stack), or a list of family names (letters, digits, spaces, `-`, `_`, optionally quoted; no URLs) | `inherit` |
| `font.size` | 12–20 (px) or `null` (the page size) | `null` |
| `font.lineHeight` | 1–2 | `1.5` |
| `shape.radius` | 0–32 (px), banner corners | `8` |
| `shape.buttonRadius` | 0–999 (px; 999 = pill), Accept/Reject and reopen button | `8` |
| `spacing.padding` | 8–48 (px) | `16` |
| `spacing.gap` | 0–32 (px), between the buttons | `8` |
| `border.width` | 0–4 (px) | `0` |
| `shadow` | `none`, `sm`, `md`, `lg` | `md` |
| `layout.breakpoint` | 480–1024 (px): viewports this wide or narrower use the mobile layout | `640` |
| `layout.desktop.position` | `bottom`, `bottom-left`, `bottom-right`, `top`, `center` | `bottom` |
| `layout.desktop.maxWidth` | 280–1200 (px) | `576` |
| `layout.desktop.offset` | 0–64 (px) from the viewport edges | `16` |
| `layout.desktop.buttons` | `row`, `stack` | `row` |
| `layout.mobile.position` | `bottom`, `top`, `center`, `sheet` | `bottom` |
| `layout.mobile.offset` | 0–32 (px) | `16` |
| `layout.mobile.buttons` | `row`, `stack` | `row` |
| `reopen.icon` | `cookie`, `shield`, `fingerprint`, `settings` | `cookie` |
| `reopen.size` | `sm`, `md`, `lg` | `md` |
| `reopen.background` / `reopen.text` | `#rrggbb` or `null` (= `colors.accent` / `colors.accentText`) | `null` |
| `reopen.desktop` / `reopen.mobile` | `{variant: text\|icon\|icon-text\|hidden, position: bottom-left\|bottom-right, offset: 0–64}` | desktop `text`, mobile `icon`; `bottom-left`, `16` |
| `css` | custom CSS, at most 8192 bytes ([below](#custom-css)) | `""` |

There is **one** button style: Accept and Reject always look the same (Garante 2021, B2). There is
deliberately no "secondary" colour.

The API **refuses** (`422 validation_failed`, naming the field) any value out of range, and any
colour pair below 4.5:1 contrast (`ContrastChecker::MINIMUM`): `colors.text` on `colors.background`,
`colors.accentText` on `colors.accent`, `colors.link` on `colors.background`, and the reopen
button's text on its background (`theme.reopen.text`). Theme edits are never material: publish them
with `material_change: false` and nobody is asked again.

### Mobile layouts

At or below `layout.breakpoint` the banner uses `layout.mobile`, whatever the desktop position:

| `layout.mobile.position` | Result |
|---|---|
| `bottom` | full width, `offset` from the left, right and bottom edges (plus the bottom safe-area inset) |
| `top` | full width, `offset` from the left, right and top edges (plus the top safe-area inset) |
| `center` | full width with `offset` side gaps, vertically centred (with the backdrop, if any) |
| `sheet` | edge to edge at the bottom, top corners rounded, padding below the content for the safe area |

Every inset is written explicitly and corner layouts get an explicit width, so the gaps on both
sides are always equal and never depend on the host page (the `body` margin, for instance). A banner
taller than the viewport scrolls inside itself (`max-height` + `overflow:auto`).

### Theme v1 (still accepted)

Configurations saved before theme v2 hold `{bg, fg, ac, acf, rad, pos}`. They keep working unchanged
— nothing is migrated in the database: the service upgrades them whenever it reads them
(`ConsentThemeV2::fromV1()`), and the API still accepts a v1 theme on write (stored as sent; the two
shapes cannot be mixed in one theme). The GET responses carry both `theme` (as stored) and
`theme_v2` (what the banner is compiled from); `defaults.theme` is a complete v2 theme. The dashboard
always saves v2.

| v1 | v2 |
|---|---|
| `bg`, `fg`, `ac`, `acf` | `colors.background`, `colors.text`, `colors.accent`, `colors.accentText` |
| — | `colors.link` = `fg` (v1 links inherited the text colour); `colors.border` default, `border.width` 0 |
| `rad` | `shape.radius` **and** `shape.buttonRadius` |
| `pos` | `layout.desktop.position` (`maxWidth` 576, `offset` 16); the reopen button sits `bottom-right` when `pos` is, otherwise `bottom-left` |
| — | `font.family` `inherit`, `font.size` `null`, `lineHeight` 1.5, `shadow` `md`, as v1 rendered |
| — | `layout.mobile` = `bottom`, 16 px gaps (v1 had no mobile layout: this is the fix for phones) |
| — | `reopen.desktop` and `reopen.mobile` variant `text` (the v1 pill on every device) |

The v1 contrast rules still apply to a v1 theme (`theme.fg`, `theme.acf`).

## Custom CSS

`theme.css` adds rules after the theme's own. It is written with public class names and compiled by
the service (`CustomCssCompiler`): nothing is passed through verbatim — selectors, properties and
values are tokenised, checked and re-serialised with the banner's internal class names. Errors come
back as `422 validation_failed` under `theme.css`, one message per problem, formatted
`Line L, column C: …` (1-based, as in the editor).

**Public names.**

| Selector | Part |
|---|---|
| `.banner` | the dialog box |
| `.title`, `.body`, `.link` | the heading, the text, the privacy policy link |
| `.actions` | the row (or stack) holding the two buttons |
| `.button` | Accept **and** Reject, together |
| `.close` | the `×` button |
| `.reopen`, `.reopen-icon` | the floating reopen button and its icon (stroked with `currentColor`) |

**Selectors allowed.** One public class per compound, optionally followed by `:hover`,
`:focus-visible` or `:active`, joined by descendant (space) or child (`>`) combinators, in comma
lists; and `@media` blocks whose query is made of `(max-width: Npx)`, `(min-width: Npx)`,
`(prefers-color-scheme: dark|light)`, `(prefers-reduced-motion: reduce|no-preference)`,
`(hover: hover|none)`, `(pointer: fine|coarse)` joined by `and` (optionally after `screen and`).

**Refused**: attribute selectors (`[data-a]`…), `:first-child`, `:last-child`, `:nth-*`, `:only-*`,
`*-of-type`, `:not()`, `:has()`, `:is()`, `:where()`, `:host` and every other pseudo-class, `+` and
`~`, ids, element names, `*`, pseudo-elements, two classes in one compound, any unknown class, nested
rules and nested `@media`, and every other at-rule (`@import`, `@font-face`, `@keyframes`,
`@supports`, `@layer`…). Because `.button` is the only way to reach Accept and Reject and it always
reaches both, **no custom CSS can make one of them more prominent than the other** (B2).

**Properties allowed.**

- colours: `color`, `background`, `background-color`, `background-image` (gradients only — no `url()`);
- borders and corners: `border`, `border-*` (sides, colour, style, width), `border-radius` and the
  four corner radii;
- shadows: `box-shadow`, `text-shadow`;
- type: `font-family`, `font-size`, `font-weight`, `font-style`, `font-variant`, `font-stretch`,
  `text-align`, `text-decoration(-color|-line|-style|-thickness)`, `text-underline-offset`,
  `text-transform`, `text-wrap`, `letter-spacing`, `line-height`;
- spacing: `padding(-*)`, `gap`, `row-gap`, `column-gap`; `margin(-*)` on the parts **inside** the
  banner only (not on `.banner` or `.reopen`, which are fixed boxes);
- size: `width`, `min-width`, `max-width` on `.banner` only (200–1200 px, 12.5–75 em, 50–100 %);
- motion and focus: `transition(-*)` (durations up to 2 s), `outline(-*)`.

**Refused**, among others: `display`, `visibility`, `opacity`, `position`, `top`/`right`/`bottom`/
`left`/`inset`, `transform`, `translate`, `scale`, `rotate`, `z-index`, `content`, `pointer-events`,
`clip`, `clip-path`, `filter`, `order`, `flex-direction`, `height`, `text-indent`, vendor-prefixed
properties — so the banner cannot be hidden, moved off-screen, reordered or made unclickable.

**Values.** Functions: `rgb()`, `rgba()`, `hsl()`, `hsla()`, `linear-gradient()`,
`radial-gradient()` (and their `repeating-` forms), `cubic-bezier()`, `steps()` — nothing else
(`url()`, `var()`, `calc()`, `env()`, `attr()`, `image-set()`, `expression()` are refused). Units: px,
em, rem, %, s, ms, deg, turn (no viewport units). Lengths are capped at 64 px (4 em) and cannot be
negative, except `letter-spacing` (-0.125–0.5 em), offsets and shadows; `font-size` stays within
10–40 px (0.625–2.5 em, 62.5–250 %). Strings only in `font-family`. Backslash escapes, `<`, control
characters and non-ASCII characters outside comments are refused, as are unterminated comments and
strings. `!important` is allowed.

**Contrast.** Text colours (`color`) must be opaque. A rule that sets `color` and/or a background is
measured against the theme colours of the part it targets (for the parts inside the banner, against
a `.banner` background set earlier in the same block) and refused below 4.5:1. Colours given as
`inherit`/`currentColor` or gradient backgrounds cannot be measured: check those yourself.

```css
/* Pill buttons, a bolder title, a dark variant for dark-mode visitors */
.button { border-radius: 999px; font-weight: 700; }
.actions > .button:hover { background-color: #1e40af; }
.title { font-size: 1.25em; letter-spacing: -0.01em; }
@media (prefers-color-scheme: dark) {
  .banner { background: #111827; color: #f9fafb; }
  .link { color: #93c5fd; }
}
```

A stored custom CSS that stops passing (after a stricter rule is introduced) is left out of the
stylesheet rather than served unchecked; the banner then renders with the theme alone.

## Accessibility and fairness

- Accept and Reject are native `<button>` elements with the same class and the same attributes,
  and the compiled stylesheet has one rule for both. Neither the theme (one button colour pair) nor
  custom CSS (`.button` always means both) can style them differently.
- Close (`×`) and Escape count as a rejection, never as acceptance. Scrolling is never consent.
- No blocking overlay: the page stays usable.
- `role="dialog"`, `aria-modal="false"`, `aria-labelledby`/`aria-describedby`, `tabindex="-1"` on the
  dialog. When opened by the visitor it takes focus and returns it on close; when shown automatically
  it does not steal focus.
- Covered by `services/tracker/test/banner.test.ts`, by axe checks in the end-to-end suite, and by
  `services/e2e/tests/consent-layout.spec.ts` (equal side gaps on a 390 px phone for every
  position, reopen icon on phones and text on desktop, identical computed styles of Accept and Reject
  under custom CSS), and `services/e2e/tests/consent-editor.spec.ts` (the dashboard preview and the
  published banner agree).

## Reopening

Three ways, all equivalent:

- `analytics.consent.open()`;
- any element with `data-analytics-consent`, or a link to `#analytics-consent` — handy for a footer
  link or a menu item;
- the floating button, when `show_floating_reopen` is on. It is rendered after a choice has been
  made, and on later page loads.

The floating button is configured per device class (`theme.reopen.desktop`, `theme.reopen.mobile`):
`text` (the label), `icon` (the chosen icon only), `icon-text`, or `hidden`, in the bottom-left or
bottom-right corner. It always carries `aria-label` with the `reopen` text (falling back to the
title), so the icon-only variant is still announced by name. The icon is an inline SVG from a fixed
allowlist (`cookie`, `shield`, `fingerprint`, `settings`), drawn in `currentColor`.

`hidden` removes the floating button on that device class only. Withdrawing consent must stay as
easy as giving it (Garante 2021, B3): if you hide it anywhere, keep a `data-analytics-consent`
link (or a link to `#analytics-consent`) reachable on every page, typically in the footer.

## Editing and publishing

Dashboard: **Settings → Consent** (permission `site:manage`). API:

| Route | Purpose |
|---|---|
| `GET /api/v1/sites/{siteId}/consent` | current published configuration and draft |
| `PUT /api/v1/sites/{siteId}/consent/draft` | create or update the draft |
| `DELETE /api/v1/sites/{siteId}/consent/draft` | discard the draft |
| `POST /api/v1/sites/{siteId}/consent/publish` | publish, body `{"material_change": true\|false}` |
| `GET /api/v1/sites/{siteId}/consent/history` | published and archived revisions |
| `POST /api/v1/sites/{siteId}/consent/preview` | compile an unsaved configuration, nothing stored ([below](#preview)) |

The dashboard editor edits **theme v2** in sections — Colours, Typography, Shape and spacing, Layout
(Desktop / Mobile tabs, breakpoint, buttons side by side or stacked), Reopen button (icon, size,
colours, and per device the variant, corner and offset) and Advanced (custom CSS and the
[theme JSON](#theme-json)). A configuration whose stored theme is still v1 opens with its upgraded
`theme_v2` and is saved as v2. Every contrast pair the server enforces is checked live as you edit,
and the editor refuses to save while one fails. Hiding the reopen button on a device class shows a
warning: visitors there need another way to reopen the banner (a `data-analytics-consent` link, see
[Reopening](#reopening)).

Server errors are shown next to their field; custom CSS errors are listed under the CSS editor with
their line and column and a link that puts the caret there.

### Preview

The preview is the real banner, not a re-drawing. As you edit (debounced, about 300 ms), the dashboard
sends the unsaved configuration to `POST …/consent/preview`, which validates it exactly like saving
the draft (same `422` errors) and returns the `consent` block of `window.__an_cfg` the site would
receive — compiled stylesheet (`css`), reopen icon (`ri`), texts per locale. The dashboard hands it to
the tracker's own banner module (`services/tracker/dist/banner.js`, copied into the dashboard at build
time) running in a frame, at a 390 × 844 phone or a 1280 px desktop viewport (scaled to fit), showing
either the banner or the reopen button. Media queries apply as on a real device of that width, so
the mobile layout, the reopen variants and the custom CSS look exactly as they will on the site.

The frame is isolated: `/_preview/banner.html` is a static page served with its own CSP
(`default-src 'none'; script-src 'self'`, no inline scripts, `frame-ancestors 'self'`) and framed with
`sandbox="allow-scripts"` (no `allow-same-origin`), so it has an opaque origin, no cookies, no storage
and no access to the dashboard. It makes no request besides its own four files, tracks nothing, and
its Accept / Reject / close buttons do nothing. It receives the configuration by `postMessage` and
only from the dashboard window that frames it, after checking its shape.

Only site administrators can use the preview (it needs the same permission as saving a draft).

### Theme JSON

The Advanced section shows the theme as JSON, with **Copy**, **Export file** (`consent-theme.json`)
and **Import** (paste or file) — the way to reuse a theme on another site or keep it in version
control. The file follows [../api/consent-theme.v2.schema.json](../api/consent-theme.v2.schema.json)
and carries a `$schema` line so editors can validate it:

```json
{
  "$schema": "https://github.com/manuto276/analytics/docs/api/consent-theme.v2.schema.json",
  "colors": { "background": "#111827", "text": "#f9fafb", "accent": "#0f766e", "accentText": "#ffffff", "link": "#93c5fd" },
  "shape": { "radius": 16, "buttonRadius": 999 },
  "layout": { "mobile": { "position": "sheet", "buttons": "stack" } },
  "reopen": { "icon": "shield", "mobile": { "variant": "icon", "position": "bottom-right", "offset": 16 } },
  "css": ".title { letter-spacing: -0.01em; }"
}
```

An import may hold only the fields it changes: missing fields take the defaults (not the values
currently in the editor). The dashboard checks the file against the schema first and names every
field that does not match (unknown keys, v1 keys, values out of range); the server then applies its
own rules (contrast, custom CSS) on preview and save. Importing replaces the theme in the editor only
— nothing is saved until you save the draft. The same JSON is the `theme` of
`PUT …/consent/draft` (without `$schema`) for scripted setups.

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
