import type {
  CampaignsRow,
  ContentRow,
  ConversionsRow,
  CountriesRow,
  EventPropsRow,
  EventsRow,
  GoalsRow,
  LandingPageRow,
  PagesRow,
  SourcesRow,
  TechRow
} from '~/types'

// One row per report, shaped exactly like the schemas in docs/api/openapi.yaml.
// Typing them here fails the build if the contract gains or renames a field.

export const pagesRow: PagesRow = {
  host: 'example.com',
  path: '/pricing',
  pageviews: 420,
  visits: 300,
  visitors: 250,
  entries: 120,
  exits: 90,
  bounce_rate: 0.33,
  avg_engagement_ms: 45_000
}

export const landingPageRow: LandingPageRow = {
  host: 'example.com',
  path: '/blog/hello',
  entries: 200,
  bounces: 60,
  bounce_rate: 0.3
}

export const sourcesRow: SourcesRow = {
  channel: 'organic_search',
  source: 'google',
  referrer_host: 'www.google.com',
  visits: 500,
  visitors: 430,
  pageviews: 900,
  bounce_rate: 0.41,
  avg_duration_ms: 65_000
}

export const campaignsRow: CampaignsRow = {
  utm_source: 'newsletter',
  utm_medium: 'email',
  utm_campaign: 'spring',
  utm_content: 'header-link',
  utm_term: 'analytics',
  visits: 140,
  visitors: 120,
  pageviews: 260,
  bounce_rate: 0.25
}

export const techRow: TechRow = { value: 'desktop', visits: 320, visitors: 280, pageviews: 700 }

export const countriesRow: CountriesRow = { country: 'IT', visits: 210, visitors: 190, pageviews: 400 }

export const eventsRow: EventsRow = { name: 'signup', occurrences: 55, visits: 50 }

export const eventPropsRow: EventPropsRow = { prop_key: 'plan', prop_value: 'pro', occurrences: 22, visits: 20 }

export const contentRow: ContentRow = {
  content_key: 'guide/analytics',
  pageviews: 800,
  visits: 600,
  visitors: 540,
  contacts: 12,
  channels: { organic_search: 300, direct: 200, social: 100 }
}

export const goalsRow: GoalsRow = {
  goal_id: 3,
  name: 'Signup',
  type: 'event',
  conversions: 45,
  conversion_rate: 0.09,
  value_minor: 12_000
}

export const conversionsRow: ConversionsRow = { name: 'purchase', count: 18, value_minor: 250_000, attributed: 14 }
