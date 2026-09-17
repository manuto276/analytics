import type { CampaignsRow, ContentRow, ConversionsRow, CountriesRow, EventPropsRow, EventsRow, GoalsRow, LandingPageRow, PagesRow, SourcesRow, TechRow } from '~/types'
import type { ColumnsOf } from './reportColumns'

// Column keys are typed against the row schemas generated from docs/api/openapi.yaml.

const visitors = { label: 'metrics.visitors', kind: 'number', sortable: true, requires: 'visitors' } as const
const visits = { label: 'metrics.visits', kind: 'number', sortable: true } as const
const pageviews = { label: 'metrics.pageviews', kind: 'number', sortable: true } as const
const bounce = { label: 'metrics.bounce_rate', kind: 'percent', sortable: true, requires: 'bounce' } as const

export const PAGE_COLUMNS: Record<'top' | 'entry' | 'exit', ColumnsOf<PagesRow>> = {
  top: [
    { key: 'path', label: 'columns.page', kind: 'page', filter: 'page' },
    { key: 'visitors', ...visitors },
    { key: 'pageviews', ...pageviews },
    { key: 'visits', ...visits },
    { key: 'avg_engagement_ms', label: 'metrics.avg_engagement_ms', kind: 'duration', sortable: true, requires: 'duration' }
  ],
  entry: [
    { key: 'path', label: 'columns.entryPage', kind: 'page', filter: 'entry_page' },
    { key: 'entries', label: 'metrics.entries', kind: 'number', sortable: true },
    { key: 'visitors', ...visitors },
    { key: 'bounce_rate', ...bounce }
  ],
  exit: [
    { key: 'path', label: 'columns.exitPage', kind: 'page', filter: 'exit_page' },
    { key: 'exits', label: 'metrics.exits', kind: 'number', sortable: true },
    { key: 'pageviews', ...pageviews },
    { key: 'visitors', ...visitors }
  ]
}

export const LANDING_COLUMNS: ColumnsOf<LandingPageRow> = [
  { key: 'path', label: 'columns.landingPage', kind: 'page', filter: 'entry_page' },
  { key: 'entries', label: 'metrics.entries', kind: 'number', sortable: true },
  { key: 'bounces', label: 'metrics.bounces', kind: 'number', sortable: true, requires: 'bounce' },
  { key: 'bounce_rate', ...bounce }
]

export const SOURCE_COLUMNS: Record<'channel' | 'source' | 'referrer', ColumnsOf<SourcesRow>> = {
  channel: [
    { key: 'channel', label: 'columns.channel', filter: 'channel' },
    { key: 'visitors', ...visitors },
    { key: 'visits', ...visits },
    { key: 'bounce_rate', ...bounce },
    { key: 'avg_duration_ms', label: 'metrics.avg_duration_ms', kind: 'duration', sortable: true, requires: 'duration' }
  ],
  source: [
    { key: 'source', label: 'columns.source', filter: 'source' },
    { key: 'channel', label: 'columns.channel', filter: 'channel' },
    { key: 'visitors', ...visitors },
    { key: 'visits', ...visits },
    { key: 'bounce_rate', ...bounce }
  ],
  referrer: [
    { key: 'referrer_host', label: 'columns.referrer', filter: 'referrer' },
    { key: 'channel', label: 'columns.channel', filter: 'channel' },
    { key: 'visitors', ...visitors },
    { key: 'visits', ...visits },
    { key: 'pageviews', ...pageviews }
  ]
}

export const CAMPAIGN_COLUMNS: ColumnsOf<CampaignsRow> = [
  { key: 'utm_campaign', label: 'columns.utm_campaign', filter: 'utm_campaign' },
  { key: 'utm_source', label: 'columns.utm_source', filter: 'utm_source' },
  { key: 'utm_medium', label: 'columns.utm_medium', filter: 'utm_medium' },
  { key: 'utm_content', label: 'columns.utm_content', filter: 'utm_content' },
  { key: 'visitors', ...visitors },
  { key: 'visits', ...visits },
  { key: 'bounce_rate', ...bounce }
]

const techColumns = (): ColumnsOf<TechRow> => [
  { key: 'visitors', ...visitors },
  { key: 'visits', ...visits },
  { key: 'pageviews', ...pageviews }
]

export const TECH_COLUMNS: Record<'device' | 'browser' | 'os', ColumnsOf<TechRow>> = {
  device: [{ key: 'value', label: 'columns.device', filter: 'device' }, ...techColumns()],
  browser: [{ key: 'value', label: 'columns.browser', filter: 'browser' }, ...techColumns()],
  os: [{ key: 'value', label: 'columns.os', filter: 'os' }, ...techColumns()]
}

export const COUNTRY_COLUMNS: ColumnsOf<CountriesRow> = [
  { key: 'country', label: 'columns.country', kind: 'country', filter: 'country' },
  { key: 'visitors', ...visitors },
  { key: 'visits', ...visits },
  { key: 'pageviews', ...pageviews }
]

export const EVENT_COLUMNS: ColumnsOf<EventsRow> = [
  { key: 'name', label: 'columns.event', filter: 'event' },
  { key: 'occurrences', label: 'metrics.occurrences', kind: 'number', sortable: true },
  { key: 'visits', ...visits }
]

export const EVENT_PROP_COLUMNS: ColumnsOf<EventPropsRow> = [
  { key: 'prop_key', label: 'columns.propKey' },
  { key: 'prop_value', label: 'columns.propValue' },
  { key: 'occurrences', label: 'metrics.occurrences', kind: 'number', sortable: true },
  { key: 'visits', ...visits }
]

export const CONTENT_COLUMNS: ColumnsOf<ContentRow> = [
  { key: 'content_key', label: 'columns.contentKey', filter: 'content' },
  { key: 'pageviews', ...pageviews },
  { key: 'visitors', ...visitors },
  { key: 'visits', ...visits },
  { key: 'contacts', label: 'metrics.contacts', kind: 'number', sortable: true },
  { key: 'channels', label: 'columns.channels', kind: 'channels' }
]

export const GOAL_COLUMNS: ColumnsOf<GoalsRow> = [
  { key: 'name', label: 'columns.goal' },
  { key: 'type', label: 'settings.goals.type' },
  { key: 'conversions', label: 'metrics.conversions', kind: 'number', sortable: true },
  { key: 'conversion_rate', label: 'metrics.conversion_rate', kind: 'percent', sortable: true },
  { key: 'value_minor', label: 'metrics.value_minor', kind: 'money', sortable: true }
]

export const CONVERSION_COLUMNS: ColumnsOf<ConversionsRow> = [
  { key: 'name', label: 'columns.conversion' },
  { key: 'count', label: 'metrics.count', kind: 'number', sortable: true },
  { key: 'value_minor', label: 'metrics.value_minor', kind: 'money', sortable: true },
  { key: 'attributed', label: 'metrics.attributed', kind: 'number', sortable: true }
]

export const TOP_PAGES_COLUMNS: ColumnsOf<PagesRow> = [
  { key: 'path', label: 'columns.page', kind: 'page', filter: 'page' },
  { key: 'visitors', ...visitors },
  { key: 'pageviews', ...pageviews }
]

export const TOP_SOURCES_COLUMNS: ColumnsOf<SourcesRow> = [
  { key: 'channel', label: 'columns.channel', filter: 'channel' },
  { key: 'visitors', ...visitors },
  { key: 'visits', ...visits }
]
