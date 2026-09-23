<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/brand/banner-dark.png">
  <img src="docs/assets/brand/banner-light.png" alt="analytics — self-hosted, privacy-first, multi-site web analytics" width="100%">
</picture>

# analytics

Self-hosted, privacy-first, multi-site web analytics.

* **Two tracking levels.** A *base* level that needs no consent: no cookies, no persistent ids,
  shortened IP addresses, daily-rotating salted visitor hash (or pageviews only). A *cookie* level that
  runs only after the visitor accepts the consent banner built into the tracker.
* **Consent banner in the tracker.** Cookies are first-party on the tracked site; the service domain
  never sets cookies.
* **Marketing features.** Custom events and goals, a server-side conversions API, funnels, campaign
  attribution (first touch, last non-direct, declared), campaign cost import with CAC and ROAS,
  content-group stats and cohorts.
* **Dashboard.** Static Nuxt SPA (Nuxt UI) on the same origin as the Slim JSON API. English and Italian.
* **Two deployment modes.** Docker images, or a timestamp-versioned tarball deployed with an
  extension-less `console` on managed PHP hosts (no Node, no sudo).

Legal frame: GDPR, ePrivacy and national regulator guidance (in particular the Italian Garante
guidelines of 10 June 2021). See [docs/privacy](docs/privacy) and the points marked **LEGAL REVIEW**.

## Repository layout

| Path | Contents |
|---|---|
| `services/api` | PHP 8.4 backend: Slim 4, PHP-DI, Doctrine ORM/DBAL/Migrations, Symfony Console (`bin/analytics`) |
| `services/dashboard` | Nuxt 4 + Nuxt UI 4 static SPA |
| `services/tracker` | Tracker and consent banner (TypeScript, esbuild, < 5 KB gzip) |
| `services/e2e` | Playwright end-to-end tests with fixture sites |
| `services/wordpress-plugin/analytics-connector` | [Analytics for WordPress](services/wordpress-plugin/analytics-connector/README.md): the tracker, consent links and a dashboard in WordPress (GPL-2.0-or-later, released on `wordpress-plugin-v*` tags) |
| `deploy/docker` | Dockerfile (dev, test, package, runtime stages) and compose files |
| `deploy/manual` | `build.sh`, `publish.sh` and the deploy `console` for managed PHP hosts |
| `deploy/examples` | nginx vhost, first-party proxy, Varnish and crontab examples |
| `docs` | Architecture, ADRs, privacy, API, integration, deploy and operations docs |

## Quick start (development)

```sh
make up          # MySQL, PHP-FPM, nginx (https://analytics.test), node
make install     # composer + pnpm
make db-reset    # migrations
make seed        # demo site with 60 days of data
make test        # all test suites
```

See [docs/development/setup.md](docs/development/setup.md). The full documentation
(architecture, ADRs, privacy, API, integration, deployment, operations) starts at
[docs/README.md](docs/README.md).

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) and [NOTICE](NOTICE). If you run a modified version as a
network service, you must offer its source code to its users; the dashboard footer and the tracker
header link to the source.
