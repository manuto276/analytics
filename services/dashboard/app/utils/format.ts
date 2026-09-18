/**
 * Locale- and time-zone-aware formatting helpers built on Intl.
 * Ratios (bounce rate, conversion rate, deltas…) are fractions: 0.125 → 12.5 %.
 */

export const EMPTY_VALUE = '—'

export function formatNumber(value: number | null | undefined, locale: string, options: Intl.NumberFormatOptions = {}): string {
  if (value === null || value === undefined || Number.isNaN(value)) return EMPTY_VALUE
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 2, ...options }).format(value)
}

export function formatCompact(value: number | null | undefined, locale: string): string {
  if (value === null || value === undefined) return EMPTY_VALUE
  if (Math.abs(value) < 10000) return formatNumber(value, locale, { maximumFractionDigits: 0 })
  return formatNumber(value, locale, { notation: 'compact', maximumFractionDigits: 1 })
}

export function formatPercent(ratio: number | null | undefined, locale: string, maximumFractionDigits = 1): string {
  if (ratio === null || ratio === undefined || Number.isNaN(ratio)) return EMPTY_VALUE
  return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits }).format(ratio)
}

export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined || Number.isNaN(ms)) return EMPTY_VALUE
  const total = Math.max(0, Math.round(ms / 1000))
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60
  if (hours > 0) return `${hours}h ${String(minutes).padStart(2, '0')}m`
  if (minutes > 0) return `${minutes}m ${String(seconds).padStart(2, '0')}s`
  return `${seconds}s`
}

export function currencyDigits(currency: string, locale = 'en'): number {
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2
  } catch {
    return 2
  }
}

export function formatMoney(minor: number | null | undefined, currency: string, locale: string): string {
  if (minor === null || minor === undefined || Number.isNaN(minor)) return EMPTY_VALUE
  const digits = currencyDigits(currency, locale)
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(minor / 10 ** digits)
  } catch {
    return `${formatNumber(minor / 10 ** digits, locale)} ${currency}`
  }
}

/** Converts a major-unit amount typed by a user ("12.34") into minor units. */
export function toMinorUnits(major: number | string, currency: string): number {
  const value = typeof major === 'string' ? Number(major.replace(',', '.')) : major
  if (!Number.isFinite(value)) return Number.NaN
  return Math.round(value * 10 ** currencyDigits(currency))
}

export function fromMinorUnits(minor: number, currency: string): number {
  return minor / 10 ** currencyDigits(currency)
}

export type DeltaTone = 'success' | 'error' | 'neutral'

export interface FormattedDelta {
  text: string
  tone: DeltaTone
  icon: string
}

/**
 * Formats a relative change (0.1 = +10 %). `invert` marks metrics where a
 * decrease is good (bounce rate).
 */
export function formatDelta(delta: number | null | undefined, locale: string, invert = false): FormattedDelta | null {
  if (delta === null || delta === undefined || Number.isNaN(delta)) return null
  const rounded = Math.round(delta * 1000) / 1000
  const sign = rounded > 0 ? '+' : ''
  const text = sign + new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1, signDisplay: 'never' }).format(Math.abs(rounded))
  const signed = rounded < 0 ? `-${text}` : text
  if (rounded === 0) return { text: signed, tone: 'neutral', icon: 'i-tabler-minus' }
  const good = invert ? rounded < 0 : rounded > 0
  return {
    text: signed,
    tone: good ? 'success' : 'error',
    icon: rounded > 0 ? 'i-tabler-trending-up' : 'i-tabler-trending-down'
  }
}

/**
 * Formats a site-local bucket (`YYYY-MM-DD`, `YYYY-MM-DD HH:MM` or the ISO variant with `T`)
 * without shifting it through the browser time zone.
 */
export function formatDay(day: string, locale: string, options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' }): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(day)
  if (!match) return day
  const [, y, m, d, hh, mm] = match
  const date = new Date(Date.UTC(Number(y), Number(m) - 1, Number(d), Number(hh ?? 0), Number(mm ?? 0)))
  return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(date)
}

/** Formats an instant (ISO date-time) in the given IANA time zone. */
export function formatDateTime(iso: string | null | undefined, locale: string, timeZone?: string, options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' }): string {
  if (!iso) return EMPTY_VALUE
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  try {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(date)
  } catch {
    return new Intl.DateTimeFormat(locale, options).format(date)
  }
}

/** Wall-clock part of a site-local bucket such as "2026-09-17 10:05". */
export function formatClock(bucket: string): string {
  const match = /^\d{4}-\d{2}-\d{2}[T ](\d{2}):(\d{2})/.exec(bucket)
  return match ? `${match[1]}:${match[2]}` : bucket
}

export function countryName(code: string | null | undefined, locale: string): string {
  if (!code) return EMPTY_VALUE
  try {
    return new Intl.DisplayNames([locale], { type: 'region' }).of(code.toUpperCase()) ?? code
  } catch {
    return code
  }
}

export function timeZoneList(): string[] {
  try {
    const zones = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone')
    if (zones?.length) return zones.includes('UTC') ? zones : ['UTC', ...zones]
  } catch {
    // fall through
  }
  return ['UTC', 'Europe/Rome', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo']
}
