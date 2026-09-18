import { describe, expect, it } from 'vitest'
import {
  countryName,
  formatClock,
  currencyDigits,
  EMPTY_VALUE,
  formatCompact,
  formatDateTime,
  formatDay,
  formatDelta,
  formatDuration,
  formatMoney,
  formatNumber,
  formatPercent,
  fromMinorUnits,
  timeZoneList,
  toMinorUnits
} from '~/utils/format'

describe('formatDelta', () => {
  it('formats positive growth as success with a plus sign', () => {
    expect(formatDelta(0.125, 'en')).toEqual({ text: '+12.5%', tone: 'success', icon: 'i-tabler-trending-up' })
  })

  it('formats decreases as error with a minus sign', () => {
    expect(formatDelta(-0.3, 'en')).toEqual({ text: '-30%', tone: 'error', icon: 'i-tabler-trending-down' })
  })

  it('inverts the tone for metrics where lower is better', () => {
    expect(formatDelta(-0.1, 'en', true)?.tone).toBe('success')
    expect(formatDelta(0.1, 'en', true)?.tone).toBe('error')
  })

  it('treats zero as neutral and missing values as null', () => {
    expect(formatDelta(0, 'en')).toEqual({ text: '0%', tone: 'neutral', icon: 'i-tabler-minus' })
    expect(formatDelta(0.0001, 'en')?.tone).toBe('neutral')
    expect(formatDelta(null, 'en')).toBeNull()
    expect(formatDelta(undefined, 'en')).toBeNull()
    expect(formatDelta(Number.NaN, 'en')).toBeNull()
  })

  it('uses the locale decimal separator', () => {
    expect(formatDelta(0.125, 'it')?.text).toMatch(/^\+12,5\s?%$/)
  })
})

describe('number formatting', () => {
  it('formats numbers, compact numbers and percentages', () => {
    expect(formatNumber(1234.5, 'en')).toBe('1,234.5')
    expect(formatNumber(1234.5, 'it')).toBe('1234,5')
    expect(formatNumber(null, 'en')).toBe(EMPTY_VALUE)
    expect(formatCompact(9999, 'en')).toBe('9,999')
    expect(formatCompact(12500, 'en')).toBe('12.5K')
    expect(formatCompact(undefined, 'en')).toBe(EMPTY_VALUE)
    expect(formatPercent(0.4567, 'en')).toBe('45.7%')
    expect(formatPercent(null, 'en')).toBe(EMPTY_VALUE)
  })

  it('formats durations', () => {
    expect(formatDuration(0)).toBe('0s')
    expect(formatDuration(42_400)).toBe('42s')
    expect(formatDuration(83_000)).toBe('1m 23s')
    expect(formatDuration(3_725_000)).toBe('1h 02m')
    expect(formatDuration(null)).toBe(EMPTY_VALUE)
  })

  it('formats money from minor units respecting currency digits', () => {
    expect(formatMoney(12345, 'EUR', 'en')).toBe('€123.45')
    expect(formatMoney(12345, 'JPY', 'en')).toBe('¥12,345')
    expect(formatMoney(null, 'EUR', 'en')).toBe(EMPTY_VALUE)
    expect(formatMoney(100, 'XXZ', 'en')).toContain('XXZ')
    expect(currencyDigits('JPY')).toBe(0)
    expect(currencyDigits('not a currency')).toBe(2)
  })

  it('converts between major and minor units', () => {
    expect(toMinorUnits('12.34', 'EUR')).toBe(1234)
    expect(toMinorUnits('12,34', 'EUR')).toBe(1234)
    expect(toMinorUnits(5, 'JPY')).toBe(5)
    expect(Number.isNaN(toMinorUnits('abc', 'EUR'))).toBe(true)
    expect(fromMinorUnits(1234, 'EUR')).toBe(12.34)
  })
})

describe('date formatting', () => {
  it('formats site-local days without shifting them', () => {
    expect(formatDay('2026-01-31', 'en')).toBe('Jan 31, 2026')
    expect(formatDay('2026-01-31T13:00', 'en', { hour: '2-digit', minute: '2-digit', hour12: false })).toBe('13:00')
    expect(formatDay('not-a-day', 'en')).toBe('not-a-day')
  })

  it('formats site-local hour and minute buckets', () => {
    expect(formatDay('2026-09-17 14:00', 'en', { hour: '2-digit', minute: '2-digit', hour12: false })).toBe('14:00')
    expect(formatClock('2026-09-17 14:05')).toBe('14:05')
    expect(formatClock('2026-09-17T14:05')).toBe('14:05')
    expect(formatClock('2026-09-17')).toBe('2026-09-17')
  })

  it('formats instants in the requested time zone', () => {
    expect(formatDateTime('2026-09-17T22:30:00Z', 'en', 'Europe/Rome', { dateStyle: 'short' })).toBe('9/18/26')
    expect(formatDateTime('2026-09-17T22:30:00Z', 'en', 'Invalid/Zone', { timeStyle: 'short', timeZone: 'UTC' })).toBeTruthy()
    expect(formatDateTime(null, 'en')).toBe(EMPTY_VALUE)
    expect(formatDateTime('garbage', 'en')).toBe('garbage')
  })

  it('names countries and lists time zones', () => {
    expect(countryName('it', 'en')).toBe('Italy')
    expect(countryName('DE', 'it')).toBe('Germania')
    expect(countryName(null, 'en')).toBe(EMPTY_VALUE)
    expect(timeZoneList()).toContain('UTC')
    expect(timeZoneList()).toContain('Europe/Rome')
  })
})
