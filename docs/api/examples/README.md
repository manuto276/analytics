# API examples

Request and response bodies taken from the functional tests, so they match what the server actually
produces.

| File | What it is |
|---|---|
| [collect-request.json](collect-request.json) | `POST /t/e` — a base-level batch: pageview, custom event, engagement |
| [collect-consented-request.json](collect-consented-request.json) | `POST /t/e` — a cookie-level batch with a consent upgrade |
| [conversion-request.json](conversion-request.json) | `POST /api/v1/server/sites/{publicKey}/conversions` |
| [conversion-batch-response.json](conversion-batch-response.json) | the `202` body for a partly duplicate, partly invalid batch |
| [report-overview.json](report-overview.json) | `GET …/reports/overview?period=7d&compare=previous_period` |
| [report-pages.json](report-pages.json) | `GET …/reports/pages?period=7d` |
| [problem-validation.json](problem-validation.json) | an RFC 9457 problem document |

Each file carries a `_comment` key naming the endpoint and the test it came from. That key is **not**
part of any real payload — the tracking payload schema sets `additionalProperties: false`, so sending
it would make the batch invalid.

See [../reporting.md](../reporting.md), [../conversions.md](../conversions.md),
[../errors.md](../errors.md) and [../tracking-payload.v1.schema.json](../tracking-payload.v1.schema.json).
