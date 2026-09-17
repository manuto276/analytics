# Documentation

Documentation of `analytics`, a self-hosted, privacy-first, multi-site web analytics service
(AGPL-3.0-or-later). Everything here describes the code as it is in this repository; where a
feature was planned but not built, the page says so.

## Start here

| If you want to | Read |
|---|---|
| Understand the system | [architecture/overview.md](architecture/overview.md) |
| Put the tracker on a site | [integration/tracker.md](integration/tracker.md) |
| Install the service | [deploy/tarball.md](deploy/tarball.md) or [deploy/docker.md](deploy/docker.md) |
| Query the API | [api/reporting.md](api/reporting.md) |
| Answer a privacy question | [privacy/two-levels.md](privacy/two-levels.md) |
| Run it in production | [operations/runbook.md](operations/runbook.md) |
| Contribute | [CONTRIBUTING.md](CONTRIBUTING.md) |

## Architecture

| Page | Contents |
|---|---|
| [overview.md](architecture/overview.md) | components, request flow, the five invariants and where they are enforced |
| [modules.md](architecture/modules.md) | backend module map and the layering rules Deptrac enforces |
| [data-model.md](architecture/data-model.md) | every table, keys, partitioning, retention, ORM vs DBAL split |
| [ingestion.md](architecture/ingestion.md) | endpoint → parser → enrichment → visit resolution → sink |
| [reporting.md](architecture/reporting.md) | rollups, query planner, filters, caching, availability, CSV |
| [tracker.md](architecture/tracker.md) | tracker contract: `window.__an_cfg`, transport, cookies, JS API |
| [adr/](architecture/adr/) | architecture decision records ([template](architecture/adr/0000-template.md)) |

Decision records: [0001 Slim + Doctrine DBAL split](architecture/adr/0001-slim-doctrine-dbal-split.md) ·
[0002 additive rollups and visitor-days](architecture/adr/0002-additive-rollups-visitor-days.md) ·
[0003 MySQL partitioning](architecture/adr/0003-mysql-partitioning.md) ·
[0004 two consoles and release layout](architecture/adr/0004-two-consoles-and-release-layout.md) ·
[0005 static SPA on the same origin](architecture/adr/0005-static-spa-same-origin.md) ·
[0006 tracker license](architecture/adr/0006-tracker-license.md) ·
[0007 geo DB-IP Lite](architecture/adr/0007-geo-dbip-lite.md) ·
[0008 separate WordPress plugin](architecture/adr/0008-separate-wordpress-plugin.md)

## Privacy and law

| Page | Contents |
|---|---|
| [two-levels.md](privacy/two-levels.md) | base level vs cookie level, what changes |
| [garante-2021-mapping.md](privacy/garante-2021-mapping.md) | requirement → implementation → test id |
| [data-inventory.md](privacy/data-inventory.md) | every stored field, purpose, retention |
| [cookies.md](privacy/cookies.md) | the three cookies: names, contents, lifetimes, domains |
| [metric-availability.md](privacy/metric-availability.md) | which metrics exist per hash mode and level |
| [legal-review-points.md](privacy/legal-review-points.md) | open points that need a lawyer's decision |
| [dpia-inputs.md](privacy/dpia-inputs.md) | facts a data protection impact assessment needs |
| [controller-processor.md](privacy/controller-processor.md) | roles when hosting for yourself or for others |

## API

| Page | Contents |
|---|---|
| [openapi.yaml](api/openapi.yaml) | machine-readable contract (validated in the functional tests) |
| [tracking-payload.v1.schema.json](api/tracking-payload.v1.schema.json) | body of `POST /t/e` |
| [reporting.md](api/reporting.md) | report endpoints, parameters, filter syntax, curl examples |
| [conversions.md](api/conversions.md) | server-side conversions API, keys, idempotency, attribution |
| [errors.md](api/errors.md) | every problem `code` the backend returns |
| [examples/](api/examples/) | real request and response bodies |

## Integration

[tracker.md](integration/tracker.md) · [consent-banner.md](integration/consent-banner.md) ·
[spa.md](integration/spa.md) · [server-side-conversions.md](integration/server-side-conversions.md) ·
[first-party-proxy.md](integration/first-party-proxy.md) · [caching-proxies.md](integration/caching-proxies.md) ·
[wordpress.md](integration/wordpress.md)

## Deployment

[tarball.md](deploy/tarball.md) · [managed-php-hosts.md](deploy/managed-php-hosts.md) ·
[docker.md](deploy/docker.md) · [nginx.md](deploy/nginx.md) · [upgrading.md](deploy/upgrading.md)

## Operations

[runbook.md](operations/runbook.md) · [cron.md](operations/cron.md) · [backups.md](operations/backups.md) ·
[monitoring.md](operations/monitoring.md) · [retention.md](operations/retention.md) ·
[key-rotation.md](operations/key-rotation.md) · [incident-response.md](operations/incident-response.md)

## Development

[setup.md](development/setup.md) · [testing.md](development/testing.md) ·
[conventions.md](development/conventions.md) · [i18n.md](development/i18n.md) ·
[release.md](development/release.md)

## Project

[CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md) · [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) ·
[plan/implementation-plan.md](plan/implementation-plan.md) (the original plan; it describes intent, not
necessarily the current code)

## Conventions used in these pages

- Example hosts are `stats.example.net` (the service) and `www.example.com` / `app.example.com`
  (tracked sites). Test hosts are `analytics.test` and `*.site.test`.
- Paths are relative to the repository root.
- `LEGAL REVIEW` marks a point that needs a decision from a lawyer before the behaviour is relied
  upon; they are collected in [privacy/legal-review-points.md](privacy/legal-review-points.md).
