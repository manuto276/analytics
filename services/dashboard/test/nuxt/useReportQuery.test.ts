import { beforeEach, describe, expect, it } from 'vitest'
import { seedState } from '../support/api'

describe('useReportQuery', () => {
  beforeEach(async () => {
    clearNuxtState()
    seedState()
    await useRouter().replace('/pages')
  })

  it('reads state from the URL and writes it back', async () => {
    const q = useReportQuery()
    expect(q.state.value.period).toBe('30d')
    expect(q.interval.value).toBe('day')

    await q.setPeriod('custom', '2026-01-01', '2026-01-02')
    expect(useRoute().query).toMatchObject({ period: 'custom', from: '2026-01-01', to: '2026-01-02' })
    expect(q.interval.value).toBe('hour')

    await q.changeInterval('day')
    expect(useRoute().query.interval).toBe('day')

    await q.setCompare('previous_period')
    expect(useRoute().query.compare).toBe('previous_period')
    expect(q.state.value.compare).toBe('previous_period')
  })

  it('drops an interval that does not fit the new period', async () => {
    const q = useReportQuery()
    await q.setPeriod('custom', '2026-01-01', '2026-01-02')
    await q.changeInterval('hour')
    await q.setPeriod('12mo')

    expect(useRoute().query.interval).toBeUndefined()
    expect(q.interval.value).toBe('month')
  })

  it('adds, replaces and removes filters', async () => {
    const q = useReportQuery()
    await q.addFilter('channel', 'is', 'search')
    await q.addFilter('page', 'prefix', '/blog/')
    expect(useRoute().query).toMatchObject({ 'filter[channel][is]': 'search', 'filter[page][prefix]': '/blog/' })

    await q.addFilter('channel', 'is', 'social')
    expect(q.state.value.filters).toEqual([{ dim: 'channel', op: 'is', value: 'social' }, { dim: 'page', op: 'prefix', value: '/blog/' }])

    await q.removeFilter({ dim: 'page', op: 'prefix' })
    expect(q.state.value.filters).toHaveLength(1)

    await q.clearFilters()
    expect(q.state.value.filters).toEqual([])
    expect(useRoute().query['filter[channel][is]']).toBeUndefined()
  })

  it('builds API params and a filter-free navigation query', async () => {
    const q = useReportQuery()
    await q.setPeriod('7d')
    await q.setCompare('previous_year')
    await q.addFilter('country', 'is', 'IT')

    expect(q.apiParams({ interval: true })).toEqual({
      'period': '7d',
      'interval': 'day',
      'compare': 'previous_year',
      'filter[country][is]': 'IT'
    })
    expect(q.navQuery.value).toEqual({ period: '7d', compare: 'previous_year' })
  })

  it('keeps unrelated query keys such as the site', async () => {
    await useRouter().replace('/pages?site=4&tab=entry')
    const q = useReportQuery()
    await q.setPeriod('90d')

    expect(useRoute().query).toMatchObject({ site: '4', tab: 'entry', period: '90d' })
  })
})
