# Tracker contract

The tracker (`services/tracker`) is a dependency-free TypeScript bundle built by esbuild as an ES2019 IIFE.
The API (`GET /t/{publicKey}.js`) serves it as:

```
/*! analytics | AGPL-3.0-or-later | source: https://github.com/manuto276/analytics */
window.__an_cfg=<JSON config>;
<contents of services/tracker/dist/tracker.js>
```

The bundle file is copied into `services/api/resources/tracker/tracker.js` at build/package time.

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
    "theme": { "bg": "#ffffff", "fg": "#111827", "ac": "#1d4ed8", "acf": "#ffffff", "rad": 8, "pos": "bottom" },  // pos: bottom|bottom-left|bottom-right
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
