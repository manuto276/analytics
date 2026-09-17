import type { FilterDimension, ReportRow } from '~/types'
import { EMPTY_VALUE } from './format'

export type ColumnKind = 'text' | 'number' | 'percent' | 'duration' | 'money' | 'country' | 'page' | 'channels'
export type AvailabilityKey = 'visitors' | 'bounce' | 'duration'

/** A column of a table report. */
export interface ReportColumn {
  /** Row property returned by the API. */
  key: string
  /** i18n key of the column header. */
  label: string
  kind?: ColumnKind
  /** Clicking the value adds `filter[dim][is]=value`. */
  filter?: FilterDimension
  sortable?: boolean
  requires?: AvailabilityKey
}

/**
 * Column list bound to a documented row schema, so a column key cannot drift
 * away from `docs/api/openapi.yaml` without a type error.
 */
export type ColumnsOf<Row> = Array<ReportColumn & { key: Extract<keyof Row, string> }>

export interface CellFormatters {
  number: (v: number | null | undefined) => string
  percent: (v: number | null | undefined) => string
  duration: (v: number | null | undefined) => string
  money: (v: number | null | undefined, currency?: string | null) => string
  country: (v: string | null | undefined) => string
}

/** Renders a channel → visits map as "search 12 · social 3" (top entries first). */
export function formatChannels(value: unknown, fmt: CellFormatters, limit = 3): string {
  if (!value || typeof value !== 'object') return EMPTY_VALUE
  const entries = Object.entries(value as Record<string, number>)
    .sort(([, a], [, b]) => b - a)
    .slice(0, limit)
  if (!entries.length) return EMPTY_VALUE
  return entries.map(([channel, visits]) => `${channel} ${fmt.number(visits)}`).join(' · ')
}

export function formatCell(row: ReportRow, column: ReportColumn, fmt: CellFormatters, available = true): string {
  const value = row[column.key] as unknown
  if (!available) return EMPTY_VALUE
  if (column.kind === 'channels') return formatChannels(value, fmt)
  if (column.kind === 'country') return fmt.country(value as string | null)
  if (value === null || value === undefined || value === '') return EMPTY_VALUE
  switch (column.kind) {
    case 'number':
      return fmt.number(Number(value))
    case 'percent':
      return fmt.percent(Number(value))
    case 'duration':
      return fmt.duration(Number(value))
    case 'money':
      return fmt.money(Number(value), typeof row.currency === 'string' ? row.currency : undefined)
    default:
      return String(value)
  }
}

/** Cycles sort: none → descending → ascending → none. */
export function nextSort(current: string | undefined, key: string): string | undefined {
  if (current === `-${key}`) return key
  if (current === key) return undefined
  return `-${key}`
}

export function unavailableMetrics(columns: ReportColumn[], availability: Record<AvailabilityKey, boolean>): AvailabilityKey[] {
  const keys = new Set<AvailabilityKey>()
  for (const column of columns) {
    if (column.requires && !availability[column.requires]) keys.add(column.requires)
  }
  return [...keys]
}
