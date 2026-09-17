import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import ConsentReceipts from '~/components/consent/ConsentReceipts.vue'
import { getQuery, problem, seedState, waitFor } from '../support/api'
import { mountInApp } from '../support/mount'

let mode: 'ok' | 'empty' | 'disabled' = 'ok'
const queries: string[] = []

registerEndpoint('/api/v1/sites/1/consent/receipts', {
  method: 'GET',
  handler: (event) => {
    const visitorId = (getQuery(event) as Record<string, string>).visitor_id
    queries.push(visitorId ?? '')
    if (mode === 'disabled') return problem(event, 409, 'receipts_disabled')
    if (mode === 'empty') return { data: [] }
    return {
      data: [
        { consent_version: 2, decision: 'accept', decided_at: '2026-09-10T08:30:00Z' },
        { consent_version: 1, decision: 'accept', decided_at: '2026-04-02T12:00:00Z' }
      ]
    }
  }
})

const VISITOR_ID = 'AbCdEfGhIjKlMnOpQrStUv'

async function setId(wrapper: Awaited<ReturnType<typeof mountInApp>>, value: string) {
  await wrapper.get('[data-testid="receipt-visitor-id"]').setValue(value)
  await nextTick()
}

describe('consent receipts panel', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
    queries.length = 0
    mode = 'ok'
  })

  it('looks up the receipts of one visitor', async () => {
    const wrapper = await mountInApp(ConsentReceipts, { props: { siteId: 1 } })
    expect(wrapper.text()).toContain('The visitor gives you this id')

    await setId(wrapper, VISITOR_ID)
    await wrapper.get('[data-testid="receipt-lookup"]').trigger('click')
    await waitFor(() => wrapper.find('[data-testid="receipt-results"]').exists())

    expect(queries).toEqual([VISITOR_ID])
    const results = wrapper.get('[data-testid="receipt-results"]')
    expect(results.text()).toContain('Accepted consent version 2')
    expect(results.text()).toContain('Accepted consent version 1')
  })

  it('refuses to call the API with a malformed visitor id', async () => {
    const wrapper = await mountInApp(ConsentReceipts, { props: { siteId: 1 } })
    await setId(wrapper, 'not-an-id')

    expect(wrapper.get('[data-testid="receipt-lookup"]').attributes('disabled')).toBeDefined()
    expect(queries).toHaveLength(0)
  })

  it('says so when the visitor has no receipts', async () => {
    mode = 'empty'
    const wrapper = await mountInApp(ConsentReceipts, { props: { siteId: 1 } })
    await setId(wrapper, VISITOR_ID)
    await wrapper.get('[data-testid="receipt-lookup"]').trigger('click')
    await waitFor(() => wrapper.find('[data-testid="receipt-results"]').exists())

    expect(wrapper.get('[data-testid="receipt-results"]').text()).toContain('No receipts for this visitor id')
  })

  it('explains a 409 when receipts are switched off', async () => {
    mode = 'disabled'
    const wrapper = await mountInApp(ConsentReceipts, { props: { siteId: 1 } })
    await setId(wrapper, VISITOR_ID)
    await wrapper.get('[data-testid="receipt-lookup"]').trigger('click')
    await waitFor(() => wrapper.find('[data-testid="receipt-error"]').exists())

    expect(wrapper.get('[data-testid="receipt-error"]').text()).toContain('Consent receipts are disabled')
  })
})
