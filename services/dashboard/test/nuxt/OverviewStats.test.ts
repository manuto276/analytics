import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OverviewStats from '~/components/overview/OverviewStats.vue'
import { seedState } from '../support/api'
import type { OverviewMetrics } from '~/types'

const metrics: OverviewMetrics = {
  visitors: 1234,
  visits: 2000,
  pageviews: 5000,
  views_per_visit: 2.5,
  bounce_rate: 0.42,
  avg_duration_ms: 95_000,
  events: 30,
  conversions: 12,
  revenue_minor: 123456,
  consented_visits: 800,
  consent_rate: 0.4
}

const deltas = { visitors: 0.2, visits: -0.1, bounce_rate: -0.05, revenue_minor: null }

async function mount(availability = { visitors: true, bounce: true, duration: true }) {
  seedState()
  return mountSuspended(OverviewStats, { props: { metrics, deltas, availability } })
}

describe('OverviewStats', () => {
  it('formats every headline metric', async () => {
    const wrapper = await mount()
    const text = wrapper.text()

    expect(text).toContain('1,234')
    expect(text).toContain('5,000')
    expect(text).toContain('42%')
    expect(text).toContain('1m 35s')
    expect(text).toContain('€1,234.56')
  })

  it('shows deltas with the right tone and hides unknown ones', async () => {
    const wrapper = await mount()
    const badges = wrapper.findAll('[data-testid="stat-delta"]')

    expect(badges).toHaveLength(3)
    expect(badges[0]!.text()).toBe('+20%')
    expect(badges[1]!.text()).toBe('-10%')
    // A falling bounce rate is an improvement.
    expect(badges[2]!.text()).toBe('-5%')
  })

  it('blanks out metrics the range cannot provide', async () => {
    const wrapper = await mount({ visitors: false, bounce: false, duration: true })
    const cards = wrapper.findAll('[data-testid="overview-stats"] > *')

    expect(cards[0]!.text()).toContain('—')
    expect(cards[3]!.text()).toContain('—')
    expect(cards[2]!.text()).toContain('5,000')
  })

  it('selects the chart metric when a card is clicked', async () => {
    const wrapper = await mount()
    await wrapper.findAll('[data-testid="overview-stats"] > *')[2]!.trigger('click')

    expect(wrapper.emitted('update:metric')?.at(-1)).toEqual(['pageviews'])
  })
})
