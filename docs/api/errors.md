# Error codes

Every error is an RFC 9457 problem document with `Content-Type: application/problem+json`:

```json
{
  "type": "https://github.com/manuto276/analytics/blob/main/docs/api/errors.md#validation_failed",
  "title": "Validation failed",
  "status": 422,
  "code": "validation_failed",
  "detail": "The request contains invalid fields.",
  "errors": { "period": ["Must be one of: today, yesterday, 7d, …."] },
  "request_id": "9f1c4b2e7a5d3086"
}
```

- **`code`** is the stable identifier — match on it, never on `title` or `detail`.
- `type` points at the anchor of this page for that code.
- `errors` is present only for field validation (`field path → messages`).
- `request_id` echoes `X-Request-Id`; it also appears in the server log line for the same request.
- `retry_after` is added as a body member and as the `Retry-After` header on `rate_limited` and
  `account_locked`.

Produced by `Analytics\Shared\Http\ApiProblem` and rendered by `JsonResponder::problem()`.

---

## Request-level errors

### `invalid_json`
`400`. The body could not be parsed as JSON, or it decoded to something other than an object or an
array. Also returned by `POST /t/e` and the conversions endpoint when the parsed body is not an array.
**Client:** fix the serialisation; do not retry unchanged.

### `invalid_body`
`400`. The body was valid JSON but not a JSON object where one was required.
**Client:** send an object.

### `payload_too_large`
`413`. The body exceeded 65 536 bytes (2 097 152 for a `text/csv` upload), either by the
`Content-Length` header or while reading.
**Client:** split the batch. The tracker already caps a batch at 50 events.

### `method_not_allowed`
`405`. The path exists with other methods; the `Allow` header lists them.

### `not_found`
`404`. No such route, or the addressed object does not exist — **or the caller may not see it**. Site
lookups deliberately return `not_found` rather than `forbidden` so that membership cannot be probed.

### `bad_request`, `http_error`
`400` / other 4xx–5xx. Generic fallbacks for framework-level HTTP exceptions that carry no specific
code.

### `internal_error`
`500`. An unhandled exception. `detail` is generic in production and carries the exception class and
message when `APP_ENV` is not `prod`. The server log holds the class, file, line, path and
`request_id`.
**Client:** retry with backoff; report the `request_id`.

### `rate_limited`
`429`. A rate-limit policy was exceeded. `retry_after` (seconds) is in the body and in the
`Retry-After` header. Policies and limits are listed in
[../architecture/overview.md](../architecture/overview.md#middleware-stack).
**Client:** wait `retry_after` and retry.

---

## Authentication and authorisation

### `unauthorized`
`401`. No valid session (missing, expired, revoked cookie), or the user was disabled. Also returned by
`POST /api/v1/auth/mfa` when there is no pending sign-in, and by the server API when the
`Authorization` header is missing, malformed or the key is invalid or revoked.
**Client (dashboard):** redirect to the sign-in page. **Client (server API):** check the key.

### `mfa_required`
`401`. The session exists but is in the `pending_mfa` state: the password was accepted and a TOTP code
is still needed.
**Client:** send the code to `POST /api/v1/auth/mfa` with the same cookie.

### `invalid_credentials`
`401`. Wrong password, or an unknown e-mail address. The two are indistinguishable on purpose — the
response, the timing (a dummy hash verification runs) and the message are identical.

### `invalid_mfa_code`
`401`. The TOTP code or recovery code did not verify.

### `account_locked`
`429`. Too many failed attempts for this account. Locking starts at 5 failures and doubles from there,
up to 60 minutes. `retry_after` and `Retry-After` give the remaining seconds.

### `mfa_not_pending`
`400`. `POST /api/v1/auth/mfa` was called on a session that is not waiting for MFA.

### `forbidden`
`403`. The session is valid but the route's permission is not satisfied (`authenticated`, `admin`,
`site:view`, `site:manage`), or the API key lacks the scope the route declares (`conversions:write`,
`stats:read`). The message names the missing scope for API keys.

### `csrf_token_invalid`
`403`. A non-GET dashboard request without a valid `X-CSRF-Token`. The token comes from
`POST /api/v1/auth/login` and from `GET /api/v1/auth/me`.
**Client:** re-read `auth/me` and resend.

### Cross-origin rejections
`403 forbidden` with `detail` "Cross-site requests are not allowed." / "Cross-origin requests are not
allowed." A state-changing dashboard request whose `Sec-Fetch-Site` is not `same-origin`/`none`, or
whose `Origin` is not the service origin (including `Origin: null`).

### `invitation_accepted`, `invitation_revoked`, `invitation_expired`
`410`. The invitation token exists but is no longer usable. The suffix is the invitation's status
(`Invitation::status()`). An unknown or malformed token gives `not_found` instead, so tokens cannot be
enumerated.

### `reset_token_invalid`
`410`. The password-reset token is unknown, already used or expired.

### `email_change_token_invalid`
`410`. `POST /api/v1/auth/email/confirm` got a token that is unknown, malformed, already used, expired
(24 hours), replaced by a newer request or cancelled, or that belongs to a disabled account.
**Client:** tell the user to request the change again from the account settings.

### `mailer_disabled`
`501`. `POST /api/v1/auth/password/forgot` or `POST /api/v1/auth/email` was called without
`MAILER_DSN`.
**Operator:** set a password with `bin/analytics user:set-password` instead; configure a mailer to let
users change their address ([../operations/mail.md](../operations/mail.md)).

---

## Validation and conflicts

### `validation_failed`
`422` with an `errors` map. Field-level validation across the whole API: report parameters
(`period`, `from`, `to`, `interval`, `compare`, `limit`, `sort`, `cursor`, `filter`, `prefix`,
`group`, `kind`, `model`, `window`, `days`), site settings, consent configuration (including the
contrast rule), conversions, goals, funnels, costs, passwords, API key scopes.
**Client:** show the messages next to the fields; do not retry unchanged.

### `email_taken`
`409`. A user or an invitation already exists for that e-mail address. `POST /api/v1/auth/email` and
`POST /api/v1/auth/email/confirm` answer it too when another account uses the requested address (at
request time, or taken meanwhile before the link was opened).

### `last_admin`
`409`. The change would leave the installation without a global administrator.

### `cannot_disable_self`
`409`. An administrator tried to disable their own account.

### `cannot_demote_self`
`409`. A site administrator tried to remove or downgrade their own access to a site.

### `totp_already_enabled` / `totp_not_enabled`
`409`. TOTP enrolment was started while it is already confirmed, or TOTP was disabled while it is not
enabled.

### `goal_name_taken`
`409`. Another goal of the same site already uses that name.

### `goal_in_use`
`409`. The goal is a step of a funnel and cannot be deleted. Remove it from the funnel first.

### `no_draft`
`409`. `POST …/consent/publish` (or discarding a draft) with no draft present.

### `receipts_disabled`
`409`. `GET …/consent/receipts` on a site whose `consent_receipts_enabled` is false: no receipt is
stored, so there is nothing to look up. Turn receipts on in the site settings first; they only start
covering decisions taken after that.

### `funnel_incomplete`
`409`. The funnel report was requested for a funnel with fewer than two usable steps — for example
because a goal was deleted.

---

## Reporting

### `filter_unsupported`
`422`. A filter was sent to a report that cannot be filtered: `conversions`, `goals`, `consent`,
`cohorts`, `realtime`, `funnels`, `attribution`.
**Client:** drop the filters.

### `filter_unavailable_for_range`
`422`. The query needs raw data (an unsupported filter combination, or `interval=hour`) but the range
starts before the retention window, where raw data no longer exists. `detail` names the window.
**Client:** shorten the range or remove the filters so a rollup can answer it.

### `range_too_large`
`422`. The funnel report matched more than 200 000 subjects.
**Client:** shorten the period.

### `csv_unavailable`
`406`. `Accept: text/csv` on a report that has neither `rows` nor `points` — `overview`, `realtime`,
`funnels`, `attribution`, `cohorts`.
**Client:** request JSON.

---

## Tracking endpoints (`/t/*`)

These return `202` for anything that is merely uninteresting (a bot, an excluded path, a foreign host,
a duplicate, Do Not Track). Only the codes below are ever returned as errors.

### `invalid_payload`
`400`. The batch is structurally wrong: missing or non-integer `v`, a site key that is not
`pk_` + 21 alphanumerics, an invalid level, or a missing/empty/non-list `e`. Also returned by
`POST /t/forget` when `k` or `vid` is missing or malformed.
*Individually* malformed events inside a well-formed batch are dropped silently, not reported.

### `unsupported_version`
`400`. `v` is an integer the server does not support. `PayloadParser::SUPPORTED_VERSIONS` is `[1]`.
**Client:** upgrade the tracker.

### `too_many_events`
`400`. More than 50 events in one batch.

### `unknown_site`
`404`. The public key does not match any site, or the site is archived.

### `origin_not_allowed`
`403`. Neither `Origin` nor `Referer` yields a host that belongs to the site's `site_domains` (and the
site does not allow localhost).
**Operator:** add the host under the site's domains, with `include_subdomains` if needed.

---

## Conversions API

### `empty_batch`
`400`. An empty array was posted.

### `too_many_conversions`
`400`. More than 100 conversions in one request.

Individual conversions that fail validation are **not** an error: the request returns `202` and lists
them in `rejected[]` with their index and a message. See [conversions.md](conversions.md).

---

## Quick reference

| Code | Status |
|---|---|
| `invalid_json`, `invalid_body`, `invalid_payload`, `unsupported_version`, `too_many_events`, `empty_batch`, `too_many_conversions`, `mfa_not_pending`, `bad_request` | 400 |
| `unauthorized`, `mfa_required`, `invalid_credentials`, `invalid_mfa_code` | 401 |
| `forbidden`, `csrf_token_invalid`, `origin_not_allowed` | 403 |
| `not_found`, `unknown_site` | 404 |
| `method_not_allowed` | 405 |
| `csv_unavailable` | 406 |
| `email_taken`, `last_admin`, `cannot_disable_self`, `cannot_demote_self`, `totp_already_enabled`, `totp_not_enabled`, `goal_name_taken`, `goal_in_use`, `no_draft`, `receipts_disabled`, `funnel_incomplete` | 409 |
| `invitation_accepted`, `invitation_revoked`, `invitation_expired`, `reset_token_invalid`, `email_change_token_invalid` | 410 |
| `payload_too_large` | 413 |
| `validation_failed`, `filter_unsupported`, `filter_unavailable_for_range`, `range_too_large` | 422 |
| `rate_limited`, `account_locked` | 429 |
| `internal_error` | 500 |
| `http_error` | the framework exception's own status (any 4xx or 5xx without a specific code) |
| `mailer_disabled` | 501 |
