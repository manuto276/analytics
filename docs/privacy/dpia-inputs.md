# Inputs for a data protection impact assessment

Facts about the system, assembled so that an assessment does not have to be reconstructed from the
code. Whether a DPIA is required at all is a decision for the controller; web analytics of this shape
is usually below the threshold, but a large-scale deployment or a national requirement may change
that.

## 1. Processing described

| | |
|---|---|
| Purpose | Measuring audience and content performance of one or more websites, and attributing conversions to marketing campaigns |
| Nature | Collection of page and event data from visitors' browsers, aggregation into daily statistics, reporting to the site operator |
| Scope | Per site: pageviews, custom events, engagement time, entry and exit pages, referrer host, campaign parameters, country, browser/OS/device family, and — at the cookie level — a visitor identifier |
| Context | Self-hosted; no third-party recipient at runtime; two levels, one without any browser storage and one requiring explicit consent |
| Data subjects | Visitors of the tracked sites; separately, the operator's own dashboard users |

## 2. Categories of data

See [data-inventory.md](data-inventory.md) for the field-by-field list. In summary:

| Category | Base level | Cookie level |
|---|---|---|
| Online identifiers | daily-salted hash of (site, shortened IP, UA) — or none in `pageviews_only` mode | additionally a random 128-bit visitor id in a first-party cookie |
| Network data | shortened IP (IPv4 /24, IPv6 /48), used transiently for the hash and the country lookup, never stored | same |
| Device data | browser and OS family + major version, device class | same |
| Location | country (two letters), derived from the shortened address | same |
| Behavioural data | URLs (sanitised, PII-scrubbed), referrer host, campaign parameters, custom event names and up to 10 scalar properties, engaged time, scroll depth | same |
| Commercial data | — | conversions: name, value, currency, optional properties, optional keyed hash of a customer reference |
| Special categories | none collected; a site could put sensitive information into a URL or an event property, which is why paths and properties are scrubbed and why `excluded_paths` exists | same |

No special-category data, no criminal-offence data, no data of children targeted as such.

## 3. Necessity and proportionality

- **Minimisation by construction.** The IP is shortened in the outermost middleware and then
  discarded; the User-Agent is reduced to families and major versions inside a single function call;
  the referrer is reduced to a host; only allow-listed query parameters survive; click identifiers are
  always dropped; e-mail addresses, phone numbers and long digit runs are scrubbed from paths, query
  values and event properties.
- **No cross-site or cross-source combination.** Identifiers are per site; the service domain sets no
  cookie; there is no data import that can be joined to a visitor.
- **Two levels.** Everything that needs a persistent identifier is behind explicit consent; the rest
  works without any browser storage.
- **Storage limitation.** Raw data 13 months by default, then dropped a partition at a time; only
  aggregates survive.
- **Purpose limitation.** The API exposes aggregates only; there is no export of individual rows.

## 4. Lawful basis

| Processing | Usual basis |
|---|---|
| Base level | legitimate interest / statistical purposes, relying on the consent-exempt analytics conditions — see [garante-2021-mapping.md](garante-2021-mapping.md) and LR-1 in [legal-review-points.md](legal-review-points.md) |
| Cookie level | consent, collected by the built-in banner |
| Server-side conversions | the controller's own basis for the underlying transaction; the analytics service stores an attribution snapshot |
| Dashboard accounts | contract / legitimate interest of the operator |

## 5. Risks and mitigations

| Risk | Mitigation in the product | Residual |
|---|---|---|
| Re-identification of a visitor from analytics rows | daily-rotating secret salt, destroyed at rotation; no full IP or UA; per-site scope; aggregates only | a same-day hash distinguishes visitors within a day — LR-1 |
| Accidental collection of personal data in URLs or event properties | `PiiScrubber`, query allow list, `excluded_paths`, property limits | a site can still name an event property badly; operators must review |
| Tracking without a valid consent | client and server both enforce the level; GPC and DNT honoured; nothing stored before a choice; equal accept/reject; 180-day rejection memory | correctness of the notice's wording — LR-7 |
| Inability to demonstrate consent | immutable published revisions, audit log, per-version counters, the visitor's own cookie, optional receipts | sufficiency — LR-3 |
| Unauthorised access to the dashboard | argon2id, hashed session tokens, `__Host-` cookie, CSRF, same-origin checks, progressive lockout, optional TOTP, per-site roles, audit log | operator practice |
| Leakage of the visitor id to third parties | first-party cookie, `credentials: 'omit'`, no third-party requests, CSP on the dashboard, `Referrer-Policy: no-referrer` on `/t/*` | integrations the operator adds |
| Data loss or breach of the database | encrypted secrets at rest (TOTP), hashed tokens, keyed hash of `customer_ref`, salts excluded from backups so a stolen backup cannot recompute yesterday's hashes | host security |
| Excessive retention | `retention:purge` from cron; refuses to delete un-rolled-up days; health check surfaces failures | the cron must actually run — see [../operations/monitoring.md](../operations/monitoring.md) |
| Inaccurate attribution influencing decisions | unattributed share reported; `declared_source` kept separate from observed data | LR-4, LR-5 |

## 6. Data subject rights

| Right | How it is served |
|---|---|
| Information | the consent banner and the site's own notice; [cookies.md](cookies.md) is the factual input |
| Withdrawal of consent | reopen the banner (`consent.open()`, `[data-analytics-consent]`, `#analytics-consent`, optional floating button) and reject; cookies are deleted |
| Erasure | `analytics.consent.forget()` / `POST /t/forget` erases every cookie-level row of that visitor and detaches their conversions; base-level rows are not linked to a person |
| Access / portability | no per-person record exists at the base level; at the cookie level the visitor id is known only to the visitor's browser, so the operator cannot look someone up without being given the id. This is a deliberate design consequence, and it should be stated in the site's notice |
| Objection | rejecting at the banner, `Sec-GPC`, `DNT` (per site configuration), or a browser/extension blocking the script |

## 7. Recipients and transfers

- **Runtime recipients:** none. All processing happens on the operator's own infrastructure.
- **Server-to-server:** `bin/analytics geo:update` downloads a country database from `db-ip.com`
  monthly. No visitor data is sent.
- **Mailer:** if `MAILER_DSN` is configured, password-reset and email-change messages go to the
  configured provider. Operators only; the messages contain no tracking (see
  [../operations/mail.md](../operations/mail.md)).
- **International transfers:** none introduced by the software. Determined entirely by where the
  operator hosts it.

## 8. Technical and organisational measures

See [../SECURITY.md](../SECURITY.md) for the full list and
[../operations/](../operations/) for the procedures (backups excluding salts, key rotation, retention,
incident response, monitoring).

## 9. Open points

[legal-review-points.md](legal-review-points.md) — LR-1 (base-level hash), LR-2 (subdomains as one
site), LR-3 (consent proof), LR-4 (`declared_source`), LR-8 (retention period) are the ones that
usually need an answer before a DPIA can be concluded.
