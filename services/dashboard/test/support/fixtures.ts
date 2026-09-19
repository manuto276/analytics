import type { AuthConfig, Site, User } from '~/types'

export function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    email: 'admin@example.com',
    pending_email: null,
    display_name: 'Ada Admin',
    global_role: 'admin',
    locale: 'en',
    status: 'active',
    mfa_enabled: false,
    last_login_at: '2026-09-01T10:00:00Z',
    created_at: '2026-01-01T10:00:00Z',
    site_roles: [],
    ...overrides
  }
}

export function makeSite(overrides: Partial<Site> = {}): Site {
  return {
    id: 1,
    public_key: 'pk_abcdefghijklmnopqrstu',
    name: 'Example',
    timezone: 'Europe/Rome',
    currency: 'EUR',
    domains: [{ host: 'example.com', include_subdomains: true }],
    base_tracking_enabled: true,
    visitor_hash_mode: 'daily_hash',
    cookie_level_enabled: false,
    cookie_domain: null,
    visitor_cookie_days: 180,
    new_visit_on_campaign_change: true,
    dnt_mode: 'ignore',
    respect_gpc: true,
    hash_routing: false,
    allow_localhost: false,
    tracker_global: 'analytics',
    allowed_query_params: [],
    excluded_paths: [],
    excluded_ip_prefixes: [],
    content_contact_events: [],
    auto_events: { outbound: true, downloads: true, forms: false },
    min_group_size: 5,
    consent_receipts_enabled: false,
    archived: false,
    created_at: '2026-01-01T10:00:00Z',
    updated_at: '2026-01-01T10:00:00Z',
    role: 'admin',
    ...overrides
  }
}

export const authConfig: AuthConfig = {
  version: '1.0.0',
  commit: '0123456789abcdef',
  source_url: 'https://github.com/example/analytics',
  mailer_enabled: true,
  locales: ['en', 'it'],
  app_url: 'https://stats.example.net'
}

export const meta = {
  site_id: 1,
  range: { from: '2026-08-19', to: '2026-09-17' },
  compare_range: null,
  interval: 'day' as const,
  source: 'rollup' as const,
  availability: { visitors: true, bounce: true, duration: true },
  generated_at: '2026-09-17T10:00:00Z',
  cache: 'miss' as const,
  timezone: 'Europe/Rome',
  currency: 'EUR',
  next_cursor: null
}
