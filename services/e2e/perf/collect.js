/* eslint-disable */
/**
 * k6 load baseline (plan §13.2 "Load (nightly, informative)").
 *
 * Two scenarios run against the compose test stack:
 *
 *   collect  ramping arrival rate up to COLLECT_RPS (default 100) requests per
 *            second of `POST /t/e`, with realistic batches: 1–4 events mixing
 *            pageviews, engagement and custom events, a share of consented
 *            (cookie-level) batches with visitor and session ids, varied user
 *            agents, paths, referrers and campaigns.
 *   reports  a steady REPORTS_RPS (default 5) requests per second spread over
 *            the overview, pages and sources reports of the seeded site, with
 *            the session cookie of a real sign-in.
 *
 * Thresholds come from the plan and are informative: collect p95 < 50 ms,
 * reports p95 < 500 ms. They are reported, and the nightly workflow does not
 * fail the build on them.
 *
 * Run it through `make perf` (which seeds the data first). Environment:
 *   BASE_URL                default http://analytics.test (plain HTTP on the
 *                           compose network: TLS handshakes would measure the
 *                           proxy rather than the application)
 *   ANALYTICS_PUBLIC_KEY    pk_… of the seeded site (required)
 *   ANALYTICS_SITE_ID       numeric id of the same site (required)
 *   ADMIN_EMAIL/PASSWORD    dashboard credentials for the report scenario
 *   COLLECT_RPS, REPORTS_RPS, DURATION, RAMP
 */
import http from 'k6/http'
import encoding from 'k6/encoding'
import crypto from 'k6/crypto'
import { check, fail } from 'k6'
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.4/index.js'

const BASE_URL = __ENV.BASE_URL || 'http://analytics.test'
const PUBLIC_KEY = __ENV.ANALYTICS_PUBLIC_KEY || ''
const SITE_ID = __ENV.ANALYTICS_SITE_ID || ''
const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@analytics.test'
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'Fixture-Passw0rd-2026'
const COLLECT_RPS = Number(__ENV.COLLECT_RPS || 100)
const REPORTS_RPS = Number(__ENV.REPORTS_RPS || 5)
const DURATION = __ENV.DURATION || '60s'
const RAMP = __ENV.RAMP || '20s'
const CONSENTED_SHARE = Number(__ENV.CONSENTED_SHARE || 0.25)

export const options = {
  discardResponseBodies: false,
  scenarios: {
    collect: {
      executor: 'ramping-arrival-rate',
      exec: 'collect',
      startRate: 10,
      timeUnit: '1s',
      preAllocatedVUs: 40,
      maxVUs: 200,
      stages: [
        { target: COLLECT_RPS, duration: RAMP },
        { target: COLLECT_RPS, duration: DURATION },
      ],
      tags: { scenario: 'collect' },
    },
    reports: {
      executor: 'constant-arrival-rate',
      exec: 'reports',
      rate: REPORTS_RPS,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 10,
      maxVUs: 40,
      startTime: RAMP,
      tags: { scenario: 'reports' },
    },
  },
  thresholds: {
    // Plan §13.2: collect p95 < 50 ms at 100 rps, reports p95 < 500 ms.
    'http_req_duration{scenario:collect}': ['p(95)<50'],
    'http_req_duration{scenario:reports}': ['p(95)<500'],
    'http_req_failed{scenario:collect}': ['rate<0.01'],
    'http_req_failed{scenario:reports}': ['rate<0.01'],
  },
}

const HOSTS = ['www.site.test', 'app.site.test']
const PATHS = ['/', '/pricing', '/features', '/blog/analytics-without-cookies', '/docs/install', '/contact']
const REFERRERS = [
  '',
  'https://www.google.com/',
  'https://duckduckgo.com/',
  'https://news.ycombinator.com/',
  'https://t.co/abc123',
]
const CAMPAIGNS = [
  '',
  '?utm_source=newsletter&utm_medium=email&utm_campaign=autumn',
  '?utm_source=google&utm_medium=cpc&utm_campaign=brand&gclid=xyz',
  '?utm_source=partner&utm_medium=referral&utm_campaign=launch',
]
const USER_AGENTS = [
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/536.36',
]
const EVENT_NAMES = ['signup_click', 'demo_request', 'pricing_toggle', 'docs_search']
// Real browsers always send Accept-Language; a request without one is treated as
// a bot (accepted with 202 and dropped), which would measure the bot filter
// instead of the ingest path.
const LANGUAGES = ['en-US,en;q=0.9', 'it-IT,it;q=0.9,en;q=0.8', 'de-DE,de;q=0.9', 'fr-FR,fr;q=0.9,en;q=0.7']
const REPORTS = ['overview', 'pages', 'sources']

const pick = array => array[Math.floor(Math.random() * array.length)]
const octet = () => Math.floor(Math.random() * 254) + 1
const clientIp = () => '10.' + octet() + '.' + octet() + '.' + octet()
const id = bytes => encoding.b64encode(crypto.randomBytes(bytes), 'rawurl')

function randomBatch() {
  const host = pick(HOSTS)
  const consented = Math.random() < CONSENTED_SHARE
  const events = []
  const pages = 1 + Math.floor(Math.random() * 3)

  for (let page = 0; page < pages; page++) {
    const url = 'https://' + host + pick(PATHS) + (page === 0 ? pick(CAMPAIGNS) : '')
    const pageview = { id: id(12), t: 'pv', u: url, a: Math.floor(Math.random() * 2000) }
    if (page === 0) {
      const referrer = pick(REFERRERS)
      if (referrer) pageview.r = referrer
    }
    events.push(pageview)

    if (Math.random() < 0.7) {
      events.push({
        id: id(12),
        t: 'en',
        u: url,
        a: 500,
        ms: 3000 + Math.floor(Math.random() * 90000),
        sp: 10 + Math.floor(Math.random() * 90),
      })
    }
    if (Math.random() < 0.15) {
      events.push({ id: id(12), t: 'ev', u: url, a: 200, n: pick(EVENT_NAMES), p: { plan: Math.random() < 0.5 ? 'pro' : 'free' } })
    }
  }

  const batch = {
    v: 1,
    k: PUBLIC_KEY,
    l: consented ? 'c' : 'b',
    cv: consented ? 1 : 0,
    sw: pick([1280, 1440, 1920, 390, 412]),
    e: events,
  }
  if (consented) {
    batch.vid = id(16)
    batch.sid = id(16)
  }
  return { batch, host }
}

export function setup() {
  if (!PUBLIC_KEY || !SITE_ID) {
    fail('ANALYTICS_PUBLIC_KEY and ANALYTICS_SITE_ID are required (run `make perf`)')
  }

  const login = http.post(
    BASE_URL + '/api/v1/auth/login',
    JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
    { headers: { 'Content-Type': 'application/json', Origin: 'https://analytics.test' } },
  )
  if (login.status !== 200) {
    fail('cannot sign in for the report scenario: ' + login.status + ' ' + login.body)
  }

  // The session cookie is `__Host-an_session` (or `an_session` without TLS);
  // k6's jar is per VU, so the value is passed to the scenarios explicitly.
  const cookies = login.cookies
  const name = Object.keys(cookies).find(key => key.indexOf('an_session') !== -1)
  const session = name ? name + '=' + cookies[name][0].value : ''

  // Warm-up: OPcache, the JIT, the compiled container and the caches of the
  // first worker processes. Without it the p95 of a short run describes the
  // first request of a cold PHP-FPM pool rather than the steady state.
  const warmup = Number(__ENV.WARMUP_REQUESTS || 60)
  for (let i = 0; i < warmup; i++) {
    const { batch, host } = randomBatch()
    http.post(BASE_URL + '/t/e', JSON.stringify(batch), {
      headers: {
        'Content-Type': 'text/plain',
        Origin: 'https://' + host,
        'User-Agent': pick(USER_AGENTS),
        'Accept-Language': pick(LANGUAGES),
        'X-Forwarded-For': clientIp(),
      },
      tags: { scenario: 'warmup' },
    })
    if (i % 10 === 0) {
      http.get(BASE_URL + '/api/v1/sites/' + SITE_ID + '/reports/' + pick(REPORTS) + '?period=30d', {
        headers: { Cookie: session, Accept: 'application/json' },
        tags: { scenario: 'warmup' },
      })
    }
  }

  return { session }
}

export function collect() {
  const { batch, host } = randomBatch()
  const response = http.post(BASE_URL + '/t/e', JSON.stringify(batch), {
    headers: {
      'Content-Type': 'text/plain',
      Origin: 'https://' + host,
      'User-Agent': pick(USER_AGENTS),
      'Accept-Language': pick(LANGUAGES),
      // Spread over many /24 networks: the collect limiter allows 300 requests
      // per minute per (site, shortened IP), so a single prefix would measure
      // the rate limiter instead of the ingest path.
      'X-Forwarded-For': clientIp(),
    },
    tags: { endpoint: 'collect' },
  })
  check(response, { 'collect accepted (202)': r => r.status === 202 })
}

export function reports(data) {
  const report = pick(REPORTS)
  const response = http.get(
    BASE_URL + '/api/v1/sites/' + SITE_ID + '/reports/' + report + '?period=30d',
    {
      headers: { Cookie: data.session, Accept: 'application/json' },
      tags: { endpoint: 'report', report: report },
    },
  )
  check(response, { 'report ok (200)': r => r.status === 200 })
}

export function handleSummary(data) {
  const line = (name, metric) => {
    if (!metric || !metric.values) return name + ': no data\n'
    const v = metric.values
    return (
      name +
      ': count=' + (v.count !== undefined ? v.count : '-') +
      ' avg=' + (v.avg !== undefined ? v.avg.toFixed(1) + 'ms' : '-') +
      ' p95=' + (v['p(95)'] !== undefined ? v['p(95)'].toFixed(1) + 'ms' : '-') +
      ' max=' + (v.max !== undefined ? v.max.toFixed(1) + 'ms' : '-') +
      '\n'
    )
  }

  const summary =
    '\n=== analytics load baseline ===\n' +
    'collect target: ' + COLLECT_RPS + ' rps for ' + DURATION + ' (ramp ' + RAMP + ')\n' +
    'reports target: ' + REPORTS_RPS + ' rps\n' +
    line('collect (p95 budget 50ms) ', data.metrics['http_req_duration{scenario:collect}']) +
    line('reports (p95 budget 500ms)', data.metrics['http_req_duration{scenario:reports}']) +
    '\n'

  return {
    stdout: textSummary(data, { indent: ' ', enableColors: false }) + summary,
    '/perf/summary.json': JSON.stringify(data),
  }
}
