import { describe, expect, it } from 'vitest'
import { hasComparison, mapTimeseries, seriesTotal } from '~/utils/chart'
import type { TimeseriesPoint } from '~/types'

function point(t: string, visitors: number | null, visits = 0): TimeseriesPoint {
  return { t, visitors, visits, pageviews: visits * 2, bounce_rate: 0.5, avg_duration_ms: 1000, conversions: 1, revenue_minor: 100 }
}

describe('mapTimeseries', () => {
  it('aligns comparison buckets by position', () => {
    const data = mapTimeseries(
      [point('2026-09-01', 10, 12), point('2026-09-02', 20, 25)],
      [point('2026-08-01', 5, 6), point('2026-08-02', 7, 8)],
      'visits'
    )
    expect(data).toEqual([
      { index: 0, t: '2026-09-01', value: 12, compareT: '2026-08-01', compare: 6 },
      { index: 1, t: '2026-09-02', value: 25, compareT: '2026-08-02', compare: 8 }
    ])
    expect(seriesTotal(data, 'value')).toBe(37)
    expect(seriesTotal(data, 'compare')).toBe(14)
    expect(hasComparison(data)).toBe(true)
  })

  it('handles missing and shorter comparison series and null metrics', () => {
    const data = mapTimeseries([point('2026-09-01', null), point('2026-09-02', 3)], [point('2026-08-01', 4)], 'visitors')
    expect(data[0]).toMatchObject({ value: null, compare: 4 })
    expect(data[1]).toMatchObject({ value: 3, compare: null, compareT: null })
    expect(seriesTotal(data, 'value')).toBe(3)

    const none = mapTimeseries([point('2026-09-01', 1)], null, 'visitors')
    expect(hasComparison(none)).toBe(false)
  })
})
