import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import SiteCreateModal from '~/components/SiteCreateModal.vue'
import { readJson, seedState, waitFor } from '../support/api'
import { makeSite } from '../support/fixtures'
import { mountInApp } from '../support/mount'

const created: Record<string, unknown>[] = []

registerEndpoint('/api/v1/sites', {
  method: 'POST',
  handler: async (event) => {
    const body = await readJson(event)
    created.push(body)
    return { data: makeSite({ id: 12, ...body }) }
  }
})

function tagsInput() {
  return [...document.querySelectorAll('input')].find(i => i.getAttribute('placeholder') === 'example.com') as HTMLInputElement
}

async function typeTag(value: string) {
  const input = tagsInput()
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
  await nextTick()
  await nextTick()
}

describe('SiteCreateModal domains', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState({ sites: [] })
    created.length = 0
  })

  it('adds several domains with Enter without submitting the modal', async () => {
    const wrapper = await mountInApp(SiteCreateModal, { attach: true })
    useDashboard().isSiteModalOpen.value = true
    await waitFor(() => !!tagsInput())

    const name = document.querySelector('input[autocomplete="off"]') as HTMLInputElement
    name.value = 'Example'
    name.dispatchEvent(new Event('input', { bubbles: true }))

    await typeTag('example.com')
    await typeTag('example.net')

    const tags = wrapper.findComponent({ name: 'UInputTags' }).props('modelValue') as string[]
    expect(tags).toEqual(['example.com', 'example.net'])
    // Enter must not have submitted the form in the meantime.
    expect(created).toHaveLength(0)

    const submit = document.querySelector('[data-testid="create-site"]') as HTMLElement
    submit.click()
    await waitFor(() => created.length > 0)

    expect(created[0]).toMatchObject({
      name: 'Example',
      domains: [
        { host: 'example.com', include_subdomains: false },
        { host: 'example.net', include_subdomains: false }
      ]
    })
  })
})
