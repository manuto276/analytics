import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import AuthLayout from '~/layouts/auth.vue'
import LocaleSwitch from '~/components/LocaleSwitch.vue'
import PagesPage from '~/pages/pages.vue'
import { seedState, waitFor } from '../support/api'
import { authConfig, meta } from '../support/fixtures'
import { landingPageRow, pagesRow } from '../support/rows'
import { mountInApp } from '../support/mount'

registerEndpoint('/api/v1/auth/config', { method: 'GET', handler: () => ({ data: authConfig }) })
registerEndpoint('/api/v1/sites/1/reports/pages', { method: 'GET', handler: () => ({ data: { rows: [pagesRow], total_rows: 1 }, meta }) })
registerEndpoint('/api/v1/sites/1/reports/landing-pages', { method: 'GET', handler: () => ({ data: { rows: [landingPageRow], total_rows: 1 }, meta }) })
registerEndpoint('/api/v1/sites/1/reports/content', { method: 'GET', handler: () => ({ data: { rows: [], total_rows: 0 }, meta }) })

describe('accessible label colours', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    seedState()
  })

  it('renders inactive report tabs with the toned token, not the muted one', async () => {
    const wrapper = await mountInApp(PagesPage, { route: '/pages' })
    await waitFor(() => wrapper.findAll('[role="tab"]').length > 0)

    const classes = wrapper.findAll('[role="tab"]').map(tab => tab.attributes('class') ?? '').join(' ')
    expect(classes).toContain('data-[state=inactive]:text-toned')
    expect(classes).not.toContain('data-[state=inactive]:text-muted')
  })

  it('renders the selected report tab with the inverted pair, not white on green', async () => {
    const wrapper = await mountInApp(PagesPage, { route: '/pages' })
    await waitFor(() => wrapper.findAll('[role="tab"]').length > 0)

    const active = wrapper.findAll('[role="tab"]').find(tab => tab.attributes('data-state') === 'active')!
    const classes = active.attributes('class') ?? ''
    // Neutral pill: bg-inverted behind text-inverted (17.7:1 in both modes).
    expect(classes).toContain('data-[state=active]:text-inverted')
    expect(classes).toContain('data-[state=active]:before:bg-inverted')
    expect(classes).not.toContain('data-[state=active]:before:bg-primary')
    // The label itself must not carry a muted colour.
    const label = active.get('[data-slot="label"]').attributes('class') ?? ''
    expect(label).not.toMatch(/text-(muted|dimmed)/)
  })

  it('renders the language switcher label with a readable colour of its own', async () => {
    const wrapper = await mountInApp(LocaleSwitch)
    const trigger = wrapper.get('[data-testid="locale-switch"]')

    expect(trigger.attributes('class')).toContain('text-highlighted')
    expect(trigger.attributes('class')).not.toMatch(/text-(muted|dimmed)(\s|$)/)

    for (const slot of ['value', 'label']) {
      const el = trigger.find(`[data-slot="${slot}"]`)
      if (el.exists()) expect(el.attributes('class') ?? '').not.toMatch(/text-(muted|dimmed)/)
    }
    expect(trigger.text()).toBe('English')
  })

  it('renders the auth footer, including the language switcher, with the toned token', async () => {
    const wrapper = await mountInApp(AuthLayout, { route: '/login' })
    await waitFor(() => wrapper.text().includes('Source code'))

    const footer = wrapper.findAll('div').find(div => div.attributes('class')?.includes('text-xs'))
    expect(footer?.attributes('class')).toContain('text-toned')
    expect(footer?.attributes('class')).not.toContain('text-muted')
    expect(footer?.findComponent({ name: 'USelect' }).exists()).toBe(true)
  })
})
