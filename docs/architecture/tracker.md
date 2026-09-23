# Tracker contract

The tracker (`services/tracker`) is a dependency-free TypeScript code base built by esbuild into two
ES2019 IIFEs:

| File | Contents | Budget (gzip, `pnpm size`) |
|---|---|---|
| `dist/tracker.js` | the core: config, ids, cookies, consent state machine, collector, transport, SPA, DOM hooks, JS API | ≤ 5.0 KB |
| `dist/banner.js` | the consent banner UI (`src/banner/*`): shadow root, dialog, floating reopen button | ≤ 4.0 KB |
| both | what a site with the cookie level on downloads | ≤ 9.0 KB |

The API (`GET /t/{publicKey}.js`) serves one file:

```
/*! analytics | AGPL-3.0-or-later | source: https://github.com/manuto276/analytics */
window.__an_cfg=<JSON config>;
<contents of dist/banner.js>      ← only when the site has the cookie level on (cfg.c)
<contents of dist/tracker.js>
```

Both files are copied into `services/api/resources/tracker/` at build/package time (`pnpm build:api`,
the Docker `node-build` stage). `ScriptBundleBuilder` strips their build headers, and its ETag covers
the config and both files. Sites without the cookie level never download the banner.

**Module boundary.** `banner.js` runs first and only registers a factory on the private global
`window.__an_b`: `(consentCfg, decide, open) => {show, fab, close}`. The core's `initConsent()` calls
it when the cookie level is enabled and keeps the returned object; every banner call is guarded, so
the core works without the module (consent API, cookies and counters behave the same; nothing is
drawn and no `cs: shown` is counted). The banner module holds no consent state: choices go back
through the core's `decide()`.

**Styling is compiled by the server.** The tracker contains no CSS builder. The API compiles the
site's theme (v2, or v1 upgraded on read) and the vetted custom CSS into one stylesheet
(`Consent\Application\BannerStylesheet`) and ships it as `consent.css`; the banner applies it with
`adoptedStyleSheets` (a `<style>` fallback) inside its shadow root. The DOM uses fixed short class
names and no inline styles:

| Class / element | Part |
|---|---|
| `.b` | the dialog (`role="dialog"`) |
| `h2`, `p`, `a` | title, body, policy link |
| `.a` | actions row |
| `.k` | Accept **and** Reject — same class, same attributes (`data-a`/`data-r` are click hooks the stylesheet never uses) |
| `.x` | close `×` |
| `.f`, `.i`, `.t` | floating reopen button, its icon (`<svg>`), its label |

**Missing files are loud.** Without `tracker.js` the endpoint serves a no-op stub that keeps the
queue, and without `banner.js` no banner; in production each build of a bundle then logs an error
and `app:preflight` fails (`tracker:tracker.js`, `tracker:banner.js`), so a broken release cannot go
live silently. In development they are warnings.

## `window.__an_cfg`

```jsonc
{
  "k": "pk_XXXXXXXXXXXXXXXXXXXXX",   // site public key (pk_ + 21 alphanumerics)
  "g": "analytics",                  // global name; falls back to "__analytics" if taken by something else
  "b": true,                         // base-level tracking enabled
  "c": true,                         // cookie level enabled (banner shown only when c && consent != null)
  "cd": ".example.com",              // cookie domain or null (probe the longest writable suffix)
  "vd": 395,                         // an_vid lifetime in days (<= 395)
  "hr": false,                       // hash routing: keep fragment, listen to hashchange
  "dnt": "ignore",                   // ignore | no_cookie (DNT=1 behaves like reject) | no_tracking (DNT=1 sends nothing)
  "gpc": true,                       // Sec-GPC / navigator.globalPrivacyControl = reject (no banner, base only)
  "xp": ["/admin/*"],                // excluded path globs (* wildcard); matching pages send nothing
  "loc": false,                      // allow tracking on localhost / file:
  "ep": null,                        // explicit endpoint base (e.g. "https://stats.example.net/t/"); null = derive from script src
  "auto": { "outbound": false, "downloads": false, "forms": false },
  "consent": null | {
    "v": 3,                          // consent_version; stored in an_consent; a lower cookie version = unknown
    "rev": 7,                        // config revision (informational)
    "dl": "en",                      // default locale
    "at": 180,                       // accepted TTL days
    "rt": 180,                       // rejected TTL days
    "fl": true,                      // show floating reopen button after a choice
    "css": ".b,.f{position:fixed;…}", // the whole banner stylesheet, compiled by the server from the theme (+ custom CSS)
    "ri": "M12 3l7 3v5c0 4.5…",      // reopen icon: SVG path data (24×24, stroked) from the server's fixed allowlist
    "texts": {
      "en": { "title": "…", "body": "…", "accept": "Accept", "reject": "Reject", "close": "Close", "policy": "Privacy policy", "policyUrl": "https://www.example.com/privacy", "reopen": "Cookie settings" },
      "it": { "…": "…" }
    }
  }
}
```

## Transport

- Endpoint base = `cfg.ep` or the script `src` with the file name removed (`https://stats.example.net/t/pk_X.js` → `https://stats.example.net/t/`; proxy `/stats/pk_X.js` → `/stats/`).
- Events: `POST {base}e`, body = payload v1 (`docs/api/tracking-payload.v1.schema.json`), via `navigator.sendBeacon(url, new Blob([json], {type:'text/plain'}))`, fallback `fetch(url, {method:'POST', body: json, keepalive:true, credentials:'omit', headers:{'Content-Type':'text/plain'}})` with one retry.
- Forget: `POST {base}forget`, body `{"k":"pk_…","vid":"…"}` (text/plain).
- Queue flush: 1 s idle, 10 queued events, `visibilitychange=hidden`, `pagehide`. Max 50 events per request.

## Levels

- Base (`l:"b"`): no `vid`/`sid`; nothing is written to cookies, localStorage, sessionStorage or IndexedDB.
- Cookie (`l:"c"`): only after `an_consent` says accepted with version >= published version. Sends `vid` (`an_vid`) and `sid` (`an_sid`).
- `cu` (consent upgrade) is sent with `l:"c"` right after the visitor accepts, carrying `lu`/`lr` = landing URL/referrer of the page load (kept in memory).
- `cs` (consent statistics) is always sent with `l:"b"`, no ids: `shown` when the banner is displayed, `accept`/`reject`/`dismiss` on a choice (Close/Esc = `dismiss` and is recorded as reject), `reopen` when preferences are reopened.

## Cookies

| Cookie | Value | Lifetime |
|---|---|---|
| `an_consent` | `1.<consentVersion>.<a\|r>.<decidedAt epoch-days base36>` | accepted `at` days, rejected `rt` days |
| `an_vid` | 22-char base64url (16 random bytes) | `vd` days, never extended |
| `an_sid` | 22-char base64url | 30 min sliding |

All with `Domain=<cd or probed>; Path=/; SameSite=Lax; Secure` (Secure omitted on http: localhost only).
Probe: try `an_probe=1` on the longest-to-shortest candidate suffix (never a bare TLD), delete immediately.

## JavaScript API (`window[g]`)

`track(name, props?)`, `pageview({url?})`, `setContent(key)`, `consent.open()`, `consent.get()` → `{status:'unknown'|'accepted'|'rejected', version, decidedAt}`,
`consent.set('accepted'|'rejected')`, `consent.onChange(cb)`, `consent.forget()` (sends /t/forget, deletes cookies, sets rejected), `getVisitorId()` (null without consent).
The stub `window.analytics = window.analytics || {q:[], track(){this.q.push(['track',...arguments])}}` queue is replayed on load (`q` entries are `[method, ...args]`).

Declarative: `[data-analytics-consent]` click opens preferences; `[data-analytics-event="name"]` + `data-analytics-prop-*` click events; `input[data-analytics-visitor]` filled with the visitor id after consent; `<meta name="analytics:content" content="author:42">` sets content key.
