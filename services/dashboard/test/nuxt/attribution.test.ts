import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import AttributionPage from '~/pages/attribution.vue'
import { getQuery, seedState, waitFor } from '../support/api'
import { meta } from '../support/fixtures'
import { conversionsRow } from '../support/rows'
import { mountInApp } from '../support/mount'

const requests: Record<string, string>[] = []

registerEndpoint('/api/v1/sites/1/reports/attribution', {
  method: 'GET',
  handler: (event) => {
    const query = getQuery(event) as Record<string, string>
    requests.push(query)
    return {
      data: {
        model: query.model ?? 'first_touch',
        group: query.group ?? 'channel',
        window_days: Number(query.window ?? 30),
        base: query.base ?? null,
        target: query.target ?? null,
        rows: [{
          key: 'organic_search',
          channel: 'organic_search',
          source: 'google',
          utm_campaign: null,
          visits: 120,
          base_conversions: 10,
          target_conversions: 4,
          revenue_minor: 50_000,
          cost_minor: 20_000,
          cac_minor: 5000,
          roas: 2.5
        }],
        totals: { base_conversions: 10, target_conversions: 4, revenue_minor: 50_000, cost_minor: 20_000, unattributed_share: 0.2 }
      },
      meta
    }
  }
})
registerEndpoint('/api/v1/sites/1/reports/conversions', {
  method: 'GET',
  handler: () => ({ data: { rows: [conversionsRow], total_rows: 1 }, meta })
})

function selects(wrapper: Awaited<ReturnType<typeof mountInApp>>) {
  return wrapper.findAllComponents({ name: 'USelect' })
}

describe('attribution page', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    requests.length = 0
    seedState()
  })

  it('restores every selector from the URL', async () => {
    const wrapper = await mountInApp(AttributionPage, {
      route: '/attribution?model=declared&group=campaign&window=90&base=signup&target=purchase'
    })
    await waitFor(() => wrapper.text().includes('organic_search'))

    expect(requests.at(-1)).toMatchObject({
      model: 'declared',
      group: 'campaign',
      window: '90',
      base: 'signup',
      target: 'purchase'
    })

    const values = selects(wrapper).map(s => s.props('modelValue'))
    expect(values).toEqual(['declared', 'campaign', 90])
    expect(wrapper.text()).toContain('20%')
  })

  it('writes selector changes back to the URL', async () => {
    const wrapper = await mountInApp(AttributionPage, { route: '/attribution' })
    await waitFor(() => requests.length > 0)
    expect(requests[0]).toMatchObject({ model: 'first_touch', group: 'channel', window: '30' })
    expect(useRouter().currentRoute.value.query.model).toBeUndefined()

    selects(wrapper)[0]!.vm.$emit('update:modelValue', 'last_non_direct')
    await waitFor(() => useRouter().currentRoute.value.query.model === 'last_non_direct')

    selects(wrapper)[2]!.vm.$emit('update:modelValue', 7)
    await waitFor(() => useRouter().currentRoute.value.query.window === '7')

    const menu = wrapper.findAllComponents({ name: 'USelectMenu' })[0]!
    menu.vm.$emit('update:modelValue', 'purchase')
    await waitFor(() => useRouter().currentRoute.value.query.base === 'purchase')

    await waitFor(() => requests.some(r => r.model === 'last_non_direct' && r.window === '7' && r.base === 'purchase'))
    expect(useRouter().currentRoute.value.fullPath).toContain('model=last_non_direct')
  })

  it('ignores unknown values in the URL', async () => {
    await mountInApp(AttributionPage, { route: '/attribution?model=telepathy&group=nonsense&window=1' })
    await waitFor(() => requests.length > 0)

    expect(requests.at(-1)).toMatchObject({ model: 'first_touch', group: 'channel', window: '30' })
  })

  it('clears a conversion filter from the URL when it is emptied', async () => {
    const wrapper = await mountInApp(AttributionPage, { route: '/attribution?base=signup' })
    await waitFor(() => requests.length > 0)

    wrapper.findAllComponents({ name: 'USelectMenu' })[0]!.vm.$emit('update:modelValue', undefined)
    await waitFor(() => useRouter().currentRoute.value.query.base === undefined)

    expect(useRouter().currentRoute.value.fullPath).not.toContain('base=')
  })
})
