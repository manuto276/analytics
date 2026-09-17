import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import CostImport from '~/components/costs/CostImport.vue'
import { getQuery, header, problem, readRawBody, seedState } from '../support/api'

const calls: { dryRun: string | undefined, contentType: string | undefined, body: string }[] = []

registerEndpoint('/api/v1/sites/1/costs/import', {
  method: 'POST',
  handler: async (event) => {
    const body = (await readRawBody(event, 'utf8')) ?? ''
    const dryRun = (getQuery(event) as Record<string, string>).dry_run
    calls.push({ dryRun, contentType: header(event, 'content-type'), body })
    if (body.includes('oops')) return problem(event, 422, 'invalid_csv', { detail: 'Unparsable CSV' })
    const invalid = body.includes('bad')
    return {
      data: {
        dry_run: dryRun === '1',
        imported: dryRun !== '1',
        batch_id: dryRun === '1' ? null : 'batch-1',
        valid_rows: invalid ? 1 : 2,
        invalid_rows: invalid ? 1 : 0,
        rows: [
          { line: 2, day_from: '2026-09-01', day_to: '2026-09-07', channel: 'paid_search', utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'brand', amount_minor: 12345, currency: 'EUR', note: null, errors: [] },
          ...(invalid ? [{ line: 3, day_from: null, day_to: null, channel: null, utm_source: null, utm_medium: null, utm_campaign: null, amount_minor: null, currency: null, note: null, errors: ['day_from is not a date'] }] : [])
        ]
      }
    }
  }
})

const HEADER = 'day_from,day_to,channel,utm_source,utm_medium,utm_campaign,amount,currency,note'

async function tick(ms = 20) {
  await new Promise(resolve => setTimeout(resolve, ms))
  await nextTick()
}

describe('CostImport', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
    calls.length = 0
  })

  it('previews a CSV as a dry run before importing', async () => {
    const wrapper = await mountSuspended(CostImport, { props: { siteId: 1 } })
    await wrapper.get('[data-testid="csv-input"]').setValue(`${HEADER}\n2026-09-01,2026-09-07,paid_search,google,cpc,brand,123.45,EUR,`)

    await wrapper.get('[data-testid="csv-preview"]').trigger('click')
    await tick()

    expect(calls[0]).toMatchObject({ dryRun: '1', contentType: 'text/csv' })
    const preview = wrapper.get('[data-testid="csv-preview-result"]')
    expect(preview.text()).toContain('2 valid rows, 0 invalid rows')
    expect(preview.text()).toContain('paid_search / google / cpc / brand')
    expect(preview.text()).toContain('€123.45')

    await wrapper.get('[data-testid="csv-confirm"]').trigger('click')
    await tick()

    expect(calls[1]).toMatchObject({ dryRun: '0' })
    expect(wrapper.emitted('imported')?.[0]?.[0]).toMatchObject({ imported: true, batch_id: 'batch-1' })
  })

  it('refuses to import while rows are invalid', async () => {
    const wrapper = await mountSuspended(CostImport, { props: { siteId: 1 } })
    await wrapper.get('[data-testid="csv-input"]').setValue(`${HEADER}\nbad row`)

    await wrapper.get('[data-testid="csv-preview"]').trigger('click')
    await tick()

    expect(wrapper.get('[data-testid="csv-preview-result"]').text()).toContain('day_from is not a date')
    expect(wrapper.get('[data-testid="csv-confirm"]').attributes('disabled')).toBeDefined()
    expect(calls).toHaveLength(1)
  })

  it('shows server errors and resets the preview when the CSV changes', async () => {
    const wrapper = await mountSuspended(CostImport, { props: { siteId: 1 } })
    await wrapper.get('[data-testid="csv-input"]').setValue('oops')
    await wrapper.get('[data-testid="csv-preview"]').trigger('click')
    await tick()

    expect(wrapper.text()).toContain('Unparsable CSV')

    await wrapper.get('[data-testid="csv-input"]').setValue(`${HEADER}\n2026-09-01,,paid_search,google,cpc,brand,1.00,EUR,`)
    await wrapper.get('[data-testid="csv-preview"]').trigger('click')
    await tick()
    expect(wrapper.find('[data-testid="csv-preview-result"]').exists()).toBe(true)
  })
})
