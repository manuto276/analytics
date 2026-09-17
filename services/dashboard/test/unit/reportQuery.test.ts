import { describe, expect, it } from 'vitest'
import { mergeReportQuery, parseReportQuery, serializeReportQuery, stableStringify, toApiParams } from '~/utils/reportQuery'
import type { ReportQueryState } from '~/utils/reportQuery'

describe('report query', () => {
  const state: ReportQueryState = {
    site: 3,
    period: 'custom',
    from: '2026-01-01',
    to: '2026-01-31',
    interval: 'week',
    compare: 'previous_year',
    filters: [
      { dim: 'channel', op: 'is', value: 'search' },
      { dim: 'page', op: 'prefix', value: '/blog/' }
    ]
  }

  it('round-trips through the URL query', () => {
    const query = serializeReportQuery(state)
    expect(query).toEqual({
      'site': '3',
      'period': 'custom',
      'from': '2026-01-01',
      'to': '2026-01-31',
      'interval': 'week',
      'compare': 'previous_year',
      'filter[channel][is]': 'search',
      'filter[page][prefix]': '/blog/'
    })
    expect(parseReportQuery(query)).toEqual(state)
  })

  it('applies defaults and drops invalid values', () => {
    expect(parseReportQuery({})).toEqual({ site: null, period: '30d', from: undefined, to: undefined, interval: undefined, compare: 'none', filters: [] })
    const parsed = parseReportQuery({
      'site': 'x',
      'period': 'custom',
      'from': '2026-02-10',
      'to': '2026-02-01',
      'interval': 'minute',
      'compare': 'yesterday',
      'filter[nope][is]': 'a',
      'filter[page][equals]': 'b',
      'filter[page][is]': '',
      'filter[country][is_not]': ['IT', 'DE']
    })
    expect(parsed.period).toBe('30d')
    expect(parsed.from).toBeUndefined()
    expect(parsed.interval).toBeUndefined()
    expect(parsed.compare).toBe('none')
    expect(parsed.filters).toEqual([{ dim: 'country', op: 'is_not', value: 'IT' }])
    expect(serializeReportQuery(parsed)).toEqual({ 'filter[country][is_not]': 'IT' })
  })

  it('builds API params', () => {
    expect(toApiParams(state)).toEqual({
      'period': 'custom',
      'from': '2026-01-01',
      'to': '2026-01-31',
      'compare': 'previous_year',
      'filter[channel][is]': 'search',
      'filter[page][prefix]': '/blog/'
    })
    expect(toApiParams(state, { interval: true, compare: false, filters: false })).toEqual({
      period: 'custom',
      from: '2026-01-01',
      to: '2026-01-31',
      interval: 'week'
    })
  })

  it('merges into an existing query keeping unrelated keys', () => {
    const merged = mergeReportQuery({ 'tab': 'x', 'site': '1', 'filter[page][is]': '/old', 'empty': null }, { ...state, filters: [] })
    expect(merged).toEqual({ tab: 'x', site: '3', period: 'custom', from: '2026-01-01', to: '2026-01-31', interval: 'week', compare: 'previous_year' })
  })

  it('stringifies params independent of key order', () => {
    expect(stableStringify({ b: 1, a: 2 })).toBe(stableStringify({ a: 2, b: 1 }))
  })
})
