# Data model

MySQL 8.4, `utf8mb4` with `utf8mb4_0900_ai_ci` (paths and the cache key use `utf8mb4_bin` /
`VARBINARY`). Timestamps are UTC `DATETIME(3)`; `local_day` / `day` are `DATE` values in the site's
time zone.

Two migrations create everything and both are forward-only (`down()` throws `IrreversibleMigration`)
and non-transactional:

| Migration | Contents |
|---|---|
| `Version20260917000001` | configuration and identity tables, generated from the ORM mapping |
| `Version20260917000002` | analytic, ingestion and rollup tables, raw SQL, monthly partitions |

## ORM vs DBAL

**O** = mapped as a Doctrine ORM entity. **D** = raw SQL migration, read and written with DBAL only.

The DBAL-only tables are listed in `Analytics\Shared\Doctrine\SchemaAssets::DBAL_TABLES` and that list
is installed as Doctrine's schema-assets filter, so they are invisible to `migrations:diff` and
`orm:validate-schema`. The split exists because the analytic tables are partitioned, have no foreign
keys, and are written in multi-row statements on the hot path — none of which the ORM models well.
See [adr/0001-slim-doctrine-dbal-split.md](adr/0001-slim-doctrine-dbal-split.md).

Partitioned tables (`events_raw`, `visits`) have **no foreign keys** at all: MySQL forbids them on
partitioned tables. Referential integrity is maintained by the application and asserted in tests.

## Configuration and identity (ORM)

| Table | Purpose | Key columns | Keys |
|---|---|---|---|
| `sites` (O) | one tracked site: identity, tracking switches, privacy settings | `public_key CHAR(24)`, `name`, `timezone`, `currency`, `base_tracking_enabled`, `visitor_hash_mode`, `cookie_level_enabled`, `cookie_domain`, `visitor_cookie_days`, `new_visit_on_campaign_change`, `dnt_mode`, `respect_gpc`, `hash_routing`, `allow_localhost`, `tracker_global`, `allowed_query_params` JSON, `excluded_paths` JSON, `excluded_ip_prefixes` JSON, `content_contact_events` JSON, `auto_events` JSON, `min_group_size`, `consent_receipts_enabled`, `rollup_version`, `created_at`, `updated_at`, `archived_at` | PK `id`; UNIQUE `public_key` |
| `site_domains` (O) | hosts allowed to send events for a site | `site_id`, `host`, `include_subdomains`, `created_at` | PK `id`; UNIQUE (`site_id`,`host`); IDX `host`; FK → `sites` ON DELETE CASCADE |
| `users` (O) | dashboard accounts | `email`, `password_hash` (argon2id), `display_name`, `global_role`, `locale`, `status`, `failed_logins`, `locked_until`, `password_changed_at`, `last_login_at` | PK `id`; UNIQUE `email` |
| `user_site_roles` (O) | per-site membership | `role` (`admin`/`viewer`), `created_at` | PK (`user_id`,`site_id`); IDX `site_id` |
| `invitations` (O) | invitation links | `email`, `token_hash BINARY(32)`, `global_role`, `site_roles` JSON, `invited_by`, `expires_at`, `accepted_at`, `revoked_at` | PK `id`; UNIQUE `token_hash` |
| `auth_sessions` (O) | dashboard sessions | `id BINARY(32)` (sha256 of the token), `user_id`, `state` (`pending_mfa`/`active`), `csrf_secret`, `created_at`, `last_seen_at`, `idle_expires_at`, `absolute_expires_at`, `ip_prefix`, `ua_summary`, `revoked_at` | PK `id`; IDX `user_id`, `absolute_expires_at` |
| `totp_credentials` (O) | TOTP secret, encrypted | `secret_ciphertext`, `key_id`, `confirmed_at`, `last_used_step` | PK `user_id` |
| `recovery_codes` (O) | one-time MFA recovery codes | `user_id`, `code_hash BINARY(32)`, `used_at` | PK `id`; IDX `user_id` |
| `password_resets` (O) | reset tokens (mailer only) | `token_hash BINARY(32)`, `user_id`, `expires_at`, `used_at` | PK `token_hash`; IDX `user_id` |
| `email_changes` (O) | pending sign-in address changes (mailer only), 24 h tokens | `token_hash BINARY(32)`, `user_id`, `new_email`, `expires_at`, `used_at`, `created_at` | PK `token_hash`; IDX `user_id` |
| `api_keys` (O) | server API keys | `site_id`, `name`, `prefix CHAR(8)`, `secret_hash BINARY(32)`, `scopes` JSON, `created_by`, `created_at`, `last_used_at`, `expires_at`, `revoked_at` | PK `id`; UNIQUE `prefix`; IDX `site_id` |
| `goals` (O) | goal definitions | `site_id`, `name`, `type` (`pageview`/`event`/`conversion`), `` `match` `` JSON | PK `id`; UNIQUE (`site_id`,`name`) |
| `funnels` (O) | funnel definitions | `site_id`, `name`, `scope` (`visit`/`visitor`), `window_days` | PK `id`; UNIQUE (`site_id`,`name`) |
| `funnel_steps` (O) | ordered steps | `funnel_id`, `position`, `goal_id` | PK (`funnel_id`,`position`); IDX `goal_id`; FK → `funnels` ON DELETE CASCADE |
| `campaign_costs` (O) | imported ad spend | `site_id`, `day_from`, `day_to`, `channel`, `utm_source/medium/campaign`, `amount_minor`, `currency`, `note`, `import_batch_id`, `created_by`, `created_at` | PK `id`; IDX (`site_id`,`day_from`) |
| `consent_configs` (O) | revisions of the banner configuration | `site_id`, `revision`, `consent_version`, `status` (`draft`/`published`/`archived`), `texts` JSON, `policy_urls` JSON, `default_locale`, `theme` JSON, `accepted_ttl_days`, `rejected_ttl_days`, `show_floating_reopen`, `created_at`, `published_at`, `published_by` | PK `id`; UNIQUE (`site_id`,`revision`); IDX (`site_id`,`status`) |
| `audit_log` (O) | administrative actions | `occurred_at`, `actor_type`, `actor_id`, `action`, `site_id`, `target_type`, `target_id`, `metadata` JSON (redacted), `ip_prefix` | PK `id`; IDX (`site_id`,`occurred_at`), (`actor_type`,`actor_id`,`occurred_at`), `occurred_at` |

Site defaults (from `Analytics\Sites\Domain\Site`): `timezone=UTC`, `currency=EUR`,
`base_tracking_enabled=true`, `visitor_hash_mode=daily_hash`, `cookie_level_enabled=false`,
`visitor_cookie_days=395` (also the maximum), `new_visit_on_campaign_change=true`, `dnt_mode=ignore`,
`respect_gpc=true`, `hash_routing=false`, `allow_localhost=false`, `tracker_global=analytics`,
`allowed_query_params=[utm_source, utm_medium, utm_campaign, utm_content, utm_term, ref]`,
`min_group_size=5`, `consent_receipts_enabled=false`, `rollup_version=1`.

## Ingestion and analytic tables (DBAL)

| Table | Purpose | Key columns | Keys / partitioning |
|---|---|---|---|
| `cache_items` (D) | Symfony `DoctrineDbalAdapter` cache and rate-limit storage when Redis is absent | `item_id VARBINARY(255)`, `item_data`, `item_lifetime`, `item_time` | PK `item_id` |
| `daily_salts` (D) | today's visitor-hash salt | `day DATE`, `salt BINARY(32)`, `created_at` | PK `day`; **never backed up**, older rows deleted on every read/rotation |
| `events_raw` (D) | one row per accepted event | `local_day`, `site_id`, `event_uid BINARY(12)`, `occurred_at`, `received_at`, `level`, `type`, `name`, `visit_id`, `is_entry`, `visitor_hash BIGINT UNSIGNED`, `visitor_id BINARY(16)`, `host`, `path`, `page_hash BINARY(8)`, `query`, `referrer_host`, `channel`, `source`, `utm_*`, `browser`, `browser_major`, `os`, `os_major`, `device`, `country CHAR(2)`, `content_key`, `engagement_ms`, `scroll_pct`, `props` JSON | PK (`id`,`local_day`); UNIQUE (`site_id`,`event_uid`,`local_day`); IDX (`site_id`,`local_day`,`type`), (`site_id`,`received_at`), (`site_id`,`visitor_id`,`local_day`), (`site_id`,`visit_id`); RANGE COLUMNS(`local_day`) monthly |
| `visits` (D) | one row per visit (session) | `local_day`, `site_id`, `level`, `started_at`, `last_activity_at`, `visitor_hash`, `visitor_id`, `entry_host`, `entry_path`, `entry_page_hash`, `exit_page_hash`, `pageviews`, `events`, `engagement_ms`, `is_bounce`, `channel`, `source`, `referrer_host`, `utm_*`, `browser`, `os`, `device`, `country`, `entry_content_key` | PK (`id`,`local_day`); IDX (`site_id`,`local_day`), (`site_id`,`visitor_id`,`started_at`), (`site_id`,`started_at`); RANGE COLUMNS(`local_day`) monthly |
| `visit_lookup` (D) | "which visit is this visitor key currently in" | `site_id`, `visitor_key BINARY(16)`, `visit_id`, `visit_day`, `last_activity_at`, `source_key BINARY(8)` | PK (`site_id`,`visitor_key`); IDX `last_activity_at` |
| `visitors` (D) | consented visitors only | `site_id`, `visitor_id BINARY(16)`, `first_seen_at`, `last_seen_at`, `first_touch_id`, `visits`, `consent_version` | PK (`site_id`,`visitor_id`); IDX `last_seen_at` |
| `attribution_touches` (D) | first visit and each non-direct entry of a consented visitor | `site_id`, `visitor_id`, `visit_id`, `visit_day`, `touched_at`, `is_first`, `channel`, `source`, `referrer_host`, `utm_*`, `landing_host`, `landing_path` | PK `id`; IDX (`site_id`,`visitor_id`,`touched_at`), `touched_at` |
| `conversions` (D) | server-side conversions with an attribution snapshot | `site_id`, `external_id`, `name`, `origin`, `occurred_at`, `local_day`, `received_at`, `visitor_id`, `customer_ref BINARY(32)` (HMAC), `value_minor`, `currency`, `props` JSON, `attr_model_version`, `attr_via`, `attr_touch_id`, `attr_touched_at`, `attr_channel`, `attr_source`, `attr_utm_source/medium/campaign`, `lnd_*` (last non-direct), `declared_source` JSON | PK `id`; UNIQUE (`site_id`,`external_id`); IDX (`site_id`,`name`,`local_day`), (`site_id`,`local_day`), (`site_id`,`customer_ref`,`occurred_at`), (`site_id`,`visitor_id`) |
| `consent_stats_daily` (D) | banner counters, no identifiers | `site_id`, `day`, `consent_version`, `shown`, `accepted`, `rejected`, `dismissed`, `reopened`, `changed_to_accept`, `changed_to_reject` | PK (`site_id`,`day`,`consent_version`) |
| `consent_stat_uids` (D) | event uids of banner statistics, so a retried beacon is counted once | `site_id`, `local_day DATE`, `event_uid BINARY(12)` | PK (`site_id`,`local_day`,`event_uid`); RANGE COLUMNS(`local_day`) monthly |
| `consent_receipts` (D) | optional per-visitor consent record | `site_id`, `visitor_id`, `consent_version`, `decision`, `decided_at` | PK `id`; IDX (`site_id`,`visitor_id`), `decided_at` |
| `rollup_dirty` (D) | days whose rollups must be rebuilt | `site_id`, `day`, `first_marked_at`, `marked_at` | PK (`site_id`,`day`); IDX `first_marked_at` |
| `job_runs` (D) | one row per scheduled job run | `job`, `started_at`, `finished_at`, `status`, `message`, `stats` JSON | PK `id`; IDX (`job`,`started_at`) |

`changed_to_accept` and `changed_to_reject` exist in `consent_stats_daily` but nothing writes them:
the tracker sends one `cs` event per action (`shown`, `accept`, `reject`, `dismiss`, `reopen`) and
`IngestBatchHandler::incrementConsentStats()` only increments those five columns. Treat them as
reserved.

## Rollup tables (DBAL)

Every rollup has a primary key starting with `(site_id, day)` and only additive metrics, so a range is
answered by `SUM()` over days. `visitors` in a daily rollup means **visitor-days** (see
[adr/0002](adr/0002-additive-rollups-visitor-days.md)).

| Table | PK after (`site_id`,`day`) | Metrics |
|---|---|---|
| `rollup_overview_daily` | — | `pageviews`, `visits`, `visitors` (nullable), `bounces`, `engagement_ms`, `events`, `consented_visits`, `conversions`, `revenue_minor` |
| `rollup_pages_daily` | `page_hash` | `host`, `path`, `pageviews`, `visits`, `visitors`, `entries`, `exits`, `entry_bounces`, `engagement_ms` |
| `rollup_landing_daily` | `page_hash`, `channel` | `host`, `path`, `entries`, `bounces` |
| `rollup_sources_daily` | `channel`, `source_hash` | `source`, `referrer_host`, `visits`, `visitors`, `bounces`, `pageviews`, `engagement_ms` |
| `rollup_campaigns_daily` | `utm_hash` | `utm_source/medium/campaign/content/term`, `visits`, `visitors`, `bounces`, `pageviews` |
| `rollup_tech_daily` | `dimension`, `value` | `visits`, `visitors`, `pageviews` (`dimension` ∈ `device`, `browser`, `os`) |
| `rollup_geo_daily` | `country` | `visits`, `visitors`, `pageviews` (unknown country stored as `ZZ`) |
| `rollup_events_daily` | `name`, `prop_key`, `prop_value_hash` | `prop_value`, `occurrences`, `visits` (`prop_key=''` is the per-event total) |
| `rollup_content_daily` | `content_key`, `channel` | `pageviews`, `visits`, `visitors`, `contacts` |
| `rollup_conversions_daily` | `name`, `attr_channel`, `attr_hash` | `attr_source`, `attr_utm_source/medium/campaign`, `count`, `value_minor`, `attributed` |
| `rollup_consented_visitors_monthly` | `month`, `dimension`, `value_hash` | `value`, `visitors` — **true** monthly uniques, consented only (`dimension` ∈ `total`, `channel`, `cohort`) |

Hashes (`page_hash`, `source_hash`, `utm_hash`, `attr_hash`, `prop_value_hash`, `value_hash`) are the
first 8 bytes of a SHA-256 over the concatenated dimension values. `page_hash` is computed in PHP by
`UrlSanitizer::pageHash(host, path)`; the others are computed in SQL so the rollup and the raw query
produce the same grouping.

## Partitioning

`Analytics\Shared\Doctrine\Partitioning` manages `RANGE COLUMNS(local_day)` on `events_raw` and
`visits`:

- `p_old` — everything before the first month created at install time;
- `pYYYYMM` — one partition per month, holding that month's `local_day` values;
- `pmax` — catch-all (`VALUES LESS THAN (MAXVALUE)`).

`partitions:maintain [--ahead=3]` reorganises `pmax` to add the current month plus the next three.
`--past-from=YYYY-MM-DD` splits `p_old` into months so imported history can also be dropped a month at
a time. Retention drops whole partitions whose upper bound is at or before the cutoff and then deletes
the stragglers in chunks.

Set `DB_PARTITIONING=false` on hosts where partitioning is unavailable: the migration then creates the
tables without a `PARTITION BY` clause and retention falls back to chunked `DELETE … LIMIT 5000`.
`MigrationsTest::testDatabaseWithoutPartitioningWorks` covers that path.

## Retention

Enforced by `bin/analytics retention:purge` (see [../operations/retention.md](../operations/retention.md)).

| Data | Window | Column used |
|---|---|---|
| `events_raw`, `visits`, `consent_stat_uids` | `RETENTION_MONTHS` (default 13) | `local_day` |
| `visit_lookup` | same | `visit_day` |
| `attribution_touches` | same | `touched_at` |
| `conversions` | same | `local_day` |
| `consent_receipts` | same | `decided_at` |
| `visitors` | same | `last_seen_at` |
| `consent_stats_daily` | same | `day` |
| `audit_log` | 24 months | `occurred_at` |
| `job_runs` | 90 days | `started_at` |
| `auth_sessions` | expired or revoked | `absolute_expires_at` / `idle_expires_at` / `revoked_at` |
| `invitations` | 30 days after acceptance or expiry | `accepted_at` / `expires_at` |
| `password_resets` | on expiry | `expires_at` |
| `email_changes` | on expiry | `expires_at` |
| `daily_salts` | one day; older rows deleted on every salt read and by `salt:rotate` | `day` |
| rollup tables | kept indefinitely | — |

`retention:purge` refuses to run when a day before the cutoff is still in `rollup_dirty`, so raw data
is never deleted before it has been rolled up (`--force` overrides). Covered by
`Analytics\Tests\Integration\Retention\RetentionTest`.

## Migration policy

Forward-only and expand/contract: each release's schema must work with the previous release's code,
because a rollback only moves the `current` symlink and never reverts the database.
`Analytics\Tests\Migrations\MigrationsTest::testMigrationsAreForwardOnlyAndExpandOnly` checks that
`down()` throws and that migrations do not contain destructive statements. See
[../deploy/upgrading.md](../deploy/upgrading.md).
