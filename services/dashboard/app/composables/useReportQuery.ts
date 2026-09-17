import type { Compare, FilterDimension, FilterOp, Interval, Period, ReportFilter } from '~/types'
import type { ReportQueryState } from '~/utils/reportQuery'

export function useReportQuery() {
  const router = useRouter()
  // currentRoute keeps the query reactive when the composable runs outside a component.
  const route = router.currentRoute

  const state = computed<ReportQueryState>(() => parseReportQuery(route.value.query))

  async function update(patch: Partial<ReportQueryState>) {
    const next = { ...state.value, ...patch }
    await router.replace({ query: mergeReportQuery(route.value.query, next) })
  }

  async function setPeriod(period: Period, from?: string, to?: string) {
    const allowed = intervalsFor(period, from, to)
    const interval = state.value.interval && allowed.includes(state.value.interval) ? state.value.interval : undefined
    await update(period === 'custom' ? { period, from, to, interval } : { period, from: undefined, to: undefined, interval })
  }

  async function changeInterval(interval: Interval | undefined) {
    await update({ interval })
  }

  async function setCompare(compare: Compare) {
    await update({ compare })
  }

  async function addFilter(dim: FilterDimension, op: FilterOp, value: string) {
    const filters = state.value.filters.filter(f => !(f.dim === dim && f.op === op))
    await update({ filters: [...filters, { dim, op, value }] })
  }

  async function removeFilter(filter: Pick<ReportFilter, 'dim' | 'op'>) {
    await update({ filters: state.value.filters.filter(f => !(f.dim === filter.dim && f.op === filter.op)) })
  }

  async function clearFilters() {
    await update({ filters: [] })
  }

  const interval = computed<Interval>(() => {
    const s = state.value
    const allowed = intervalsFor(s.period, s.from, s.to)
    return s.interval && allowed.includes(s.interval) ? s.interval : defaultInterval(s.period, s.from, s.to)
  })

  /** Query kept when navigating between report pages (no filters). */
  const navQuery = computed(() => serializeReportQuery({ ...state.value, filters: [] }))

  function apiParams(options: { interval?: boolean, compare?: boolean, filters?: boolean } = {}) {
    const params = toApiParams(state.value, options)
    if (options.interval) params.interval = interval.value
    return params
  }

  return {
    state,
    interval,
    navQuery,
    update,
    setPeriod,
    changeInterval,
    setCompare,
    addFilter,
    removeFilter,
    clearFilters,
    apiParams
  }
}
