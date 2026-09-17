import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import PeriodPicker from '~/components/report/PeriodPicker.vue'
import FilterChips from '~/components/report/FilterChips.vue'
import ReportToolbar from '~/components/report/ReportToolbar.vue'
import { seedState, waitFor } from '../support/api'

async function tick() {
  await new Promise(resolve => setTimeout(resolve, 20))
  await nextTick()
}

describe('PeriodPicker', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
  })

  it('labels the active preset', async () => {
    const wrapper = await mountSuspended(PeriodPicker, { route: '/pages?period=7d' })
    expect(wrapper.get('[data-testid="period-picker"]').text()).toContain('Last 7 days')
  })

  it('labels a custom range with formatted days', async () => {
    const wrapper = await mountSuspended(PeriodPicker, { route: '/pages?period=custom&from=2026-01-01&to=2026-01-31' })
    expect(wrapper.get('[data-testid="period-picker"]').text()).toContain('Jan 1, 2026')
    expect(wrapper.get('[data-testid="period-picker"]').text()).toContain('Jan 31, 2026')
  })

  it('offers every preset and applies the chosen one', async () => {
    const wrapper = await mountSuspended(PeriodPicker, { route: '/pages' })
    await wrapper.get('[data-testid="period-picker"]').trigger('click')
    await tick()

    await waitFor(() => [...document.querySelectorAll('button')].some(b => b.textContent?.trim() === 'Last 90 days'))
    const buttons = [...document.querySelectorAll('button')].filter(b => b.textContent?.trim())
    const labels = buttons.map(b => b.textContent!.trim())
    expect(labels).toContain('This month')
    expect(labels).toContain('Last 12 months')

    buttons.find(b => b.textContent!.trim() === 'Last 90 days')!.click()
    await waitFor(() => useRouter().currentRoute.value.query.period === '90d')

    expect(useRouter().currentRoute.value.query.period).toBe('90d')
  })
})

describe('ReportToolbar', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
  })

  it('offers only intervals that fit the range', async () => {
    const wrapper = await mountSuspended(ReportToolbar, { props: { interval: true }, route: '/?period=today' })
    const items = wrapper.findAllComponents({ name: 'USelect' })[0]!.props('items') as { value: string }[]

    expect(items.map(i => i.value)).toEqual(['hour', 'day'])
  })

  it('changes the comparison through the URL', async () => {
    const wrapper = await mountSuspended(ReportToolbar, { route: '/?period=30d' })
    const select = wrapper.findAllComponents({ name: 'USelect' }).at(-1)!

    select.vm.$emit('update:modelValue', 'previous_year')
    await waitFor(() => useRouter().currentRoute.value.query.compare === 'previous_year')

    expect(useRouter().currentRoute.value.query.compare).toBe('previous_year')
  })
})

describe('FilterChips', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
  })

  it('renders active filters and removes them', async () => {
    const wrapper = await mountSuspended(FilterChips, { route: '/pages?filter[channel][is]=search&filter[page][prefix]=/blog/' })

    expect(wrapper.text()).toContain('Channel')
    expect(wrapper.text()).toContain('search')
    expect(wrapper.text()).toContain('starts with')

    await wrapper.findAll('button')[0]!.trigger('click')
    await waitFor(() => useRouter().currentRoute.value.query['filter[channel][is]'] === undefined)
    expect(useRouter().currentRoute.value.query['filter[channel][is]']).toBeUndefined()
    expect(useRouter().currentRoute.value.query['filter[page][prefix]']).toBe('/blog/')
  })

  it('renders nothing without filters', async () => {
    const wrapper = await mountSuspended(FilterChips, { route: '/pages' })
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
