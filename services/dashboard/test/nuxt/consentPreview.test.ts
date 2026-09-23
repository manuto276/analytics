import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import ConsentBannerPreview from '~/components/consent/ConsentBannerPreview.vue'
import { MSG_ERROR, MSG_READY, MSG_RENDER, PREVIEW_PAGE } from '~/utils/bannerPreview'
import { defaultTheme } from '~/utils/consentTheme'
import type { ConsentDraft } from '~/utils/consentTheme'
import { problem, readJson, seedState } from '../support/api'

const bodies: ConsentDraft[] = []
let reject: Record<string, string[]> | null = null
let fail = false

const compiled = (title: string) => ({
  v: 1, rev: 1, dl: 'en', at: 180, rt: 180, fl: true, css: '.b{color:#111827}', ri: 'M0 0h24',
  texts: { en: { title, body: 'Choose.', accept: 'Accept', reject: 'Reject', close: 'Close', policy: 'Privacy', reopen: 'Cookie settings', policyUrl: '' } }
})

registerEndpoint('/api/v1/sites/1/consent/preview', {
  method: 'POST',
  handler: async (event) => {
    const body = await readJson<ConsentDraft>(event)
    bodies.push(body)
    if (fail) return problem(event, 500, 'internal_error')
    if (reject) return problem(event, 422, 'validation_failed', { detail: 'The request is not valid.', errors: reject })
    return { data: compiled(body.texts.en!.title) }
  }
})

function draft(title = 'We use cookies'): ConsentDraft {
  return {
    texts: { en: { title, body: 'Choose.', accept: 'Accept', reject: 'Reject', close: 'Close', policy: 'Privacy', reopen: 'Cookie settings' } },
    policy_urls: { en: 'https://example.com/privacy' },
    default_locale: 'en',
    theme: defaultTheme(),
    accepted_ttl_days: 180,
    rejected_ttl_days: 180,
    show_floating_reopen: true
  }
}

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 3000, interval: 10 })
const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))

async function mountPreview(props: Record<string, unknown> = {}) {
  const config = reactive(draft())
  const wrapper = await mountSuspended(ConsentBannerPreview, {
    props: { config, locale: 'en', siteId: 1, debounce: 40, ...props }
  })
  const frame = wrapper.get<HTMLIFrameElement>('iframe[data-testid="preview-frame"]')
  // The frame is never loaded here (no document to navigate); stand in for its window.
  const post = vi.fn()
  Object.defineProperty(frame.element, 'contentWindow', { value: { postMessage: post }, configurable: true })
  return { wrapper, config, frame, post }
}

/** What the frame's page does once banner.js and preview.js have run. */
function frameSays(frame: HTMLIFrameElement, data: unknown) {
  const event = new MessageEvent('message', { data, origin: 'null' })
  Object.defineProperty(event, 'source', { value: frame.contentWindow })
  window.dispatchEvent(event)
}

describe('ConsentBannerPreview', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
    bodies.length = 0
    reject = null
    fail = false
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('frames the static preview page in a sandbox without same-origin access', async () => {
    const { frame } = await mountPreview()
    expect(frame.attributes('src')).toBe(PREVIEW_PAGE)
    expect(frame.attributes('sandbox')).toBe('allow-scripts')
    expect(frame.attributes('referrerpolicy')).toBe('no-referrer')
    expect(frame.attributes('title')).toBe('Consent banner preview')
  })

  it('compiles the configuration once on mount, then once per burst of edits', async () => {
    const { config } = await mountPreview()
    await until(() => expect(bodies).toHaveLength(1))

    config.texts.en!.title = 'A'
    await sleep(5)
    config.texts.en!.title = 'AB'
    await sleep(5)
    config.theme.colors.accent = '#0f766e'
    await sleep(120)

    expect(bodies).toHaveLength(2)
    expect(bodies[1]!.texts.en!.title).toBe('AB')
    expect(bodies[1]!.theme.colors.accent).toBe('#0f766e')
  })

  it('sends the compiled block to the frame once it is ready, and again on every change', async () => {
    const { wrapper, config, frame, post } = await mountPreview()
    await until(() => expect(bodies).toHaveLength(1))
    expect(post).not.toHaveBeenCalled()

    frameSays(frame.element, { type: MSG_READY })
    await until(() => expect(post).toHaveBeenCalledTimes(1))
    expect(post.mock.calls[0]).toEqual([{ type: MSG_RENDER, view: 'banner', locale: 'en', config: compiled('We use cookies') }, '*'])

    config.texts.en!.title = 'Updated'
    await until(() => expect(post).toHaveBeenCalledTimes(2))
    expect((post.mock.calls[1]![0] as { config: { texts: { en: { title: string } } } }).config.texts.en.title).toBe('Updated')

    // Banner / reopen button and locale switch without asking the server again.
    const viewTabs = wrapper.get('[data-testid="preview-view"]').findAll('[role="tab"]')
    await viewTabs[1]!.trigger('mousedown', { button: 0 })
    await viewTabs[1]!.trigger('click')
    await until(() => expect(post).toHaveBeenCalledTimes(3))
    expect(post.mock.calls[2]![0]).toMatchObject({ view: 'reopen' })
    await wrapper.setProps({ locale: 'it' })
    await until(() => expect(post).toHaveBeenCalledTimes(4))
    expect(post.mock.calls[3]![0]).toMatchObject({ view: 'reopen', locale: 'it' })
    expect(bodies).toHaveLength(2)
  })

  it('ignores messages that do not come from its frame', async () => {
    const { post } = await mountPreview()
    await until(() => expect(bodies).toHaveLength(1))

    window.dispatchEvent(new MessageEvent('message', { data: { type: MSG_READY }, origin: window.location.origin, source: window }))
    await sleep(20)
    expect(post).not.toHaveBeenCalled()
  })

  it('reports validation errors and keeps the last valid banner', async () => {
    const { wrapper, config, frame, post } = await mountPreview()
    frameSays(frame.element, { type: MSG_READY })
    await until(() => expect(post).toHaveBeenCalledTimes(1))

    reject = { 'theme.css': ['Line 1, column 1: Selector "p" is not allowed.'] }
    config.theme.css = 'p { color: red }'
    await until(() => expect(wrapper.emitted('errors')?.at(-1)).toEqual([reject]))
    expect(wrapper.find('[data-testid="preview-invalid"]').exists()).toBe(true)
    expect(post).toHaveBeenCalledTimes(1)

    reject = null
    config.theme.css = ''
    await until(() => expect(wrapper.emitted('errors')?.at(-1)).toEqual([{}]))
    expect(wrapper.find('[data-testid="preview-invalid"]').exists()).toBe(false)
    expect(post).toHaveBeenCalledTimes(2)
  })

  it('says so when the server or the banner module fails', async () => {
    fail = true
    const { wrapper, frame } = await mountPreview()
    await until(() => expect(wrapper.find('[data-testid="preview-failed"]').text()).toContain('could not be loaded'))

    fail = false
    frameSays(frame.element, { type: MSG_ERROR, reason: 'banner-module-missing' })
    await until(() => expect(wrapper.find('[data-testid="preview-failed"]').text()).toContain('banner module is missing'))
  })

  it('renders a 390×844 phone or a 1280px desktop, scaled to fit', async () => {
    const { wrapper, frame } = await mountPreview({ device: 'desktop' })
    expect(frame.attributes('data-device')).toBe('desktop')
    expect(frame.attributes('style')).toContain('width: 1280px')
    expect(frame.attributes('style')).toContain('height: 800px')

    await wrapper.setProps({ device: 'mobile' })
    expect(frame.attributes('data-device')).toBe('mobile')
    expect(frame.attributes('style')).toContain('width: 390px')
    expect(frame.attributes('style')).toContain('height: 844px')
    expect(frame.attributes('style')).toMatch(/transform: scale\(/)
  })

  it('does not ask for a preview the user may not see', async () => {
    const wrapper = await mountSuspended(ConsentBannerPreview, { props: { config: draft(), locale: 'en', siteId: 1, enabled: false } })
    await sleep(80)
    expect(bodies).toHaveLength(0)
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.text()).toContain('available to site administrators')
  })
})
