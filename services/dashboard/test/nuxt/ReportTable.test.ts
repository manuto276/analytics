import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import ReportTable from '~/components/report/ReportTable.vue'
import { getQuery, seedState } from '../support/api'
import { meta } from '../support/fixtures'
import { SOURCE_COLUMNS } from '~/utils/reportDefinitions'

const requests: Record<string, string>[] = []

registerEndpoint('/api/v1/sites/1/reports/sources', {
  method: 'GET',
  handler: (event) => {
    const query = getQuery(event) as Record<string, string>
    requests.push(query)
    const second = query.cursor === 'next'
    return {
      data: {
        rows: second
          ? [{ channel: 'social', source: 'x', referrer_host: 'x.com', visitors: 5, visits: 6, pageviews: 8, bounce_rate: 0.2, avg_duration_ms: 1000 }]
          : [{ channel: 'search', source: 'google', referrer_host: 'www.google.com', visitors: 100, visits: 120, pageviews: 300, bounce_rate: 0.35, avg_duration_ms: 65_000 }],
        total_rows: 2
      },
      meta: {
        ...meta,
        availability: { visitors: query.period === 'year' ? false : true, bounce: true, duration: query.period === 'year' ? false : true },
        next_cursor: second ? null : 'next'
      }
    }
  }
})

/**
 * A click starts a navigation and a fetch that resolve over several ticks. Waiting a fixed number
 * of milliseconds passes on an idle machine and fails on a loaded CI runner, so every assertion
 * about what a click caused is retried until it holds.
 */
const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 5000, interval: 10 })

async function mountTable(route = '/sources') {
  seedState()
  const wrapper = await mountSuspended(ReportTable, {
    props: { report: 'sources', columns: SOURCE_COLUMNS.channel, params: { group: 'channel' }, title: 'Channels' },
    route
  })
  // The rows arrive from the mocked endpoint a few ticks after the component suspends.
  await until(() => expect(wrapper.find('[data-testid="filter-value"]').exists()).toBe(true))
  await nextTick()
  return wrapper
}

describe('ReportTable', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    requests.length = 0
  })

  it('renders formatted rows and passes the report params', async () => {
    const wrapper = await mountTable()

    await until(() => expect(wrapper.text()).toContain('search'))
    expect(wrapper.text()).toContain('120')
    expect(wrapper.text()).toContain('35%')
    expect(wrapper.text()).toContain('1m 05s')
    expect(requests[0]).toMatchObject({ group: 'channel', period: '30d', limit: '50' })
  })

  it('adds a filter when a dimension value is clicked', async () => {
    const wrapper = await mountTable()

    await wrapper.get('[data-testid="filter-value"]').trigger('click')

    await until(() => {
      expect(useRouter().currentRoute.value.query['filter[channel][is]']).toBe('search')
      expect(requests.at(-1)).toMatchObject({ 'filter[channel][is]': 'search' })
    })
  })

  it('loads the next page with the cursor', async () => {
    const wrapper = await mountTable()

    await wrapper.get('[data-testid="load-more"]').trigger('click')

    await until(() => expect(requests.at(-1)).toMatchObject({ cursor: 'next' }))
    await until(() => expect(wrapper.text()).toContain('social'))
    expect(wrapper.find('[data-testid="load-more"]').exists()).toBe(false)
  })

  it('warns about metrics the range cannot provide', async () => {
    const plain = await mountTable()
    expect(plain.find('[data-testid="availability-notice"]').exists()).toBe(false)

    const wrapper = await mountTable('/sources?period=year')
    await until(() => expect(wrapper.find('[data-testid="availability-notice"]').exists()).toBe(true))
    const notice = wrapper.get('[data-testid="availability-notice"]')
    expect(notice.text()).toContain('Unique visitors are not counted')
    expect(notice.text()).toContain('Visit duration')
  })

  it('sorts by a metric column', async () => {
    const wrapper = await mountTable()
    const header = wrapper.findAll('th button').find(b => b.text() === 'Visits')!

    await header.trigger('click')
    await until(() => expect(requests.at(-1)).toMatchObject({ sort: '-visits' }))

    await header.trigger('click')
    await until(() => expect(requests.at(-1)).toMatchObject({ sort: 'visits' }))
  })

  it('exports CSV', async () => {
    const wrapper = await mountTable()
    URL.createObjectURL = () => 'blob:mock'
    URL.revokeObjectURL = () => {}

    await wrapper.get('[data-testid="csv-export"]').trigger('click')

    await until(() => expect(requests.at(-1)).toMatchObject({ limit: '1000' }))
  })
})
