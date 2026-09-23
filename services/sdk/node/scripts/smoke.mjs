// Runs the built ES module against a local HTTP server with the global fetch of whatever Node runs
// it (the nightly job runs it on every supported Node version, down to 18). No test framework.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { AnalyticsApiError, createClient, visitorIdFromCookie } from '../dist/esm/index.js';

const PK = 'pk_ABCDEFGHIJKLMNOPQRSTU';
const seen = [];
const server = createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    seen.push({ method: req.method, url: req.url, auth: req.headers.authorization, body });
    if (req.url.endsWith('/conversions') && seen.length == 1) {
      res.writeHead(503, { 'Content-Type': 'application/problem+json', 'Retry-After': '0' });
      return res.end(JSON.stringify({ type: 'about:blank', title: 'Unavailable', status: 503, code: 'unavailable' }));
    }
    if (req.url.endsWith('/conversions')) {
      res.writeHead(202, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ accepted: 1, duplicates: 0, rejected: [] }));
    }
    res.writeHead(403, { 'Content-Type': 'application/problem+json' });
    res.end(JSON.stringify({ type: 'about:blank', title: 'Forbidden', status: 403, code: 'insufficient_scope' }));
  });
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const { port } = server.address();

try {
  const client = createClient({ serviceUrl: `http://127.0.0.1:${port}`, apiKey: 'ak_smoke_secret', publicKey: PK });
  const result = await client.conversions.send({ name: 'purchase', visitor_id: visitorIdFromCookie('an_vid=AbCdEfGhIjKlMnOpQrStUv') });
  assert.deepEqual(result, { accepted: 1, duplicates: 0, rejected: [] });
  assert.equal(seen.length, 2, 'the 503 was retried');
  assert.equal(seen[1].auth, 'Bearer ak_smoke_secret');
  const sent = JSON.parse(seen[1].body);
  assert.match(sent.id, /^[0-9a-f-]{36}$/);
  assert.equal(sent.id, JSON.parse(seen[0].body).id);
  assert.equal(sent.visitor_id, 'AbCdEfGhIjKlMnOpQrStUv');
  await assert.rejects(client.reports.overview({ period: '7d' }), (e) => e instanceof AnalyticsApiError && e.code == 'insufficient_scope');
  console.log(`ESM smoke passed on Node ${process.versions.node}`);
} finally {
  server.close();
}
