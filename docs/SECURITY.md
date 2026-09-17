# Security

## Supported versions

Releases are timestamp-versioned (`YYYYMMDDTHHMMSSZ`). Only the **latest release** receives security
fixes. There are no long-term support branches: a fix is published as a new release, and upgrading is
a single `./console deploy` or `docker compose pull && up -d` ([deploy/upgrading.md](deploy/upgrading.md)).

| Version | Supported |
|---|---|
| The newest release, and `main` | yes |
| Anything older | no — upgrade |

Because migrations are expand/contract and cumulative, upgrading across several versions is a single
step ([deploy/upgrading.md](deploy/upgrading.md#upgrading-across-several-versions)).

## Reporting a vulnerability

**Do not open a public issue.**

Report privately through GitHub's *Report a vulnerability* button on the repository's Security tab
(GitHub Security Advisories), or by e-mail to the maintainer address listed on the repository profile.

Please include: what you found, how to reproduce it, the affected version and commit, the impact you
believe it has, and any proof-of-concept. If you cannot encrypt, send a short notice first and wait
for a reply before sending details.

What to expect: an acknowledgement, an assessment with a severity and a planned fix, a release, and
credit in the advisory unless you prefer otherwise. Please give us a reasonable window before public
disclosure.

**In scope**: this repository — the backend, the tracker, the dashboard, the deploy console, the
WordPress plugin, the packaging and the shipped configuration examples.

**Out of scope**: a deployment's own infrastructure, a vulnerability in a third-party dependency that
is already public (report it upstream and tell us so we can bump it), volumetric denial of service,
missing hardening headers on a *tracked* site, and anything requiring physical access or a
compromised operator account.

A report that a tracking endpoint answers `202` for an event it then discards is not a vulnerability:
that is deliberate ([architecture/ingestion.md](architecture/ingestion.md)).

## Threat model

### What is being protected

| Asset | From |
|---|---|
| Visitor data (URLs, referrers, campaign parameters, country, device families, daily hashes, and visitor ids at the cookie level) | exfiltration, re-identification, joining across sites |
| Operator accounts and their sessions | takeover, brute force, CSRF, session fixation |
| Secrets (`APP_SECRET`, `APP_ENCRYPTION_KEYS`, TOTP secrets, API keys, database credentials) | disclosure, replay |
| The integrity of the statistics | forged events, injected conversions |
| The service's availability | ingestion floods, expensive report queries |

### Who the adversaries are

| Adversary | Can do | Is stopped by |
|---|---|---|
| An anonymous internet user | call `/t/*` and the public auth routes | origin checks bound to the site's registered domains; rate limits; payload limits and validation; no cookies on the service domain |
| A visitor of a tracked site | forge or replay events for that site | event uids are idempotent; the origin must match; the data is aggregate-only, so forgery skews numbers but reveals nothing |
| A signed-in operator | read the sites they are a member of | per-route permissions checked fail-closed; `{siteId}` membership; `404` rather than `403` so sites cannot be enumerated |
| Someone holding a database dump | read 13 months of rows | no IP addresses, no User-Agents, no readable `customer_ref`, hashed tokens, argon2id passwords, encrypted TOTP secrets — **and no salts, provided the dump excluded `daily_salts`** |
| Someone holding `.env` **and** a dump | decrypt TOTP secrets, recompute `customer_ref` hashes, reach the database | keeping the two apart ([operations/backups.md](operations/backups.md)) |
| A network attacker | intercept or tamper | HTTPS enforced by `app:preflight` in production; HSTS; `__Host-` session cookie |

### Accepted limitations

- **Base-level data is not anonymous within a day.** The daily `visitor_hash` distinguishes visitors
  for 24 hours by design; it cannot be recomputed afterwards because the salt is destroyed. See LR-1
  in [privacy/legal-review-points.md](privacy/legal-review-points.md).
- **Forged events cannot be fully prevented.** A public collection endpoint is spoofable by anyone who
  can read the site's public key. Rate limits, origin checks and bot filtering raise the cost; the
  data is aggregate, so the impact is distorted statistics, not disclosure.
- **A global admin sees every site.** There is no hard tenancy boundary in a single installation —
  [privacy/controller-processor.md](privacy/controller-processor.md).
- **No hardware-bound MFA.** TOTP and recovery codes only; no WebAuthn.
- **`/_ops/` is reserved but unimplemented**, and `OPS_TOKEN` is unused; there is no remote OPcache
  reset endpoint.

## Security features

### Authentication

- Passwords hashed with **argon2id** (`memory_cost` 64 MiB, `time_cost` 4, `threads` 1), with a
  `sodium_crypto_pwhash` fallback when PHP lacks argon2. Policy: at least 12 characters, at most 1024
  bytes, must not contain the local part of the e-mail address, must not be trivially repetitive.
- Unknown e-mail and wrong password are indistinguishable: the same code, the same message, and a
  dummy hash verification so the timing matches.
- Progressive lockout: from 5 failures, `2^(n−5)` minutes, capped at 60 (`429 account_locked` with
  `Retry-After`).
- Rate limits `login_ip` (30 per 15 minutes per shortened IP) and `login_email` (10 per 15 minutes per
  hashed address).
- **TOTP** (RFC 6238, 30-second period, ±1 step window) with replay protection — the last accepted
  step is stored and a step is never accepted twice. Secrets are encrypted at rest with
  XChaCha20-Poly1305 and bound to the user id as associated data. Ten single-use recovery codes,
  stored as SHA-256 hashes.
- Password reset needs a configured mailer; without one, `501 mailer_disabled` and the operator uses
  `bin/analytics user:set-password`.

### Sessions

- Cookie `__Host-an_session` (`an_session` when `APP_URL` is not https): `Path=/`, `HttpOnly`,
  `SameSite=Lax`, `Secure`. The `__Host-` prefix forbids a `Domain` attribute, so no subdomain can set
  or read it.
- The token is 32 random bytes; **only its SHA-256 is stored**, so a database read does not yield a
  usable session.
- Idle expiry 12 hours (refreshed at most once a minute), absolute expiry 30 days. A pending-MFA
  session lives 10 minutes and can reach nothing but `POST /auth/mfa`.
- The token is **rotated** when MFA completes. Changing a password revokes the user's other sessions.
  Sessions are listable and revocable individually or all at once.
- Disabling a user invalidates their sessions on the next request.

### CSRF and cross-origin

- Every non-GET dashboard request needs `X-CSRF-Token` matching the session's secret
  (`403 csrf_token_invalid`).
- `SameOriginMiddleware` rejects a state-changing request whose `Sec-Fetch-Site` is not
  `same-origin`/`none`, or whose `Origin` is not the service origin — including `Origin: null`.
- The dashboard is on the same origin as the API, so no CORS is needed for it
  ([architecture/adr/0005-static-spa-same-origin.md](architecture/adr/0005-static-spa-same-origin.md)).
- `/t/*` echoes the request `Origin` with `Vary: Origin` and sends no credentials; the collection
  endpoints additionally require the origin host to be one of the site's registered domains.

### Rate limits

Sliding windows, backed by Redis when `REDIS_DSN` is set and by the `cache_items` table otherwise:
`collect` 300/min per site + shortened IP, `collect_site` 30 000/min per site, `script` 600/min,
`login_ip` 30/15 min, `login_email` 10/15 min, `dashboard` 600/min per user, `server` 1200/min per API
key prefix, `public` 60/min for `/t/forget`.

### Headers

Dashboard and API: `Content-Security-Policy` (`default-src 'self'; script-src 'self' <generated inline
hashes>; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src
'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'` on HTML;
`default-src 'none'; frame-ancestors 'none'` on everything else), `Referrer-Policy: same-origin`,
`Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`,
`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Permissions-Policy` denying camera,
microphone, geolocation, payment, USB, interest-cohort and browsing-topics, and HSTS
(`max-age=31536000; includeSubDomains`) when `APP_URL` is https.

Tracking endpoints use a deliberately different profile: `Cross-Origin-Resource-Policy: cross-origin`
(the script is loaded from other sites) and `Referrer-Policy: no-referrer`.

The inline-script hashes are generated at build time into `services/api/config/csp.php`.

### API keys

`ak_<prefix>_<secret>`: an 8-character public prefix and 32 random bytes base64url. Only the SHA-256
of the secret is stored; the key is shown once. Scoped (`conversions:write`, `stats:read`), bound to
one site, optionally expiring, revocable, with a throttled `last_used_at`. A key used against another
site's public key answers `404`, the same as a non-existent site.

### Authorisation

Four permissions (`authenticated`, `admin`, `site:view`, `site:manage`) declared per route through
`SecuredRoutes` and checked by `AccessMiddleware`. A route without a declared permission raises a
`LogicException` rather than being open — and `RbacMatrixTest` fails the build. Site access is checked
by membership; a site the caller cannot see is reported as missing.

### Input handling

Bodies capped at 64 KB (2 MB for CSV), JSON depth 32. The tracking payload has a hand-written
validator with per-field limits. Every SQL statement is parameterised. `PiiScrubber` and
`UrlSanitizer` strip personal data and click identifiers before storage.

### Cryptography

- `SecretBox` — XChaCha20-Poly1305 with a key ring (`v1.<keyId>.<payload>`), associated data binding
  the ciphertext to its owner, online rotation via `secrets:rotate-key`.
- `KeyDerivation` — keyed BLAKE2b subkeys from `APP_SECRET`, used for the per-site `customer_ref`
  HMAC.
- `VisitorHasher` — keyed BLAKE2b with a salt that lives one UTC day.
- `TokenGenerator` / `TokenHasher` — `random_bytes` and SHA-256 with `hash_equals` comparison.
- All from libsodium; no hand-rolled primitives.

### Audit log

Every administrative action (`auth.*`, `user.*`, `site.*`, `invitation.*`, `api_key.*`, `goal.*`,
`funnel.*`, `cost.*`, `consent.published`, …) is recorded with the actor, the target, a **shortened**
IP prefix and redacted metadata: any key containing `password`, `secret`, `token`, `code`, `key`,
`totp` or `recovery` is stored as `[redacted]`. Retained 24 months.

### Logging

Monolog at `LOG_LEVEL` (`warning` in production) with `IpScrubbingProcessor`, which masks anything
resembling an IPv4 or IPv6 address in the message, the context and the extra fields. Every request
carries an `X-Request-Id` that also appears in error responses. The web server's `/t/` access log must
be anonymised or disabled ([deploy/nginx.md](deploy/nginx.md#anonymised-logs)).

### Supply chain and verification in CI

`roave/security-advisories` in the development dependencies, `composer audit` and a pnpm audit
nightly, Dependabot for Composer, npm, Docker and Actions, CodeQL for JavaScript/TypeScript and the
workflows, an OWASP ZAP baseline nightly, SBOMs (syft) attached to releases and images, and packages
published with a SHA-256 that the deploy console verifies with `hash_equals` before extraction — after
rejecting archives with absolute paths, `..` or escaping symlinks.

## Hardening a deployment

- [ ] `APP_URL` is `https://`, with a valid certificate and a redirect from port 80
- [ ] `APP_SECRET` and `APP_ENCRYPTION_KEYS` generated with `secrets:generate`; `.env` is `0600`
- [ ] `TRUSTED_PROXIES` lists exactly the proxies in front, and nothing else
- [ ] The database user has no privileges beyond its own schema
- [ ] `/t/` access logs anonymised or off; application logs rotated
- [ ] Cron running: `rollup:run`, `salt:rotate`, `partitions:maintain`, `retention:purge`, `geo:update`
- [ ] Backups exclude `daily_salts`, are encrypted, and are kept apart from `.env`
- [ ] TOTP enabled for every administrator
- [ ] `health:check` is `ok` and monitored ([operations/monitoring.md](operations/monitoring.md))
- [ ] No shared page cache in front of the API
- [ ] The dashboard is not exposed to the internet more widely than it needs to be

## Related

[operations/incident-response.md](operations/incident-response.md) ·
[operations/key-rotation.md](operations/key-rotation.md) ·
[privacy/dpia-inputs.md](privacy/dpia-inputs.md) ·
[architecture/overview.md](architecture/overview.md#the-five-invariants)
