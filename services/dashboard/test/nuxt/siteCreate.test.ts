import { beforeEach, describe, expect, it, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import SiteCreateModal from '~/components/SiteCreateModal.vue'
import { problem, readJson, seedState, waitFor } from '../support/api'
import { makeSite } from '../support/fixtures'
import { mountInApp } from '../support/mount'

const created: Record<string, unknown>[] = []
let reject: Record<string, string[]> | null = null

registerEndpoint('/api/v1/sites', {
  method: 'POST',
  handler: async (event) => {
    const body = await readJson(event)
    created.push(body)
    if (reject) return problem(event, 422, 'validation_failed', { detail: 'The request is not valid.', errors: reject })
    return { data: makeSite({ id: 12, ...body }) }
  }
})

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 5000, interval: 10 })

function tagsInput() {
  return [...document.querySelectorAll('input')].find(i => i.getAttribute('placeholder') === 'example.com') as HTMLInputElement
}

/** Types into the tags field without pressing Enter. */
async function typePending(value: string) {
  const input = tagsInput()
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

async function typeTag(value: string) {
  await typePending(value)
  tagsInput().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
  await nextTick()
  await nextTick()
}

function tags(wrapper: Awaited<ReturnType<typeof mountInApp>>) {
  return wrapper.findComponent({ name: 'UInputTags' }).props('modelValue') as string[]
}

/** Text of the messages linked to the tags input (hint and error), as assistive tech reads them. */
function domainsFieldMessages() {
  const ids = (tagsInput().getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
  return ids.map(id => document.getElementById(id)?.textContent ?? '').join(' ')
}

async function openModal() {
  const wrapper = await mountInApp(SiteCreateModal, { attach: true })
  useDashboard().isSiteModalOpen.value = true
  await waitFor(() => !!tagsInput())
  const name = document.querySelector('input[autocomplete="off"]') as HTMLInputElement
  name.value = 'Example'
  name.dispatchEvent(new Event('input', { bubbles: true }))
  return wrapper
}

function clickCreate() {
  (document.querySelector('[data-testid="create-site"]') as HTMLElement).click()
}

describe('SiteCreateModal domains', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState({ sites: [] })
    created.length = 0
    reject = null
  })

  it('adds several domains with Enter without submitting the modal', async () => {
    const wrapper = await openModal()

    await typeTag('example.com')
    await typeTag('example.net')

    expect(tags(wrapper)).toEqual(['example.com', 'example.net'])
    // Enter must not have submitted the form in the meantime.
    expect(created).toHaveLength(0)

    clickCreate()
    await waitFor(() => created.length > 0)

    expect(created[0]).toMatchObject({
      name: 'Example',
      domains: [
        { host: 'example.com', include_subdomains: false },
        { host: 'example.net', include_subdomains: false }
      ]
    })
  })

  it('creates the site from a domain typed without pressing Enter', async () => {
    await openModal()

    await typePending('skeda.fit')
    clickCreate()

    await until(() => expect(created).toHaveLength(1))
    expect(created[0]).toMatchObject({ domains: [{ host: 'skeda.fit', include_subdomains: false }] })
  })

  it('sends a *. domain as "with subdomains" next to plain ones, including a pending one', async () => {
    const wrapper = await openModal()

    await typeTag('*.frascella.dev')
    await typeTag('skeda.fit')
    expect(tags(wrapper)).toEqual(['*.frascella.dev', 'skeda.fit'])
    await typePending('analytics.frascella.dev')
    clickCreate()

    await until(() => expect(created).toHaveLength(1))
    expect(created[0]!.domains).toEqual([
      { host: '*.frascella.dev', include_subdomains: true },
      { host: 'skeda.fit', include_subdomains: false },
      { host: 'analytics.frascella.dev', include_subdomains: false }
    ])
  })

  it('splits a pasted or typed list into one tag per domain', async () => {
    const wrapper = await openModal()

    const paste = new Event('paste', { bubbles: true, cancelable: true })
    Object.defineProperty(paste, 'clipboardData', { value: { getData: () => 'example.com, *.example.net\nexample.org,' } })
    tagsInput().dispatchEvent(paste)
    await until(() => expect(tags(wrapper)).toEqual(['example.com', '*.example.net', 'example.org']))

    await typePending('a.example.com b.example.com')
    clickCreate()
    await until(() => expect(created).toHaveLength(1))
    expect((created[0]!.domains as { host: string }[]).map(d => d.host))
      .toEqual(['example.com', '*.example.net', 'example.org', 'a.example.com', 'b.example.com'])
  })

  it('rejects an invalid host on the client and shows the tag in the error colour', async () => {
    await openModal()

    await typeTag('skeda.fit')
    await typeTag('bad_host.com')
    clickCreate()

    await until(() => expect(domainsFieldMessages()).toContain('Not a valid domain: bad_host.com'))
    expect(created).toHaveLength(0)
    const invalid = [...document.querySelectorAll('[data-testid="domain-tag"][data-invalid]')].map(e => e.textContent)
    expect(invalid).toEqual(['bad_host.com'])
  })

  it('shows a 422 on domains.N.host on the domains field, naming the entry', async () => {
    reject = { 'domains.1.host': ['Must be a valid host name.'] }
    await openModal()

    await typeTag('example.com')
    await typeTag('skeda.fit')
    clickCreate()

    await until(() => expect(domainsFieldMessages()).toContain('skeda.fit: Must be a valid host name.'))
    expect(useDashboard().isSiteModalOpen.value).toBe(true)
    const invalid = [...document.querySelectorAll('[data-testid="domain-tag"][data-invalid]')].map(e => e.textContent)
    expect(invalid).toEqual(['skeda.fit'])
  })
})
