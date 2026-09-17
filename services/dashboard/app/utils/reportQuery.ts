import type { Compare, FilterDimension, FilterOp, Interval, Period, ReportFilter } from '~/types'
import { COMPARE_OPTIONS, DEFAULT_PERIOD, isIsoDay, isPeriod } from './datePresets'

export const FILTER_DIMENSIONS: FilterDimension[] = ['page', 'entry_page', 'exit_page', 'host', 'channel', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'device', 'browser', 'os', 'country', 'event', 'content', 'level']
export const FILTER_OPS: FilterOp[] = ['is', 'is_not', 'contains', 'prefix', 'glob']
export const REPORT_QUERY_KEYS = ['site', 'period', 'from', 'to', 'interval', 'compare'] as const

export interface ReportQueryState {
  site: number | null
  period: Period
  from?: string
  to?: string
  interval?: Interval
  compare: Compare
  filters: ReportFilter[]
}

type QueryValue = string | null | undefined | (string | null)[]
type QueryInput = Record<string, QueryValue>

const FILTER_KEY = /^filter\[([a-z_]+)\]\[([a-z_]+)\]$/

function first(value: QueryValue): string | undefined {
  if (Array.isArray(value)) return value[0] ?? undefined
  return value ?? undefined
}

export function parseReportQuery(query: QueryInput): ReportQueryState {
  const siteRaw = Number(first(query.site))
  const periodRaw = first(query.period)
  const from = first(query.from)
  const to = first(query.to)
  const intervalRaw = first(query.interval)
  const compareRaw = first(query.compare)

  let period: Period = isPeriod(periodRaw) ? periodRaw : DEFAULT_PERIOD
  if (period === 'custom' && !(isIsoDay(from) && isIsoDay(to) && from! <= to!)) {
    period = DEFAULT_PERIOD
  }

  const filters: ReportFilter[] = []
  for (const key of Object.keys(query).sort()) {
    const match = FILTER_KEY.exec(key)
    if (!match) continue
    const [, dim, op] = match
    const value = first(query[key])
    if (!value || !FILTER_DIMENSIONS.includes(dim as FilterDimension) || !FILTER_OPS.includes(op as FilterOp)) continue
    filters.push({ dim: dim as FilterDimension, op: op as FilterOp, value })
  }

  return {
    site: Number.isInteger(siteRaw) && siteRaw > 0 ? siteRaw : null,
    period,
    from: period === 'custom' ? from : undefined,
    to: period === 'custom' ? to : undefined,
    interval: (['hour', 'day', 'week', 'month'] as string[]).includes(intervalRaw ?? '') ? intervalRaw as Interval : undefined,
    compare: (COMPARE_OPTIONS as readonly string[]).includes(compareRaw ?? '') ? compareRaw as Compare : 'none',
    filters
  }
}

export function filterKey(filter: Pick<ReportFilter, 'dim' | 'op'>): string {
  return `filter[${filter.dim}][${filter.op}]`
}

/** URL query for a state; defaults are omitted to keep URLs short. */
export function serializeReportQuery(state: ReportQueryState): Record<string, string> {
  const query: Record<string, string> = {}
  if (state.site) query.site = String(state.site)
  if (state.period !== DEFAULT_PERIOD) query.period = state.period
  if (state.period === 'custom' && state.from && state.to) {
    query.from = state.from
    query.to = state.to
  }
  if (state.interval) query.interval = state.interval
  if (state.compare !== 'none') query.compare = state.compare
  for (const filter of state.filters) {
    query[filterKey(filter)] = filter.value
  }
  return query
}

/** Query parameters sent to report endpoints (site goes into the path). */
export function toApiParams(state: ReportQueryState, options: { interval?: boolean, compare?: boolean, filters?: boolean } = {}): Record<string, string> {
  const params: Record<string, string> = { period: state.period }
  if (state.period === 'custom' && state.from && state.to) {
    params.from = state.from
    params.to = state.to
  }
  if (options.interval && state.interval) params.interval = state.interval
  if (options.compare !== false && state.compare !== 'none') params.compare = state.compare
  if (options.filters !== false) {
    for (const filter of state.filters) params[filterKey(filter)] = filter.value
  }
  return params
}

/** Replaces the report keys of an existing route query, preserving unrelated keys. */
export function mergeReportQuery(current: QueryInput, state: ReportQueryState): Record<string, string> {
  const rest: Record<string, string> = {}
  for (const [key, value] of Object.entries(current)) {
    if ((REPORT_QUERY_KEYS as readonly string[]).includes(key) || FILTER_KEY.test(key)) continue
    const v = first(value)
    if (v !== undefined) rest[key] = v
  }
  return { ...rest, ...serializeReportQuery(state) }
}

export function stableStringify(value: Record<string, unknown>): string {
  return JSON.stringify(Object.keys(value).sort().map(k => [k, value[k]]))
}
