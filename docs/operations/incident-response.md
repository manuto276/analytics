# Incident response

For security incidents. Operational faults (rollup lag, a failed deploy, a full disk) are in
[runbook.md](runbook.md).

A vulnerability report about the software itself goes to [../SECURITY.md](../SECURITY.md).

## 1. Assess

Answer three questions before touching anything:

- **What was reached?** The dashboard (operator data), the database (visitor and operator data), the
  host, or only the public endpoints?
- **What data is involved?** [../privacy/data-inventory.md](../privacy/data-inventory.md) lists every
  field. Note that no full IP address and no full User-Agent is ever stored, and that `customer_ref`
  and every token are stored only as hashes.
- **Is it still happening?** Check `audit_log`, `auth_sessions`, `job_runs` and the web server log.

```sql
-- recent administrative actions
SELECT occurred_at, actor_type, actor_id, action, site_id, target_type, target_id, ip_prefix
  FROM audit_log ORDER BY occurred_at DESC LIMIT 100;

-- failed and successful sign-ins
SELECT occurred_at, actor_id, action, ip_prefix, metadata
  FROM audit_log WHERE action LIKE 'auth.%' ORDER BY occurred_at DESC LIMIT 100;

-- live sessions
SELECT user_id, state, created_at, last_seen_at, ip_prefix, ua_summary
  FROM auth_sessions WHERE revoked_at IS NULL AND absolute_expires_at > NOW();

-- API keys and their last use
SELECT id, site_id, name, prefix, scopes, created_at, last_used_at, revoked_at FROM api_keys;
```

Remember `ip_prefix` is a /24 or /48, not an address.

## 2. Contain

Pick what fits; they are independent.

**Revoke all dashboard sessions**

```sql
UPDATE auth_sessions SET revoked_at = NOW() WHERE revoked_at IS NULL;
```

**Disable a compromised account**

```sh
php bin/analytics user:disable admin@example.com
php bin/analytics user:set-password admin@example.com --password-stdin   # also unlocks and revokes sessions
php bin/analytics user:reset-2fa admin@example.com
```

**Revoke API keys**

```sh
php bin/analytics api-key:revoke <prefix>
```

**Rotate secrets** — [key-rotation.md](key-rotation.md). If `.env` may have leaked, rotate
`APP_ENCRYPTION_KEYS` (online, three steps), the database password, and every API key. Understand what
changing `APP_SECRET` costs before doing it.

**Take the service offline** if needed:

```sh
# tarball: point current at a maintenance release, or stop the PHP-FPM pool in the panel
# Docker:
docker compose -f deploy/docker/compose.prod.yml stop web
```

Collection stops immediately; queued events (queue mode) stay in Redis.

**Preserve evidence before cleaning up.** Copy `shared/var/log/`, the web server logs, and a database
dump (**without `daily_salts`**, [backups.md](backups.md)) to somewhere the attacker cannot reach.
Note that logs rotate and `job_runs` is purged after 90 days.

## 3. Eradicate and recover

1. Patch or upgrade: `./console deploy` a fixed release, or `docker compose pull && up -d`.
2. If the host itself was compromised, rebuild it. The application is a tarball or an image plus a
   database — reinstalling is [../deploy/tarball.md](../deploy/tarball.md) again.
3. Restore from a backup taken before the compromise if data was altered
   ([backups.md](backups.md)). Remember `daily_salts` is absent from backups by design and refills
   itself.
4. Re-run `app:preflight`, `health:check` and `jobs:status`.
5. Re-issue the credentials you revoked.

## 4. Assess the data exposure

| What the attacker had | What they could learn |
|---|---|
| A dashboard session or account | aggregate reports for the sites that account can see; the audit log; site settings. **No individual visitor rows** — no endpoint returns them |
| An API key (`conversions:write`) | write conversions for one site. It cannot read reports |
| An API key (`stats:read`) | content-key aggregates for one site, suppressed below `min_group_size` |
| Read access to the database | 13 months of visit-level rows: sanitised URLs, referrer hosts, campaign parameters, country, browser/OS/device families, daily visitor hashes, and — for consented visitors — visitor ids. No IP addresses, no User-Agent strings, no readable `customer_ref`, no passwords, no usable tokens |
| A database backup **including `daily_salts`** | additionally the ability to recompute base-level visitor hashes for the days whose salt is present, which makes those rows linkable to a candidate address plus User-Agent. This is why the salt is excluded from dumps |
| `.env` | the ability to decrypt TOTP secrets and to compute `customer_ref` hashes; database access if the DSN is in it |

Remember what is *not* at risk: passwords are argon2id hashes; session, invitation, reset and API-key
tokens are stored as SHA-256 hashes and cannot be replayed; TOTP secrets are encrypted with
`APP_ENCRYPTION_KEYS`.

## 5. Notify

If personal data was, or may have been, exposed, GDPR Article 33 gives the controller **72 hours** to
notify the supervisory authority from becoming aware, and Article 34 requires notifying the data
subjects when the risk to them is high.

Who notifies depends on the role: a self-hoster is the controller; an operator hosting for clients is
a processor and must notify **each affected client without undue delay** so that they can meet their
own deadline ([../privacy/controller-processor.md](../privacy/controller-processor.md)).

Have ready:

- what happened, when it was detected, and the period covered;
- the categories and approximate number of data subjects and records —
  [../privacy/data-inventory.md](../privacy/data-inventory.md) and the tables above;
- the likely consequences (for base-level data, the honest assessment is usually low: no addresses, no
  User-Agents, no cross-site identifiers, and daily hashes that cannot be linked across days *unless*
  the salt leaked with them);
- the measures taken and proposed;
- a contact point.

## 6. Afterwards

- Write it up: timeline, cause, what was exposed, what was changed.
- Fix the cause, not the symptom. If it is a bug in this software, report it privately per
  [../SECURITY.md](../SECURITY.md).
- Review whether monitoring would have caught it sooner ([monitoring.md](monitoring.md)).
- Check the retention of the evidence you copied — it inherits the same obligations as the original
  data.

## Quick reference

```sh
php bin/analytics user:disable <email>
php bin/analytics user:set-password <email> --password-stdin
php bin/analytics user:reset-2fa <email>
php bin/analytics api-key:revoke <prefix>
php bin/analytics user:list
php bin/analytics health:check --json
./console status && ./console list
```

```sql
UPDATE auth_sessions SET revoked_at = NOW() WHERE revoked_at IS NULL;
SELECT * FROM audit_log ORDER BY occurred_at DESC LIMIT 200;
```

## Related

[../SECURITY.md](../SECURITY.md) · [key-rotation.md](key-rotation.md) · [backups.md](backups.md) ·
[runbook.md](runbook.md) · [../privacy/dpia-inputs.md](../privacy/dpia-inputs.md)
