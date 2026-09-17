import { defineComponent } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import { getQuery, header, seedState } from '../support/api'
import { meta } from '../support/fixtures'

const requests: Record<string, string | undefined>[] = []

registerEndpoint('/api/v1/sites/1/reports/pages', {
  method: 'GET',
  handler: (event) => {
    const query = getQuery(event) as Record<string, string>
    requests.push({ ...query, accept: header(event, 'accept') })
    const page = query.cursor === 'c1' ? 2 : 1
    return {
      data: {
        rows: [{ path: `/page-${page}`, visitors: page * 10, pageviews: page * 20 }],
        total_rows: 2
      },
      meta: { ...meta, availability: { visitors: query.period === 'year' ? false : true, bounce: true, duration: true }, next_cursor: page === 1 ? 'c1' : null }
    }
  }
})

/** Waits for pending useAsyncData requests to settle. */
async function flush(ms = 30) {
  await new Promise(resolve => setTimeout(resolve, ms))
  await nextTick()
}

const Harness = defineComponent({
  setup() {
    const report = useReport('pages', { limit: 50 })
    return { report }
  },
  template: '<div>{{ report.rows.value.length }}</div>'
})

describe('useReport', () => {
  beforeEach(async () => {
    clearNuxtState()
    clearNuxtData()
    requests.length = 0
    seedState()
    await useRouter().replace('/pages')
  })

  it('loads the report for the current site with the URL query', async () => {
    const wrapper = await mountSuspended(Harness)
    await flush()
    const report = wrapper.vm.report

    expect(report.rows.value).toEqual([{ path: '/page-1', visitors: 10, pageviews: 20 }])
    expect(requests[0]).toMatchObject({ period: '30d', limit: '50' })
    expect(report.meta.value?.timezone).toBe('Europe/Rome')
    expect(report.availability.value).toEqual({ visitors: true, bounce: true, duration: true })
    expect(report.nextCursor.value).toBe('c1')
  })

  it('appends rows when loading more and resets them when the query changes', async () => {
    const wrapper = await mountSuspended(Harness)
    await flush()
    const report = wrapper.vm.report

    await report.loadMore()
    expect(report.rows.value.map((r: Record<string, unknown>) => r.path)).toEqual(['/page-1', '/page-2'])
    expect(report.nextCursor.value).toBeNull()
    expect(requests.at(-1)).toMatchObject({ cursor: 'c1' })

    await useReportQuery().setPeriod('7d')
    await flush()
    expect(report.rows.value).toHaveLength(1)
  })

  it('keeps the previous data while a new request is pending', async () => {
    const wrapper = await mountSuspended(Harness)
    await flush()
    const report = wrapper.vm.report
    const before = report.data.value

    await useReportQuery().setPeriod('90d')
    await nextTick()
    expect(report.data.value).toBe(before)

    await flush()
    expect(requests.some(r => r.period === '90d')).toBe(true)
  })

  it('reports metric availability from the response', async () => {
    const wrapper = await mountSuspended(Harness, { route: '/pages?period=year' })
    await flush()

    expect(requests.at(-1)).toMatchObject({ period: 'year' })
    expect(wrapper.vm.report.availability.value.visitors).toBe(false)
  })

  it('requests CSV when downloading', async () => {
    const wrapper = await mountSuspended(Harness)
    await flush()
    URL.createObjectURL = () => 'blob:mock'
    URL.revokeObjectURL = () => {}

    await wrapper.vm.report.downloadCsv('pages.csv')

    expect(requests.at(-1)).toMatchObject({ accept: 'text/csv', limit: '1000' })
  })
})
