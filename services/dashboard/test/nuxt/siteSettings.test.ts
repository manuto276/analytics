import { beforeEach, describe, expect, it, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import SiteSettings from '~/pages/settings/index.vue'
import { problem, readJson, seedState } from '../support/api'
import { makeSite } from '../support/fixtures'
import { mountInApp } from '../support/mount'

const updates: Record<string, unknown>[] = []
let reject: Record<string, string[]> | null = null

registerEndpoint('/api/v1/sites/1', {
  method: 'PATCH',
  handler: async (event) => {
    const body = await readJson(event)
    updates.push(body)
    if (reject) return problem(event, 422, 'validation_failed', { detail: 'The request is not valid.', errors: reject })
    return { data: makeSite({ ...body, domains: [] }) }
  }
})
registerEndpoint('/api/v1/sites/1/snippet', {
  method: 'GET',
  handler: () => ({ data: { html: '<script></script>', proxy_html: '<script></script>', public_key: 'pk_abcdefghijklmnopqrstu' } })
})

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 5000, interval: 10 })

type Wrapper = Awaited<ReturnType<typeof mountInApp>>

function hostInputs(wrapper: Wrapper) {
  return wrapper.findAll<HTMLInputElement>('input[data-testid="domain-host"]')
}

async function addRow(wrapper: Wrapper, host: string) {
  await wrapper.findAll('button').find(b => b.text() === 'Add domain')!.trigger('click')
  await hostInputs(wrapper).at(-1)!.setValue(host)
}

function domainsFieldMessages(wrapper: Wrapper) {
  const ids = (hostInputs(wrapper)[0]!.attributes('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
  return ids.map(id => document.getElementById(id)?.textContent ?? '').join(' ')
}

async function mountSettings() {
  seedState({ sites: [makeSite({ domains: [{ host: 'example.com', include_subdomains: false }] })] })
  const wrapper = await mountInApp(SiteSettings, { route: '/settings?site=1', attach: true })
  await until(() => expect(hostInputs(wrapper)).toHaveLength(1))
  return wrapper
}

describe('site settings domains', () => {
  beforeEach(() => {
    clearNuxtState()
    updates.length = 0
    reject = null
  })

  it('sends *. domains as "with subdomains" next to plain ones', async () => {
    const wrapper = await mountSettings()

    await hostInputs(wrapper)[0]!.setValue('*.frascella.dev')
    await addRow(wrapper, 'skeda.fit')
    await addRow(wrapper, 'https://Analytics.Frascella.dev/path')
    await wrapper.get('form#site-settings').trigger('submit')

    await until(() => expect(updates).toHaveLength(1))
    expect(updates[0]!.domains).toEqual([
      { host: '*.frascella.dev', include_subdomains: true },
      { host: 'skeda.fit', include_subdomains: false },
      { host: 'analytics.frascella.dev', include_subdomains: false }
    ])
  })

  it('turns a typed *. prefix into the subdomains checkbox on blur', async () => {
    const wrapper = await mountSettings()

    const input = hostInputs(wrapper)[0]!
    await input.setValue('*.frascella.dev')
    await input.trigger('blur')

    await until(() => expect(input.element.value).toBe('frascella.dev'))
    expect(wrapper.findComponent({ name: 'UCheckbox' }).props('modelValue')).toBe(true)
  })

  it('flags an invalid host on the client without calling the API', async () => {
    const wrapper = await mountSettings()

    await addRow(wrapper, 'bad_host.com')
    await wrapper.get('form#site-settings').trigger('submit')

    await until(() => expect(domainsFieldMessages(wrapper)).toContain('Not a valid domain: bad_host.com'))
    expect(updates).toHaveLength(0)
    expect(hostInputs(wrapper)[1]!.attributes('aria-invalid')).toBe('true')
    // The whole field is in error; the offending row carries its own marker.
    expect(hostInputs(wrapper).map(i => i.attributes('data-invalid') !== undefined)).toEqual([false, true])
  })

  it('shows a 422 on domains.N.host on the domains field, naming the entry', async () => {
    reject = { 'domains.1.host': ['Must be a valid host name.'] }
    const wrapper = await mountSettings()

    await addRow(wrapper, 'skeda.fit')
    await wrapper.get('form#site-settings').trigger('submit')

    await until(() => expect(domainsFieldMessages(wrapper)).toContain('skeda.fit: Must be a valid host name.'))
    expect(hostInputs(wrapper).map(i => i.attributes('data-invalid') !== undefined)).toEqual([false, true])
  })
})
