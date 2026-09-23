# @analytics/tracker

Dependency-free browser tracker and consent banner (TypeScript, bundled by esbuild as two minified ES2019
IIFEs: `dist/tracker.js`, the core, and `dist/banner.js`, the banner UI that the server adds to
`/t/{key}.js` only for sites with the cookie level on).
The contract (config `window.__an_cfg`, transport, cookies, JS API) is documented in
[`docs/architecture/tracker.md`](../../docs/architecture/tracker.md); the payload schema is
[`docs/api/tracking-payload.v1.schema.json`](../../docs/api/tracking-payload.v1.schema.json).

## Layout

```
src/index.ts        bootstrap: skips, prerender activation, wiring
src/config.ts       config types and shared globals
src/ids.ts          base64url random ids
src/cookies.ts      document.cookie access, domain probe, id deletion
src/consent.ts      consent state machine (unknown / accepted / rejected), cs/cu events, forget
src/collector.ts    queue, flush rules, pageview / event / engagement events
src/transport.ts    sendBeacon (text/plain) with fetch keepalive fallback + one retry
src/spa.ts          history patching, popstate, hashchange (hash routing)
src/dom.ts          declarative attributes (data-analytics-*) and optional automatic events
src/api.ts          window[g] API, stub queue replay, global collision handling
src/banner/         shadow-DOM consent banner, built as dist/banner.js (index = entry registering
                    window.__an_b; banner = mount/show/close; template = DOM). No CSS here: the
                    stylesheet comes compiled from the server in cfg.consent.css
```

## Automatic events (`cfg.auto`)

Each switch adds one delegated listener on `document` and sends an ordinary `ev` event through the
normal queue, so the usual skips (webdriver, excluded paths, DNT `no_tracking`, base tracking off)
and flush rules apply.

| Switch | Event | Props |
|---|---|---|
| `outbound` | `outbound_link` | `url` (absolute href, truncated to 100 chars), `host` |
| `downloads` | `file_download` | `url`, `ext` |
| `forms` | `form_submit` | `id` (`form.id`, else `form.name`, else `''`), `action` (path only, no query) |

- Clicks are captured on `document` (capture phase), so middle clicks and ctrl/cmd clicks count too.
- Only `http:` / `https:` links are considered: `javascript:`, `mailto:`, `tel:` and in-page anchors
  (same URL as the current page ignoring the fragment) are skipped.
- A link is **own** (never `outbound_link`) when its host equals `location.hostname`, or one is a
  subdomain of the other (`location.hostname` ends with `.<link host>`, or the link host ends with
  `.<location.hostname>`). Sibling subdomains (`www.example.com` → `app.example.com`) are therefore
  treated as outbound, because the tracker has no list of the site's own hosts.
- Downloads are detected from the path extension: pdf, doc(x), xls(x), ppt(x), csv, zip, rar, 7z, gz,
  tar, dmg, pkg, exe, msi, apk, mp3, mp4, mov, avi, wav, txt, rtf, key, numbers, pages (case
  insensitive). A link that is both a download and outbound sends only `file_download`.
- Form submits never read field values.

## Commands

Uses pnpm (`corepack pnpm …` works without a global install).

```sh
pnpm install --frozen-lockfile
pnpm build           # dist/tracker.js (core) and dist/banner.js (banner module)
pnpm build:api       # build + copy both to ../api/resources/tracker/
pnpm test            # Vitest + happy-dom
pnpm test:coverage   # v8 coverage, gates: lines >= 95 %, branches >= 90 %
pnpm size            # size-limit, gzip: core <= 5.0 KB, banner <= 4.0 KB, both <= 9.0 KB (run after build)
pnpm lint            # ESLint (typescript-eslint, flat config)
pnpm typecheck       # tsc --noEmit
```

Test helpers live in `test/helpers.ts` (`makeConfig`, `installDom`, `advanceTime`, `load()` — banner module
then core, as served; `load({ banner: false })` for the core alone —, a fake cookie jar that
honours `Domain`, `Max-Age` and `Secure`, and payload validation against the JSON schema with Ajv).

Note: the tracker sends nothing when `navigator.webdriver` is true, so browser automation must
override it (e.g. a Playwright init script) to exercise real tracking.
