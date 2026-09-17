import type { MaybeRefOrGetter } from 'vue'
import type { ReportMeta, ReportRow } from '~/types'

export type ReportParams = Record<string, string | number | boolean | null | undefined>

export interface UseReportOptions {
  /** Send the date range (default true). */
  range?: boolean
  interval?: boolean
  compare?: boolean
  filters?: boolean
  enabled?: MaybeRefOrGetter<boolean>
}

const DEFAULT_AVAILABILITY = { visitors: true, bounce: true, duration: true }

function cleanParams(params: ReportParams): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue
    out[key] = String(value)
  }
  return out
}

/**
 * Loads `/sites/{site}/reports/{name}` with the URL report query.
 * Previous data stays visible while a new request is pending.
 */
export function useReport<T = { data: { rows: ReportRow[], total_rows: number, totals?: ReportRow }, meta: ReportMeta }>(
  name: MaybeRefOrGetter<string>,
  extra: MaybeRefOrGetter<ReportParams> = {},
  options: UseReportOptions = {}
) {
  const api = useApi()
  const query = useReportQuery()
  const { currentSiteId } = useSites()

  const params = computed(() => {
    const base = options.range === false ? {} : query.apiParams({ interval: options.interval, compare: options.compare, filters: options.filters })
    return { ...base, ...cleanParams(toValue(extra)) }
  })
  const enabled = computed(() => !!currentSiteId.value && (options.enabled === undefined || toValue(options.enabled)))
  const url = computed(() => `/sites/${currentSiteId.value}/reports/${toValue(name)}`)
  const key = computed(() => `report:${url.value}:${enabled.value}:${stableStringify(params.value)}`)

  const { data: fresh, status, error, refresh } = useAsyncData<T | null>(
    key,
    () => (enabled.value ? api<T>(url.value, { query: params.value }) : Promise.resolve(null)),
    { default: () => null }
  )

  const data = shallowRef<T | null>(fresh.value as T | null)
  watch(fresh, (value) => {
    if (value !== null && value !== undefined) data.value = value as T
  })

  const loading = computed(() => status.value === 'pending')
  const meta = computed<ReportMeta | null>(() => (data.value as { meta?: ReportMeta } | null)?.meta ?? null)
  const availability = computed(() => meta.value?.availability ?? DEFAULT_AVAILABILITY)

  // Cursor pagination for table reports.
  const extraRows = shallowRef<ReportRow[]>([])
  const extraCursor = ref<string | null | undefined>(undefined)
  const loadingMore = ref(false)
  watch(key, () => {
    extraRows.value = []
    extraCursor.value = undefined
  })

  const rows = computed<ReportRow[]>(() => {
    const base = (data.value as { data?: { rows?: ReportRow[] } } | null)?.data?.rows ?? []
    return [...base, ...extraRows.value]
  })
  const nextCursor = computed(() => (extraCursor.value === undefined ? meta.value?.next_cursor ?? null : extraCursor.value))

  async function loadMore() {
    if (!nextCursor.value || loadingMore.value) return
    loadingMore.value = true
    try {
      const res = await api<{ data: { rows: ReportRow[] }, meta: ReportMeta }>(url.value, { query: { ...params.value, cursor: nextCursor.value } })
      extraRows.value = [...extraRows.value, ...res.data.rows]
      extraCursor.value = res.meta.next_cursor ?? null
    } finally {
      loadingMore.value = false
    }
  }

  async function downloadCsv(filename = `${toValue(name).replace(/\W+/g, '-')}.csv`) {
    const blob = await api<Blob>(url.value, {
      query: { ...params.value, limit: 1000 },
      headers: { Accept: 'text/csv' },
      responseType: 'blob'
    })
    const href = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = href
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(href)
  }

  return {
    data,
    rows,
    meta,
    availability,
    status,
    loading,
    error,
    refresh,
    params,
    nextCursor,
    loadingMore,
    loadMore,
    downloadCsv
  }
}
