// The CommonJS build loads with require() and exposes the same API (see smoke.mjs for the real calls).
const assert = require('node:assert/strict');
const sdk = require('../dist/cjs/index.js');

assert.equal(typeof sdk.createClient, 'function');
assert.equal(sdk.visitorIdFromCookie('an_vid=AbCdEfGhIjKlMnOpQrStUv'), 'AbCdEfGhIjKlMnOpQrStUv');
assert.ok(new sdk.AnalyticsApiError({ status: 429, code: 'rate_limited', title: 'x' }).retryable);
assert.equal(sdk.REPORTS.length, 11);
sdk
  .normalizeConversion({ name: 'lead', value: { amount_minor: 100, currency: 'eur' } })
  .then((c) => {
    assert.equal(c.value.currency, 'EUR');
    console.log(`CJS smoke passed on Node ${process.versions.node}`);
  })
  .catch((e) => {
    console.error(e);
    process.exit(1);
  });
