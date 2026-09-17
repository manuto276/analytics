# Keys and secrets

Two secrets matter, and they behave differently.

| Variable | Format | What it protects | Rotatable |
|---|---|---|---|
| `APP_ENCRYPTION_KEYS` | `id:base64key[,id:base64key]`, ids `[a-z0-9]{1,8}`, keys 32 bytes | encrypted columns — today only the TOTP secrets, with XChaCha20-Poly1305 | yes, online, with a key ring |
| `APP_SECRET` | base64 of 32 bytes | the master secret for derived keys — today the per-site `customer_ref` HMAC | **no safe rotation**; see below |

Generate fresh values:

```sh
php bin/analytics secrets:generate
# APP_SECRET=…
# APP_ENCRYPTION_KEYS=k260917:…      (the id is the current date)
# OPS_TOKEN=…
```

`OPS_TOKEN` is generated but **no endpoint consumes it** — there is no `/_ops/` route in the
application. It is harmless to leave empty.

Both are required in `APP_ENV=prod`; the application refuses to boot without them. In dev and test,
fixed throwaway values are used.

## Rotating `APP_ENCRYPTION_KEYS`

`SecretBox` stores ciphertext as `v1.<keyId>.<base64url(nonce‖ciphertext)>`. **The last key in the
ring encrypts; every key in the ring can decrypt.** That makes rotation a three-step, zero-downtime
operation.

```sh
# 1. append a new key; keep the old one so existing ciphertext still decrypts
#    APP_ENCRYPTION_KEYS=k250101:<old>,k260917:<new>
$EDITOR shared/.env        # or the compose env_file
./console app cache:clear  # container definitions cache the settings

# 2. re-encrypt everything with the new active key
./console app secrets:rotate-key
# Re-encrypted 7 secret(s) with key "k260917". Old keys can be removed from
# APP_ENCRYPTION_KEYS once no secret uses them.

# 3. remove the old key
#    APP_ENCRYPTION_KEYS=k260917:<new>
$EDITOR shared/.env
./console app cache:clear
./console app health:check
```

Do not skip step 2. Removing a key that some ciphertext still references makes that ciphertext
undecryptable: `SecretBox::decrypt()` throws "Unknown key id", and the affected users can no longer
complete MFA. Recovery is `user:reset-2fa <email>` and re-enrolment.

Ids are free-form; using a date (`k260917`) makes the ring self-documenting. Never reuse an id for a
different key.

Rotate when a key may have been exposed (a leaked backup of `.env`, a compromised host), when an
administrator with access to it leaves, or on a schedule you have set yourself.

## `APP_SECRET`

`APP_SECRET` is the master secret of `KeyDerivation`, which derives purpose-bound subkeys with a keyed
BLAKE2b. Today it has exactly one consumer: `CustomerRefHasher`, which keys the per-site HMAC used to
store `customer_ref` on conversions.

There is no re-derivation command, and there cannot be a complete one: the raw `customer_ref` values
are not stored — only their HMACs — so old hashes cannot be recomputed under a new secret.

**What changing `APP_SECRET` breaks:**

| Effect | Detail |
|---|---|
| `customer_ref` attribution splits in two | conversions written after the change hash differently from the historic ones, so a follow-up conversion no longer inherits the earlier campaign for the same customer. Attribution through `visitor_id` is unaffected |
| Historic `customer_ref` values become opaque | they already were; they simply no longer match anything new |

**What it does not break**, contrary to what is easy to assume: session tokens, invitation tokens,
password-reset tokens and API key secrets are hashed with plain SHA-256 (`TokenHasher`), not with
`APP_SECRET`, so signed-in users stay signed in. Passwords use argon2id and are unaffected. TOTP
secrets use `APP_ENCRYPTION_KEYS`, not `APP_SECRET`.

**If you must change it** (it leaked):

1. Accept that `customer_ref`-based attribution restarts from zero.
2. Change the value, `cache:clear`, restart PHP.
3. Optionally `conversions:reattribute --site=…` to refresh the snapshots — it will not recover the
   old links, but it recomputes what it can from visitor touches.
4. Because a leaked `APP_SECRET` usually means the whole `.env` leaked, rotate the encryption keys and
   the database password in the same maintenance window, and treat it as an incident
   ([incident-response.md](incident-response.md)).

## API keys

Server API keys (`ak_<prefix>_<secret>`) are stored as a SHA-256 of the secret and shown once. To
rotate one:

```sh
php bin/analytics api-key:create --site=pk_XXXXXXXXXXXXXXXXXXXXX --name=shop-2026 --scopes=conversions:write
# deploy the new key to the caller, verify traffic, then:
php bin/analytics api-key:revoke <old prefix>
```

Both keys work in between, so there is no downtime. Keys may also carry an `expires_at`. Creation and
revocation are written to the audit log (`api_key.created`, `api_key.revoked`) and `last_used_at` (updated
at most every 5 minutes) shows whether a key is still in use before you revoke it.

## The database password

Change it in MySQL and in `.env`/`env_file`, then restart PHP (and the cron jobs pick it up on their
next run). Nothing in the application caches it beyond the process.

## Session invalidation

Not a key, but the related operation. To sign everyone out:

```sql
UPDATE auth_sessions SET revoked_at = NOW() WHERE revoked_at IS NULL;
```

Per user, from the dashboard (Settings → Security → sessions), through
`DELETE /api/v1/auth/sessions`, or implicitly: changing a password revokes that user's other sessions.

## Storage

- `shared/.env` is `0600`, owned by the site user; the deploy console creates it that way and
  symlinks it into every release.
- Keep a copy in a password manager or a secrets store, **separately from database backups**. Together
  they are the live system ([backups.md](backups.md)).
- Never commit a real value. `secrets:generate` exists so nobody is tempted to reuse an example.
- The audit log redacts any metadata key containing `password`, `secret`, `token`, `code`, `key`,
  `totp` or `recovery`.

## Checklist

- [ ] `APP_SECRET` and `APP_ENCRYPTION_KEYS` are 32-byte, base64, generated by `secrets:generate`
- [ ] `.env` is `0600` and backed up separately from the database
- [ ] Encryption key rotation rehearsed: append → `secrets:rotate-key` → remove
- [ ] Old encryption keys removed only after a successful `secrets:rotate-key`
- [ ] API keys per integration, scoped minimally, rotated by overlap
- [ ] You know that changing `APP_SECRET` resets `customer_ref` attribution

## Related

[backups.md](backups.md) · [incident-response.md](incident-response.md) ·
[../SECURITY.md](../SECURITY.md)
