# 0006. The tracker is AGPL, like the rest of the repository

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

The repository is AGPL-3.0-or-later. The tracker is different from the rest of it in one respect: it
is not run by the operator, it is **delivered to third parties** — every visitor of every tracked site
downloads and executes `GET /t/{publicKey}.js`.

That raises a question for whoever puts the snippet on their site. A copyleft licence on a file that
their pages include could be read as reaching their page, their bundler output, or their site as a
whole. The usual answer for a client-side SDK is a permissive licence (MIT / BSD / Apache-2.0), which
is what most analytics vendors publish.

Against that: the tracker is not a library. It is not imported into anyone's build — it is loaded as a
standalone `<script src>` from the analytics service, with no linking, no bundling and no modification
expected. It is also the part of the system with the most privacy-relevant behaviour (what is stored
in the browser, when consent is honoured, what is sent). Its being inspectable and modifiable by the
people it runs on is the point of choosing AGPL for the service at all. A permissive tracker would
make it easy to ship a modified, closed tracker against an otherwise AGPL service.

## Decision

The tracker stays **AGPL-3.0-or-later**, the same as the rest of the repository. There is no separate
`LICENSE` under `services/tracker`.

To make the source offer real and visible:

- the built bundle carries the header
  `/*! analytics | AGPL-3.0-or-later | source: <SOURCE_URL> */` (`scripts/build.mjs`), and the serving
  endpoint re-adds it with the configured `SOURCE_URL` (`ScriptBundleBuilder::HEADER`);
- `SOURCE_URL` is an environment variable, so a modified deployment points at its own source;
- the dashboard shows the build, the commit and the source link.

The WordPress plugin is the exception and carries its own `LICENSE`: GPL-2.0-or-later, because the
WordPress ecosystem expects it. See [0008](0008-separate-wordpress-plugin.md).

## Consequences

- Loading the script with `<script src="…">` does not create a derivative work of the page, so putting
  the snippet on a proprietary site changes nothing about that site's licence. Modifying the tracker
  and serving it to visitors does trigger the AGPL's network clause.
- The whole repository has one licence, which keeps `LICENSE`/`NOTICE` and contribution handling
  simple.
- The alternative remains available: relicensing the `services/tracker` directory to MIT later would
  require agreement from its contributors. A DCO on contributions keeps that door open. If an
  integrator ever objects to the copyleft, the fallback is the same one every site already has —
  write their own `<script>` against the documented payload
  ([../../api/tracking-payload.v1.schema.json](../../api/tracking-payload.v1.schema.json)), which is a
  stable, versioned contract and is not covered by the tracker's licence.
- The size budget (5.0 KB gzip, `size-limit`) keeps the header's relative cost negligible.
