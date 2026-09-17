import { describe, expect, it } from 'vitest'
import { daysBetween, defaultInterval, intervalsFor, intervalsForDays, isIsoDay, isPeriod, PERIOD_PRESETS, presetRange, todayIn } from '~/utils/datePresets'

describe('date presets', () => {
  const today = '2026-03-15'

  it.each([
    ['today', '2026-03-15', '2026-03-15'],
    ['yesterday', '2026-03-14', '2026-03-14'],
    ['7d', '2026-03-09', '2026-03-15'],
    ['30d', '2026-02-14', '2026-03-15'],
    ['90d', '2025-12-16', '2026-03-15'],
    ['month', '2026-03-01', '2026-03-15'],
    ['last_month', '2026-02-01', '2026-02-28'],
    ['12mo', '2025-04-01', '2026-03-15'],
    ['year', '2026-01-01', '2026-03-15']
  ] as const)('%s → %s…%s', (period, from, to) => {
    expect(presetRange(period, today)).toEqual({ from, to })
  })

  it('handles last month across a year boundary', () => {
    expect(presetRange('last_month', '2026-01-10')).toEqual({ from: '2025-12-01', to: '2025-12-31' })
  })

  it('lists all presets and validates periods and days', () => {
    expect(PERIOD_PRESETS).toHaveLength(9)
    expect(isPeriod('custom')).toBe(true)
    expect(isPeriod('7d')).toBe(true)
    expect(isPeriod('5d')).toBe(false)
    expect(isIsoDay('2026-02-29')).toBe(false)
    expect(isIsoDay('2028-02-29')).toBe(true)
    expect(isIsoDay('2026-1-1')).toBe(false)
    expect(isIsoDay(42)).toBe(false)
  })

  it('computes intervals for ranges', () => {
    expect(daysBetween('2026-03-01', '2026-03-01')).toBe(1)
    expect(intervalsForDays(1)).toEqual(['hour', 'day'])
    expect(intervalsForDays(30)).toEqual(['day', 'week'])
    expect(intervalsForDays(90)).toEqual(['day', 'week', 'month'])
    expect(intervalsForDays(365)).toEqual(['week', 'month'])
    expect(intervalsFor('custom', '2026-01-01', '2026-01-02')).toEqual(['hour', 'day'])
    expect(intervalsFor('custom')).toEqual(['day'])
    expect(intervalsFor('7d', undefined, undefined, today)).toEqual(['day', 'week'])
    expect(defaultInterval('today')).toBe('hour')
    expect(defaultInterval('year')).toBe('month')
    expect(defaultInterval('30d', undefined, undefined, today)).toBe('day')
  })

  it('computes today in a time zone', () => {
    const now = new Date('2026-09-17T23:30:00Z')
    expect(todayIn('Europe/Rome', now)).toBe('2026-09-18')
    expect(todayIn('America/New_York', now)).toBe('2026-09-17')
    expect(todayIn('Not/AZone', now)).toBe('2026-09-17')
  })
})
