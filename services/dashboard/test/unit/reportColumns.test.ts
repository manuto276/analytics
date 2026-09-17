import { describe, expect, it } from 'vitest'
import { formatCell, formatChannels, nextSort, unavailableMetrics } from '~/utils/reportColumns'
import type { CellFormatters, ReportColumn } from '~/utils/reportColumns'
import { PAGE_COLUMNS } from '~/utils/reportDefinitions'

const fmt: CellFormatters = {
  number: v => `n:${v}`,
  percent: v => `p:${v}`,
  duration: v => `d:${v}`,
  money: (v, c) => `m:${v}:${c ?? 'site'}`,
  country: v => (v ? `c:${v}` : 'c:null')
}

describe('report columns', () => {
  it('formats cells by kind', () => {
    const row = { path: '/a', visitors: 3, bounce_rate: 0.5, avg_duration_ms: 10, revenue_minor: 99, currency: 'USD', country: 'IT', empty: '' }
    const col = (key: string, kind?: ReportColumn['kind']): ReportColumn => ({ key, label: key, kind })
    expect(formatCell(row, col('path', 'page'), fmt)).toBe('/a')
    expect(formatCell(row, col('visitors', 'number'), fmt)).toBe('n:3')
    expect(formatCell(row, col('bounce_rate', 'percent'), fmt)).toBe('p:0.5')
    expect(formatCell(row, col('avg_duration_ms', 'duration'), fmt)).toBe('d:10')
    expect(formatCell(row, col('revenue_minor', 'money'), fmt)).toBe('m:99:USD')
    expect(formatCell({ revenue_minor: 1 }, col('revenue_minor', 'money'), fmt)).toBe('m:1:site')
    expect(formatCell(row, col('country', 'country'), fmt)).toBe('c:IT')
    expect(formatCell(row, col('empty'), fmt)).toBe('—')
    expect(formatCell(row, col('missing', 'number'), fmt)).toBe('—')
    expect(formatCell(row, col('visitors', 'number'), fmt, false)).toBe('—')
  })

  it('renders a channel split, biggest first', () => {
    expect(formatChannels({ direct: 3, organic_search: 10, social: 5 }, fmt)).toBe('organic_search n:10 · social n:5 · direct n:3')
    expect(formatChannels({ a: 1, b: 2, c: 3, d: 4 }, fmt, 2)).toBe('d n:4 · c n:3')
    expect(formatChannels({}, fmt)).toBe('—')
    expect(formatChannels(null, fmt)).toBe('—')
  })

  it('formats a country cell as unknown when the API sends null', () => {
    expect(formatCell({ country: null }, { key: 'country', label: 'c', kind: 'country' }, fmt)).toBe('c:null')
  })

  it('cycles sort order', () => {
    expect(nextSort(undefined, 'visitors')).toBe('-visitors')
    expect(nextSort('-visitors', 'visitors')).toBe('visitors')
    expect(nextSort('visitors', 'visitors')).toBeUndefined()
    expect(nextSort('-pageviews', 'visitors')).toBe('-visitors')
  })

  it('lists unavailable metrics required by columns', () => {
    expect(unavailableMetrics(PAGE_COLUMNS.top, { visitors: false, bounce: true, duration: true })).toEqual(['visitors'])
    expect(unavailableMetrics(PAGE_COLUMNS.entry, { visitors: true, bounce: false, duration: false })).toEqual(['bounce'])
    expect(unavailableMetrics(PAGE_COLUMNS.top, { visitors: true, bounce: true, duration: true })).toEqual([])
  })
})
