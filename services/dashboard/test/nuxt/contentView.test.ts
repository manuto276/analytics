import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import PagesPage from '~/pages/pages.vue'
import { getQuery, seedState, waitFor } from '../support/api'
import { meta } from '../support/fixtures'
import { contentRow, landingPageRow, pagesRow } from '../support/rows'
import { mountInApp } from '../support/mount'

const contentQueries: Record<string, string>[] = []

registerEndpoint('/api/v1/sites/1/reports/pages', { method: 'GET', handler: () => ({ data: { rows: [pagesRow], total_rows: 1 }, meta }) })
registerEndpoint('/api/v1/sites/1/reports/landing-pages', { method: 'GET', handler: () => ({ data: { rows: [landingPageRow], total_rows: 1 }, meta }) })
registerEndpoint('/api/v1/sites/1/reports/content', {
  method: 'GET',
  handler: (event) => {
    const query = getQuery(event) as Record<string, string>
    contentQueries.push(query)
    const rows = query.prefix && !contentRow.content_key.startsWith(query.prefix) ? [] : [contentRow]
    return { data: { rows, total_rows: rows.length }, meta }
  }
})

describe('content view', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    contentQueries.length = 0
    seedState()
  })

  it('shows content groups with their channel split inside Pages', async () => {
    const wrapper = await mountInApp(PagesPage, { route: '/pages' })
    await waitFor(() => wrapper.text().includes('guide/analytics'))

    expect(wrapper.text()).toContain('Content groups')
    expect(wrapper.text()).toContain('organic_search 300')
    expect(wrapper.text()).toContain('/blog/hello')
  })

  it('narrows content groups with the prefix filter', async () => {
    const wrapper = await mountInApp(PagesPage, { route: '/pages' })
    await waitFor(() => contentQueries.length > 0)

    await wrapper.get('[data-testid="content-prefix"]').setValue('guide/')
    await waitFor(() => contentQueries.some(q => q.prefix === 'guide/'), 3000)

    expect(contentQueries.at(-1)).toMatchObject({ prefix: 'guide/', period: '30d' })
  })
})
