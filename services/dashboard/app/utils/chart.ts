import type { TimeseriesPoint } from '~/types'

export type ChartMetric = 'visitors' | 'visits' | 'pageviews' | 'bounce_rate' | 'avg_duration_ms' | 'conversions' | 'revenue_minor'

export interface ChartDatum {
  index: number
  t: string
  value: number | null
  compareT: string | null
  compare: number | null
}

/**
 * Aligns the current series with the comparison series by position
 * (the n-th bucket of the previous period is drawn under the n-th current bucket).
 */
export function mapTimeseries(points: TimeseriesPoint[], comparePoints: TimeseriesPoint[] | null | undefined, metric: ChartMetric): ChartDatum[] {
  return points.map((point, index) => {
    const other = comparePoints?.[index]
    return {
      index,
      t: point.t,
      value: point[metric] ?? null,
      compareT: other?.t ?? null,
      compare: other ? (other[metric] ?? null) : null
    }
  })
}

export function seriesTotal(data: ChartDatum[], key: 'value' | 'compare'): number {
  return data.reduce((sum, d) => sum + (d[key] ?? 0), 0)
}

export function hasComparison(data: ChartDatum[]): boolean {
  return data.some(d => d.compare !== null)
}
