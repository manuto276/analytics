# 0007. Country lookup with DB-IP Lite, offline, on the shortened address

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

Country is the only geographic dimension the product needs. Anything finer (region, city) would both
exceed what the consent-exempt statistics can justify and be meaningless on a shortened address.

An online lookup service is out of the question: it would send visitor addresses to a third party,
which is exactly what this system is built to avoid, and it would add a network call to the hot path.
So the database must be a local file.

The candidates were MaxMind GeoLite2 (free, but requires an account, signed licence terms and an
authenticated download) and DB-IP IP-to-Country Lite (free, CC BY 4.0, plain monthly HTTPS download,
same MaxMind DB binary format).

## Decision

DB-IP IP-to-Country Lite, in MaxMind DB format, read with `maxmind-db/reader`.

- `bin/analytics geo:update [--force] [--url=…]` downloads
  `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz` (override with `--url` or
  `GEO_DB_URL`, `%s` = `YYYY-MM`), falls back to the previous month if the current one is not
  published yet, gunzips it, validates it by opening it with the reader, and swaps it in with
  `rename()`. Monthly from cron (`40 4 5 * *`).
- `GEO_DB_PATH` defaults to `<STORAGE_DIR>/geo/dbip-country-lite.mmdb`, which lives in `shared/` on a
  tarball deploy and in the `storage` volume in Docker, so it survives releases.
- `MaxMindGeoLocator::country()` is called with the **shortened** address only (IPv4 /24, IPv6 /48),
  after `ClientIpMiddleware` has discarded the full one. It returns a two-letter code or `null`, and
  memoises up to 5000 lookups per process.
- The file is optional. When it is missing or unreadable the locator returns `null` for everything and
  the application runs normally; `health:check` reports `geo_db: warn` and the countries report shows
  the rows under an unknown country.
- Attribution: DB-IP is credited in `NOTICE` and in the dashboard, as CC BY 4.0 requires.
- `bin/analytics geo:lookup <ip>` shortens an address exactly as ingestion does and prints the result,
  for debugging.

## Consequences

- No visitor address ever leaves the server, and no third party learns anything.
- Accuracy is limited twice over: by the free database, and by looking up a /24 or /48 network rather
  than a host. For a country-level metric that is acceptable; it is not usable for anything finer, and
  the data model does not store anything finer (`events_raw.country CHAR(2)`, `ZZ` for unknown in the
  rollups).
- The CC BY 4.0 attribution requirement is a licence obligation on every deployment, not just on this
  repository.
- If DB-IP changes its URL or licence, `geo:update --url` and `GEO_DB_URL` allow pointing at any
  MaxMind-format country database without a code change.
- `health:check` warns when the file is older than 45 days (`HealthChecker::GEO_MAX_AGE_DAYS`), which
  is how a silently failing cron becomes visible.
