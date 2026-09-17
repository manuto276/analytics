export function useFormatters() {
  const { t, locale } = useI18n()
  const { currentSite } = useSites()

  const timezone = computed(() => currentSite.value?.timezone)
  const currency = computed(() => currentSite.value?.currency ?? 'EUR')

  return {
    locale,
    timezone,
    currency,
    number: (v: number | null | undefined) => formatNumber(v, locale.value),
    compact: (v: number | null | undefined) => formatCompact(v, locale.value),
    percent: (v: number | null | undefined) => formatPercent(v, locale.value),
    duration: (v: number | null | undefined) => formatDuration(v),
    money: (v: number | null | undefined, cur?: string | null) => formatMoney(v, cur || currency.value, locale.value),
    day: (v: string, options?: Intl.DateTimeFormatOptions) => formatDay(v, locale.value, options),
    time: (v: string | null | undefined) => formatDateTime(v, locale.value, timezone.value, { timeStyle: 'short' }),
    dateTime: (v: string | null | undefined, tz?: string) => formatDateTime(v, locale.value, tz ?? timezone.value),
    delta: (v: number | null | undefined, invert = false) => formatDelta(v, locale.value, invert),
    country: (v: string | null | undefined) => (v ? countryName(v, locale.value) : t('common.unknown')),
    clock: (v: string) => formatClock(v)
  }
}
