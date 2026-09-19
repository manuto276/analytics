# Managed PHP hosts (CloudPanel and similar)

Step by step for a panel-managed host: a site user with SSH, PHP-FPM 8.4, nginx, MySQL, no root, no
Docker, no Node. Everything below is done once; afterwards a release is
[`./console deploy`](tarball.md).

Throughout: `stats.example.net` is the service host and
`/home/site/htdocs/stats.example.net` the deploy root. Adjust to your panel's layout.

## 1. Create the site

In the panel, create a **PHP site** for `stats.example.net`:

- PHP version **8.4** (the application requires ≥ 8.4.1 and `app:preflight` refuses less);
- its own PHP-FPM pool, running as the site user;
- **web root `current/public`** relative to the site directory — this is the one setting that differs
  from a normal site. If the panel cannot express it, point the root at the site directory and change
  `root` in the vhost (step 4).

Check the PHP extensions: `pdo_mysql`, `sodium`, `intl`, `mbstring`, `json` are required, `opcache`
strongly recommended.

```sh
php8.4 -m | grep -E 'pdo_mysql|sodium|intl|mbstring|json|Zend OPcache'
php8.4 -v
```

Recommended `php.ini` values for the pool: `memory_limit=256M`, `max_execution_time=60`,
`opcache.enable=1`, `opcache.validate_timestamps=0` (see step 4 for why that is safe here),
`expose_php=Off`.

## 2. Database

Create a database and a dedicated user in the panel.

```sql
CREATE DATABASE analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'analytics'@'localhost' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON analytics.* TO 'analytics'@'localhost';
```

`ALTER` and `DROP` are needed: migrations create the schema and `partitions:maintain` /
`retention:purge` reorganise and drop partitions. MySQL 8.4 is expected; `app:preflight` warns below
8.0.

If the host forbids partitioning, set `DB_PARTITIONING=false` in `.env` **before the first deploy** —
it changes how the tables are created.

## 3. Install the deploy root

```sh
ssh site@host
cd /home/site/htdocs/stats.example.net

# the console, once
scp deploy/manual/console site@host:/home/site/htdocs/stats.example.net/console   # from your machine
chmod 750 console

./console init          # creates packages/ releases/ shared/ .deploy/ and deploy.ini
nano deploy.ini         # php_binary="/usr/bin/php8.4" ; health_url="https://stats.example.net/api/v1/health"
nano shared/.env        # see below
chmod 600 shared/.env
```

Minimum `.env` (full list in [../architecture/overview.md](../architecture/overview.md#configuration),
template `deploy/docker/env.example`):

```dotenv
APP_ENV=prod
APP_URL=https://stats.example.net
APP_SECRET=<base64 of 32 random bytes>
APP_ENCRYPTION_KEYS=k1:<base64 of 32 random bytes>
DATABASE_URL=mysql://analytics:…@127.0.0.1:3306/analytics?serverVersion=8.4
DB_PARTITIONING=true
INGEST_MODE=sync
RETENTION_MONTHS=13
TRUSTED_PROXIES=
STORAGE_DIR=/home/site/htdocs/stats.example.net/shared/var/storage
LOG_DIR=/home/site/htdocs/stats.example.net/shared/var/log

# Optional: password reset and email changes. Without it the "forgot password" link is hidden.
MAILER_DSN=smtps://stats%40example.net:password@smtp.example.net:465
MAIL_FROM=stats@example.net
MAIL_FROM_NAME=Analytics
```

`MAILER_DSN` examples for common providers, and how to encode special characters in the password,
are in [../operations/mail.md](../operations/mail.md). After the first deploy, check the account
with `./console app mail:test --to=you@example.com`.

Generate the secrets with `php8.4 -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`, or after the
first deploy with `./console app secrets:generate`.

Then deploy:

```sh
scp dist/analytics-*.tar.gz dist/analytics-*.tar.gz.sha256 site@host:/…/packages/   # from your machine
./console deploy
./console status
```

## 4. Vhost edits

Full file: `deploy/examples/nginx-vhost.conf`; the reasoning is in [nginx.md](nginx.md). Four things
must be right.

**a. Web root** is `…/current/public`.

**b. `$realpath_root`.** There is no way to reload PHP-FPM, and OPcache caches by file path. Because
`current` is a symlink, the path `…/current/public/index.php` never changes and OPcache would keep
serving the previous release. Pass the *resolved* path instead:

```nginx
fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
fastcgi_param SCRIPT_NAME     /index.php;
fastcgi_param DOCUMENT_ROOT   $realpath_root;
```

Each release then has its own OPcache entries and a deploy is picked up immediately.

**c. Routing.** Only `/api/`, `/t/` and `/_ops/` reach PHP; `/_nuxt/` is immutable; everything else
falls back to the SPA:

```nginx
location / { try_files $uri /index.html; }
location = /index.html { add_header Cache-Control "no-cache" always; }
location ^~ /_nuxt/    { add_header Cache-Control "public, max-age=31536000, immutable" always; try_files $uri =404; }
location ^~ /api/      { …fastcgi… }
location ^~ /t/        { …fastcgi, anonymised log… }
location ~ \.php$      { return 404; }
location ~ /\.         { deny all; }
```

**d. Anonymised logging for `/t/`.** In the `http` context (panels usually allow it in the vhost file,
outside `server`):

```nginx
log_format analytics_anon '[$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent $request_time';
```

and inside the `/t/` location, `access_log /home/site/logs/nginx/tracking.log analytics_anon;` — or
`access_log off;`. Otherwise the web server log becomes a full-IP record of every visitor, which
defeats the point of shortening the address in the application.

Reload nginx through the panel after editing, and re-check after panel upgrades: some panels
regenerate vhosts.

## 5. TLS

Issue a certificate through the panel (Let's Encrypt) for `stats.example.net`. `APP_URL` must be
`https://`: `app:preflight` fails a production install whose `APP_URL` is not HTTPS unless it is a
loopback address, and the session cookie uses the `__Host-` prefix only over HTTPS.

Redirect port 80 to 443 (the example vhost does).

## 6. Cron

Add these as the **site user**, through the panel's cron UI or `crontab -e`. Full file:
`deploy/examples/crontab.example`, explained in [../operations/cron.md](../operations/cron.md).

```cron
*/5  * * * * cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics rollup:run -q >> var/log/cron.log 2>&1
*/15 * * * * cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics salt:rotate -q >> var/log/cron.log 2>&1
20 3 * * *   cd /home/site/htdocs/stats.example.net/current && (nice -n 10 /usr/bin/php8.4 bin/analytics partitions:maintain -q && nice -n 10 /usr/bin/php8.4 bin/analytics retention:purge -q) >> var/log/cron.log 2>&1
40 4 5 * *   cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics geo:update -q >> var/log/cron.log 2>&1
```

Always go through `current`, so jobs follow deploys and rollbacks. Every job takes a lock and records
a row in `job_runs`, so overlapping runs are skipped safely.

Then fetch the country database once:

```sh
./console app geo:update
./console app jobs:status
```

## 7. Page cache

Panels often put a page cache (Varnish) in front of every site. **Turn it off for this site.** The
API responses are `private`, the collect endpoint is `no-store`, and a cache that does not honour
those will serve one visitor's dashboard data to another. If it cannot be disabled, restrict caching
to `/_nuxt/`, `/_fonts/` and `/t/*.js` and pass everything else — see
[../integration/caching-proxies.md](../integration/caching-proxies.md).

## 8. First administrator and first site

```sh
./console app user:create-admin --email=admin@example.com
./console app site:create --name="Example" --domain='*.example.com' --timezone=Europe/Rome --cookie-domain=example.com
./console app site:show pk_XXXXXXXXXXXXXXXXXXXXX    # prints the snippet
```

Sign in at `https://stats.example.net/` and put the snippet on the tracked site
([../integration/tracker.md](../integration/tracker.md)).

## 9. Verify

```sh
curl -s https://stats.example.net/api/v1/health            # {"status":"ok","version":"…","commit":"…"}
./console app health:check
./console app app:preflight
./console status
```

Then load a tracked page and confirm a `202` from `POST /t/e` and an active visitor in Realtime.

## Troubleshooting

| Symptom | Cause |
|---|---|
| The old release is still served after a deploy | `$realpath_root` is not used in the fastcgi parameters (step 4b) |
| `500` immediately after a deploy | check `shared/var/log/`; usually a missing `.env` value — `./console app app:preflight` names it |
| `The dashboard has not been built` | the package was built without the SPA; rebuild with `make package` |
| `403 origin_not_allowed` from `/t/e` | the tracked host is not registered under the site's domains |
| `APP_SECRET is required` | `.env` is not linked or not readable; check `current/.env` resolves into `shared/` |
| Every visitor shares one visitor hash | the site is behind a proxy and `TRUSTED_PROXIES` is empty |
| `health_url` returns the old commit | the health check ran before nginx picked up the new root; it retries `health_tries` times |
| Reports stay empty | `rollup:run` is not running; check `./console app jobs:status` |
| Writes fail with a permissions error | `shared/var/**` must be writable by the site user; `./console init` creates them |

## Related

[tarball.md](tarball.md) · [nginx.md](nginx.md) · [upgrading.md](upgrading.md) ·
[../operations/cron.md](../operations/cron.md) · [../operations/runbook.md](../operations/runbook.md)
