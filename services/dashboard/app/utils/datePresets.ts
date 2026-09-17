import type { Interval, Period } from '~/types'

export const PERIOD_PRESETS: Exclude<Period, 'custom'>[] = ['today', 'yesterday', '7d', '30d', '90d', 'month', 'last_month', '12mo', 'year']
export const COMPARE_OPTIONS = ['none', 'previous_period', 'previous_year'] as const
export const DEFAULT_PERIOD: Period = '30d'

export function isPeriod(value: unknown): value is Period {
  return typeof value === 'string' && ([...PERIOD_PRESETS, 'custom'] as string[]).includes(value)
}

export function isIsoDay(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const d = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(d.getTime()) && d.toISOString().startsWith(value)
}

function toIsoDay(date: Date): string {
  return date.toISOString().slice(0, 10)
}

function addDays(day: string, days: number): string {
  const d = new Date(`${day}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return toIsoDay(d)
}

/** Today in an IANA time zone as YYYY-MM-DD. */
export function todayIn(timeZone?: string, now: Date = new Date()): string {
  try {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
    return parts
  } catch {
    return toIsoDay(now)
  }
}

/** Inclusive day range of a preset, relative to `today` (site-local day). Mirrors the backend definitions. */
export function presetRange(period: Exclude<Period, 'custom'>, today: string): { from: string, to: string } {
  const [y, m] = today.split('-').map(Number) as [number, number]
  switch (period) {
    case 'today':
      return { from: today, to: today }
    case 'yesterday': {
      const day = addDays(today, -1)
      return { from: day, to: day }
    }
    case '7d':
      return { from: addDays(today, -6), to: today }
    case '30d':
      return { from: addDays(today, -29), to: today }
    case '90d':
      return { from: addDays(today, -89), to: today }
    case 'month':
      return { from: `${today.slice(0, 7)}-01`, to: today }
    case 'last_month': {
      const first = new Date(Date.UTC(y, m - 2, 1))
      const last = new Date(Date.UTC(y, m - 1, 0))
      return { from: toIsoDay(first), to: toIsoDay(last) }
    }
    case '12mo': {
      const start = new Date(Date.UTC(y, m - 1 - 11, 1))
      return { from: toIsoDay(start), to: today }
    }
    case 'year':
      return { from: `${y}-01-01`, to: today }
  }
}

export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) + 1
}

/** Intervals that make sense for a range length. */
export function intervalsForDays(days: number): Interval[] {
  if (days <= 2) return ['hour', 'day']
  if (days <= 31) return ['day', 'week']
  if (days <= 92) return ['day', 'week', 'month']
  return ['week', 'month']
}

export function intervalsFor(period: Period, from?: string, to?: string, today = todayIn()): Interval[] {
  if (period === 'custom') {
    return from && to ? intervalsForDays(daysBetween(from, to)) : ['day']
  }
  const range = presetRange(period, today)
  return intervalsForDays(daysBetween(range.from, range.to))
}

export function defaultInterval(period: Period, from?: string, to?: string, today = todayIn()): Interval {
  if (period === 'today' || period === 'yesterday') return 'hour'
  if (period === '12mo' || period === 'year') return 'month'
  return intervalsFor(period, from, to, today)[0]!
}
