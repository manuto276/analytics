# 0001. Slim with a Doctrine ORM/DBAL split

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

The backend has two very different kinds of data.

*Configuration and identity* — sites, users, sessions, invitations, API keys, goals, funnels, costs,
consent revisions — is low-volume, richly related, edited through forms, and benefits from a mapper:
relations, change tracking, validation, generated migrations.

*Analytic data* — `events_raw`, `visits`, the rollups — is high-volume and written on the request
path. It needs multi-row `INSERT IGNORE`, `SELECT … FOR UPDATE`, `INSERT … SELECT` aggregates, and
monthly `RANGE COLUMNS` partitioning. MySQL forbids foreign keys on partitioned tables, requires the
partitioning column in every unique key, and Doctrine's schema tool knows nothing about partitions —
so a `migrations:diff` against ORM-mapped analytic tables would keep proposing to drop the partitions
and add foreign keys.

A full framework (Symfony, Laravel) was not needed: the HTTP surface is small and the hot path should
carry as little per-request work as possible. Using only DBAL everywhere would have meant hand-writing
the whole admin CRUD; using only the ORM would have meant fighting it on every partitioned table.

## Decision

Slim 4 with PHP-DI 7 (compiled container in production) and `php-di/slim-bridge` for the HTTP layer,
and a hard split inside Doctrine:

- **ORM entities** for configuration and identity. Attribute mapping under each module's `Domain/`
  directory, registered through `Module::entityPaths()`. Migration `Version20260917000001` is
  generated from that mapping.
- **DBAL only** for the hot and analytic tables. Migration `Version20260917000002` is hand-written
  SQL, including the `PARTITION BY RANGE COLUMNS(local_day)` clauses.
- `Analytics\Shared\Doctrine\SchemaAssets::DBAL_TABLES` lists the DBAL tables and is installed as
  Doctrine's schema-assets filter, so `migrations:diff` and `orm:validate-schema` ignore them.
- Reporting owns its read models: `RawSelects`, `TableReports` and `DailyMetrics` build SQL strings
  directly; no analytic query goes through DQL.

## Consequences

- The ingestion path does no ORM work at all: `IngestBatchHandler` uses `Connection` and prepared
  statements, one transaction per site batch.
- Partitioning, index shape and the exact `INSERT … SELECT` of every rollup are visible in one place
  and reviewable as SQL.
- The price is duplicated knowledge: a column added to an analytic table must be added to the raw
  SQL, to the insert column list and to the rollup selects by hand. There is no mapping to catch a
  typo; the integration tests do.
- Partitioned tables have no foreign keys, so referential integrity between `events_raw`, `visits`
  and `sites` is an application concern.
- `orm:validate-schema` stays meaningful for the half it covers, and
  `Analytics\Tests\Migrations\MigrationsTest::testOrmMappingMatchesTheMigratedSchema` fails if the
  generated migration and the entities drift apart.
- Deptrac keeps the layering honest, but it does not stop Reporting from reading another module's
  tables — that is deliberate (see [0002](0002-additive-rollups-visitor-days.md)).
