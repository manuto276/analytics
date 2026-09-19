# Email delivery

The service sends a handful of account messages. Nothing else is ever mailed: no reports, no
newsletters, no notifications about visitors.

| Message | Sent to | When |
|---|---|---|
| Password reset link (valid 1 hour) | the account address | `POST /auth/password/forgot` ("Forgot password?" on the sign-in page) |
| Email change confirmation link (valid 24 hours) | the **new** address | `POST /auth/email` (the account settings in the dashboard) |
| "Your sign-in address was changed" notice | the **previous** address | when the confirmation link is opened |

Every message is multipart (plain text + HTML) and written in the recipient's language (`en` or `it`,
the account's `locale`; a new address gets the locale of the user who asked for it). The HTML is
deliberately plain and self-contained: no remote images, no web fonts, no tracking pixels, no
redirected or tagged links. The only link in a message points straight at `APP_URL`.

Invitations are **not** mailed: `POST /invitations` and `bin/analytics invitation:create` return a
link that the admin copies to the person, with or without a mailer.

## Without a mailer

The mailer is optional. When `MAILER_DSN` is empty:

- `GET /api/v1/auth/config` reports `mailer_enabled: false` and the dashboard hides the "Forgot
  password?" link. An admin sets passwords with `bin/analytics user:set-password <email>`.
- Changing the own email address is refused with `501 mailer_disabled`: without a confirmation link
  nobody could prove they own the new address.
- Invitations work as always, as copy-paste links.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `MAILER_DSN` | empty (no mailer) | where to send, as a Symfony Mailer DSN |
| `MAIL_FROM` | `analytics@localhost` | sender address; must be an address the SMTP account may send as |
| `MAIL_FROM_NAME` | `Analytics` | display name of the sender (`Analytics <stats@example.net>`) |

Set them in `.env` (tarball, managed hosts) or in the environment of the `app` and `cron` containers
(Docker). Only the SMTP transports that ship with `symfony/mailer` are available (`smtp://`,
`smtps://`, `sendmail://`, `null://`); provider API bridges such as `gmail+smtp://` or `ses+api://`
are not installed, so use the provider's plain SMTP endpoint.

### Examples

Generic SMTP account, STARTTLS on port 587 (the most common case):

```dotenv
MAILER_DSN=smtp://stats%40example.net:app-password@smtp.example.net:587
MAIL_FROM=stats@example.net
MAIL_FROM_NAME="Example Analytics"
```

`smtp://` upgrades the connection with STARTTLS whenever the server offers it (every reputable
provider does). Add `?require_tls=true` to refuse to send in clear text if the server stops offering
it: `smtp://user:pass@smtp.example.net:587?require_tls=true`.

Implicit TLS (SMTPS) on port 465:

```dotenv
MAILER_DSN=smtps://stats%40example.net:app-password@smtp.example.net:465
```

Gmail and Google Workspace — turn on 2-Step Verification for the sending account, create an **app
password** (Google Account → Security → App passwords; 16 letters, no spaces) and use it instead of
the account password:

```dotenv
MAILER_DSN=smtp://stats%40example.net:abcdefghijklmnop@smtp.gmail.com:587
MAIL_FROM=stats@example.net
```

Gmail rewrites the sender to the authenticated account unless `MAIL_FROM` is that account or one of
its verified "Send mail as" aliases. Workspace admins can also use the SMTP relay
(`smtp-relay.gmail.com:587`) with IP or SMTP authentication.

Fastmail — create an app password with the "SMTP" access (Settings → Privacy & Security → App
passwords); the username is the full Fastmail address:

```dotenv
MAILER_DSN=smtps://you%40fastmail.com:app-password@smtp.fastmail.com:465
# or STARTTLS: smtp://you%40fastmail.com:app-password@smtp.fastmail.com:587
MAIL_FROM=stats@your-domain.example   # an alias or domain configured in Fastmail
```

OVHcloud mailboxes (MX Plan, Email Pro, Exchange) — the username is the full address of the
mailbox, and `MAIL_FROM` must be that mailbox or one of its aliases, otherwise OVH refuses or
rewrites the sender:

```dotenv
MAILER_DSN=smtps://analytics%40example.net:password@ssl0.ovh.net:465
# or STARTTLS: smtp://analytics%40example.net:password@ssl0.ovh.net:587
MAIL_FROM=analytics@example.net
```

Exchange accounts use `ex.mail.ovh.net` (or `ex3.mail.ovh.net`, as shown in the OVH control panel)
on 587 instead of `ssl0.ovh.net`. For deliverability, publish `include:mx.ovh.com` in the domain's
SPF record and enable DKIM for the domain in the OVH control panel.

Local sendmail/Postfix on the same host:

```dotenv
MAILER_DSN=sendmail://default
```

### Special characters in the user name or password

The DSN is a URL, so the user name and password must be **percent-encoded**. The `@` of an address used
as user name is the usual one (`stats@example.net` → `stats%40example.net`).

| Character | Encoded | Character | Encoded |
|---|---|---|---|
| `@` | `%40` | `:` | `%3A` |
| `/` | `%2F` | `?` | `%3F` |
| `#` | `%23` | `%` | `%25` |
| `+` | `%2B` | space | `%20` |
| `&` | `%26` | `=` | `%3D` |

Encode the whole value rather than guessing:

```bash
php -r 'echo rawurlencode($argv[1]), PHP_EOL;' 'p@ss:w/rd#1'
# p%40ss%3Aw%2Frd%231
```

Once encoded, the value contains no `#`, spaces or `$` (`rawurlencode` turns `$` into `%24`), so it
needs no quoting in `.env` and no `$$` escaping in `compose.*.yml`.

## Checking the configuration

`app:preflight` reports the mailer on a `mailer` line. It is a **warning**, never a failure:

```text
[ok]   mailer                   configured: smtp://smtp.example.net:587 as Example Analytics <stats@example.net> (verify with mail:test)
[warn] mailer                   not configured: set MAILER_DSN to enable password reset by email and email changes (docs/operations/mail.md)
[warn] mailer                   MAILER_DSN cannot be used: The "carrier-pigeon" scheme is not supported; …
```

Preflight only parses the DSN; it does not connect. To prove that the account really sends, use
`mail:test`:

```bash
bin/analytics mail:test --to=you@example.com
# Docker:       docker compose exec app php bin/analytics mail:test --to=you@example.com
# Managed host: ./console app mail:test --to=you@example.com
```

It sends a short multipart test message through the configured DSN and prints either

```text
Sending a test message to you@example.com via smtp://smtp.example.net:587 from Example Analytics <stats@example.net> ...
Sent. The transport accepted the message; check the inbox (and the spam folder) of you@example.com.
```

or the transport error (connection refused, TLS failure, `535 Authentication failed`, sender
rejected, …) with exit code 1. Credentials are never printed. "Sent" means the SMTP server accepted
the message; if it never arrives, look at the provider's logs and at the DNS records below.

## Deliverability: SPF, DKIM, DMARC

Password reset links that land in spam are as bad as no mailer. For the domain of `MAIL_FROM`:

- **SPF** — a TXT record on the domain listing who may send for it, e.g.
  `v=spf1 include:_spf.google.com ~all` (Google), `v=spf1 include:spf.messagingengine.com ~all`
  (Fastmail), or your provider's include. Keep a single SPF record per domain; merge includes into it.
- **DKIM** — enable signing in the provider's console and publish the TXT/CNAME records it gives you
  (`google._domainkey`, `fm1._domainkey`, …). The service does not sign messages itself: the provider
  does.
- **DMARC** — a TXT record on `_dmarc.<domain>`, starting in monitoring mode:
  `v=DMARC1; p=none; rua=mailto:dmarc@example.net`. Move to `p=quarantine` or `p=reject` once the
  reports show SPF and DKIM aligned.
- Use a `MAIL_FROM` on a domain you control and that the SMTP account is authorised for. A sender on
  someone else's domain (or `@localhost`, the default) fails DMARC and is rejected or filed as spam.
- A dedicated address such as `stats@` or `no-reply@` is fine; replies to it are not read by the
  service.

## Development

`make up PROFILES=mail` starts Mailpit and the dev php container then sends through it
(`MAILER_DSN=smtp://mailpit:1025`, set automatically when `MAILER_DSN` is empty and Mailpit is up).
Open <http://127.0.0.1:8025> to read the messages. CLI commands run with `docker compose exec` do not
inherit that value, so pass it explicitly:

```bash
docker compose -p analytics-dev -f deploy/docker/compose.base.yml -f deploy/docker/compose.dev.yml \
  exec -e MAILER_DSN=smtp://mailpit:1025 php php bin/analytics mail:test --to=dev@example.com
```

Tests never send mail: the test container replaces the mailer with an in-memory recorder.

## Privacy

With a mailer configured, the mail provider becomes a processor for the operator's own staff (their
addresses and the message contents), never for visitors. Record it as such
([../privacy/controller-processor.md](../privacy/controller-processor.md)).
