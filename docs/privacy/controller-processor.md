# Controller and processor roles

Who is what depends on who decides the purposes and means of the processing, not on who runs the
server. Two deployments are common.

This page describes what the software supports. It is not legal advice; the role assessment and the
paperwork are the deployment's responsibility (LR-6 in [legal-review-points.md](legal-review-points.md)).

## A. Self-hosting for your own sites

One organisation runs the service and owns the tracked sites.

- That organisation is the **controller** for the visitor data.
- There is no processor for the analytics itself: no runtime third party is involved.
- Processors may still exist around it — the hosting provider, and the mail provider if `MAILER_DSN`
  is configured.

What is needed: an entry in the record of processing activities, the cookie and privacy notice on the
tracked sites, and the retention decision (LR-8).

## B. Hosting the service for someone else

An agency or a platform runs one installation and gives each client access to their own site.

- Each **client is the controller** of their visitors' data.
- The **operator is a processor** for each of them, and must process only on documented instructions.
- A processing agreement is required between the operator and each client. **This repository provides
  no template.**

Sub-processors of the operator (hosting, mail) must be disclosed to the clients.

### What the software gives you for this

| Need | Support |
|---|---|
| Separation between clients | everything is keyed by `site_id`: identifiers, reports, rollups, API keys, goals, funnels, costs, consent configurations |
| No cross-client identifiers | `site_id` is part of the hashed input of the visitor hash, so two sites never produce the same hash for the same browser; `an_vid` is a first-party cookie of the client's own domain |
| Access control | global roles (`admin`, `member`) plus per-site roles (`admin`, `viewer`) in `user_site_roles`; every API route declares a permission and `AccessMiddleware` fails closed |
| "Only my site" | a member sees only the sites they are a member of; `Authorizer::accessibleSiteIds()` |
| Instructions and settings per client | per-site tracking level, hash mode, cookie domain, retention-relevant switches, consent texts and theme |
| Demonstrating what was done | `audit_log` records administrative actions with actor, target and shortened IP, 24 months |
| Erasure on a client's instruction | `POST /t/forget` per visitor; archiving a site (`DELETE /api/v1/sites/{id}` sets `archived_at`) stops collection |
| Export | CSV export of any table report; the reports API |

### What it does not give you

- **No hard tenancy boundary.** One database, one application, one set of operating-system
  credentials. A global admin can see every site. If clients require isolation, run one installation
  per client.
- **No per-client retention setting.** `RETENTION_MONTHS` is global.
- **No site deletion.** Archiving stops collection and hides the site; the rows are removed by
  retention over the following 13 months. There is no "delete this site and all its data now"
  command (**not implemented**).
- **No data export of an entire site** beyond the report CSV exports.
- **No processing agreement template**, and no sub-processor list — those are yours to write.

## Roles for the operator's own users

Dashboard accounts (`users`, `auth_sessions`, `totp_credentials`, `audit_log`) are the operator's own
data about their staff. The operator is the controller for those, in both deployments.

## Practical checklist for deployment B

1. Create one site per client domain set; decide LR-2 (subdomains as one site) with them.
2. Invite the client's users with per-site roles; never give a client a global admin role.
3. Agree the tracking level and hash mode per site, and record it as an instruction.
4. Document the retention period and who may trigger `retention:purge` and `POST /t/forget`.
5. Disclose your hosting and mail sub-processors.
6. Keep `audit_log` intact — it is the record of what was done on whose behalf.

See also [dpia-inputs.md](dpia-inputs.md) and [data-inventory.md](data-inventory.md).
