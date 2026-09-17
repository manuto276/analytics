# Open points for legal review

These are the points the implementation cannot settle. Each says what the code does today, what the
question is, and which switch changes the behaviour if the answer is unfavourable. They correspond to
the items marked **LEGAL REVIEW** in §16 of
[../plan/implementation-plan.md](../plan/implementation-plan.md).

Nothing here is legal advice. Enable the cookie level in production only after a sign-off on LR-1,
LR-2, LR-3 and LR-4.

---

## LR-1 — Is the base-level `visitor_hash` acceptable?

**What the code does.** For sites with `visitor_hash_mode = daily_hash`, base-level rows carry
`sodium_crypto_generichash(site_id ‖ shortened IP ‖ "\0" ‖ User-Agent, salt_of_today, 16)`, stored as
the first 8 bytes in a `BIGINT UNSIGNED`. The salt is 32 random bytes, exists for one UTC day and is
deleted when the next day's salt is created (`salt:rotate` every 15 minutes enforces it). The full IP
and full UA are never stored.

**The question.** Whether deriving a per-day identifier from network and device characteristics counts
as the storage of, or access to, information in the visitor's terminal equipment, or as fingerprinting
— which would require consent even though nothing is written to the browser.

**Arguments on record.** Nothing is stored in or read from the terminal equipment: the inputs are
transmitted as part of any HTTP request. The identifier cannot be recomputed after the day ends, is
scoped to one site, and cannot be joined to any other source. Against: it is still a derived
identifier that distinguishes visitors within a day.

**If the answer is unfavourable.** Set the site to `visitor_hash_mode = pageviews_only`. No hash is
then stored at all; visits are estimated from entry pageviews and bounce rate and duration disappear
(see [metric-availability.md](metric-availability.md)). Existing rows keep their hashes and are
removed by retention within 13 months.

---

## LR-2 — May several subdomains be configured as one site?

**What the code does.** A site can have any number of `site_domains` rows, each with
`include_subdomains`. Events from all of them share one `site_id`, one set of reports and — at the
cookie level with a shared `cookie_domain` — one `an_vid`.

**The question.** Whether "statistics for a single site" in the consent-exempt case still holds when
`www.example.com`, `shop.example.com` and `app.example.com` are aggregated, especially when they are
different services with different purposes.

**If the answer is unfavourable.** Create one site per host. Each gets its own public key, its own
daily hash (the `site_id` is part of the hashed input, so the hashes do not correlate) and its own
reports. The cost is that cross-subdomain journeys are no longer visible.

---

## LR-3 — Is the consent proof sufficient?

**What the code does.** Published consent configurations are immutable revisions with `published_at`,
`published_by` and an `audit_log` entry; the visitor's `an_consent` cookie records the version and the
day of their decision; `consent_stats_daily` counts `shown`, `accepted`, `rejected`, `dismissed`,
`reopened` per day and version. There is **no** per-person consent log by default. Setting
`consent_receipts_enabled` writes one `consent_receipts` row (`visitor_id`, version, decision,
timestamp) per acceptance, and a site admin can look one visitor up by the id the visitor supplies
(`GET /api/v1/sites/{siteId}/consent/receipts?visitor_id=…`, audited as `consent.receipts_read`).

**The question.** Whether an immutable versioned notice plus aggregate counters plus the visitor's own
cookie amount to being able to demonstrate consent, or whether a per-person record is required.

**Trade-off.** A per-person record is itself personal data, kept for as long as the proof must last,
about people who consented to analytics — arguably more intrusive than the processing it documents.

**If the answer is unfavourable.** Enable `consent_receipts_enabled` per site; the per-visitor
lookup then documents each acceptance. There is deliberately no bulk export, so a receipt can only be
retrieved by someone who already knows the visitor id.

---

## LR-4 — `declared_source` on a server-side conversion

**What the code does.** The conversions API accepts an optional `declared_source`
(`utm_source`, `utm_medium`, `utm_campaign`, `channel`) supplied by the caller's own systems — a CRM
field, a coupon code, a "how did you hear about us" answer. It is stored as a JSON column, kept
separate from the observed attribution (`attr_*`, `lnd_*`), and is only used by the attribution report
when `model=declared`.

**The question.** Whether importing an attribution hint from another system, and reporting on it next
to observed analytics, is compatible with "not combined with data from other sources".

**If the answer is unfavourable.** Stop sending `declared_source`; nothing else depends on it. The
column then stays NULL and `model=declared` returns everything as `unattributed`.

---

## LR-5 — Mid-page and late consent loses the original campaign

**What the code does.** The consent upgrade event carries `lu`/`lr` — the landing URL and referrer of
the *current* page load — so a visitor who accepts on the landing page keeps the campaign. A visitor
who accepts on a later page produces a touch pointing at that later page, and their conversion is
attributed accordingly or not at all.

**The question.** None, legally; this is a documented accuracy limit. It is listed here because it is
often mistaken for a bug and because it interacts with LR-4: `declared_source` is the usual
work-around.

**Mitigation in the product.** The attribution report reports the unattributed share, so the size of
the effect is visible rather than hidden.

---

## LR-6 — Controller or processor

**What the code does.** Nothing: it is a deployment question. A self-hoster measuring their own sites
is a controller. An agency hosting one installation for several clients is, in the usual reading, a
processor for each of them, and the multi-site model with per-site roles supports that.

**What is needed.** A processing agreement between the operator and each site owner. This repository
provides no template and no such document — see [controller-processor.md](controller-processor.md).

---

## LR-7 — Cookie notice wording and lifetimes

**What the code does.** Ships default English and Italian texts in `ConsentService::defaults()` and
default lifetimes (`accepted_ttl_days` 180, `rejected_ttl_days` 180, `visitor_cookie_days` 395, the
maximum the product allows). The texts are editable per locale and immutable once published.

**What is needed.** Review of the shipped wording and of the chosen lifetimes for each deployment; the
cookie table in [cookies.md](cookies.md) is the factual input.

---

## LR-8 — Retention period

**What the code does.** `RETENTION_MONTHS` defaults to 13 for raw events, visits, visitors, touches,
conversions and receipts. Aggregate rollups are kept indefinitely. Audit log 24 months, job runs 90
days.

**What is needed.** Confirmation that 13 months (a year plus a comparison month) is justified for the
stated purpose, and that keeping the aggregates indefinitely is acceptable given that they contain no
identifiers.

---

## Tracking these

When one of these is resolved, update this page, the relevant row of
[garante-2021-mapping.md](garante-2021-mapping.md), and — if behaviour changes — the release gate in
§15 of the plan requires `cookies.md` and the mapping to be updated in the same change.
