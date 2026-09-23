// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { make } from '../../../tracker/src/banner/banner'
import { MSG_ERROR, MSG_READY, MSG_RENDER } from '~/utils/bannerPreview'

/**
 * public/_preview/preview.js as a unit: the page the dashboard frames. It is plain JavaScript
 * served as a static file, so it is evaluated here the way the browser runs it.
 */
// happy-dom replaces the global URL, so resolve the paths without it.
const here = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(here, '../../public/_preview/preview.js'), 'utf8')
const page = readFileSync(resolve(here, '../../public/_preview/banner.html'), 'utf8')

interface PreviewApi {
  parse: (data: unknown) => { view: string, locale: string, config: Record<string, unknown> } | null
  render: (message: unknown) => boolean
  onMessage: (event: { source: unknown, origin: string, data: unknown }) => void
}

type Win = Window & { __an_b?: unknown, __anPreview?: PreviewApi }
const win = window as Win

function load(): PreviewApi {
  new Function(source)()
  return win.__anPreview!
}

function config(overrides: Record<string, unknown> = {}) {
  return {
    v: 1, rev: 2, dl: 'en', at: 180, rt: 180, fl: true,
    css: '.b{color:#111827}',
    ri: 'M12 3a9 9 0 1 0 9 9 4 4 0 0 1-5-5 4 4 0 0 1-4-4z',
    texts: {
      en: { title: 'We use cookies', body: 'Choose.', accept: 'Accept', reject: 'Reject', close: 'Close', policy: 'Privacy', reopen: 'Cookie settings', policyUrl: 'https://example.com/privacy' },
      it: { title: 'Usiamo i cookie', body: 'Scegli.', accept: 'Accetta', reject: 'Rifiuta', close: 'Chiudi', policy: 'Privacy', reopen: 'Impostazioni cookie', policyUrl: '' }
    },
    ...overrides
  }
}

const render = (overrides: Record<string, unknown> = {}) => ({ type: MSG_RENDER, view: 'banner', locale: 'en', config: config(), ...overrides })
const fromParent = (data: unknown) => ({ source: win.parent, origin: win.location.origin, data })

describe('preview page', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    delete win.__an_b
    delete win.__anPreview
  })
  afterEach(() => {
    // Each evaluation adds a listener, as a page load would; drop it with the page.
    if (win.__anPreview) window.removeEventListener('message', win.__anPreview.onMessage as unknown as EventListener)
    vi.restoreAllMocks()
  })

  it('loads only same-origin scripts, no inline code', () => {
    const scripts = [...page.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)]
    expect(scripts.map(m => /src="([^"]+)"/.exec(m[1]!)?.[1])).toEqual(['banner.js', 'preview.js'])
    expect(scripts.every(m => m[2]!.trim() === '')).toBe(true)
    expect(page).not.toMatch(/\son[a-z]+=/i)
    expect(page).not.toMatch(/https?:\/\//)
  })

  it('speaks the protocol of ~/utils/bannerPreview', () => {
    for (const type of [MSG_READY, MSG_RENDER, MSG_ERROR]) expect(source).toContain(`'${type}'`)
  })

  it('renders the banner with the real factory and no-op callbacks', () => {
    const ui = { show: vi.fn(), fab: vi.fn(), close: vi.fn() }
    const factory = vi.fn(() => ui)
    win.__an_b = factory
    const preview = load()

    preview.onMessage(fromParent(render({ locale: 'it' })))

    expect(factory).toHaveBeenCalledOnce()
    const [cfg, decide, open] = factory.mock.calls[0] as unknown as [Record<string, unknown>, () => unknown, () => unknown]
    expect(cfg).toEqual(config())
    expect(decide()).toBeUndefined()
    expect(open()).toBeUndefined()
    expect(ui.show).toHaveBeenCalledWith(false)
    expect(ui.fab).not.toHaveBeenCalled()
    expect(document.documentElement.lang).toBe('it')
  })

  it('shows the reopen button on request', () => {
    const ui = { show: vi.fn(), fab: vi.fn(), close: vi.fn() }
    win.__an_b = () => ui
    load().onMessage(fromParent(render({ view: 'reopen' })))
    expect(ui.fab).toHaveBeenCalledOnce()
    expect(ui.show).not.toHaveBeenCalled()
  })

  it('draws the real banner module and replaces it on every render', () => {
    win.__an_b = make
    const preview = load()

    preview.onMessage(fromParent(render()))
    const host = document.querySelector('[data-analytics-banner]')!
    expect(host.shadowRoot!.querySelector('h2')!.textContent).toBe('We use cookies')
    expect(host.shadowRoot!.querySelectorAll('button.k')).toHaveLength(2)

    preview.onMessage(fromParent(render({ view: 'reopen', locale: 'it' })))
    const hosts = document.querySelectorAll('[data-analytics-banner]')
    expect(hosts).toHaveLength(1)
    const reopen = hosts[0]!.shadowRoot!.querySelector('[data-o]')!
    expect(reopen.getAttribute('aria-label')).toBe('Impostazioni cookie')
    expect(reopen.querySelector('path')!.getAttribute('d')).toBe(config().ri)

    // Clicking inside the preview decides nothing: the banner module gets no-op callbacks.
    preview.onMessage(fromParent(render()))
    ;(document.querySelector('[data-analytics-banner]')!.shadowRoot!.querySelector('[data-a]') as HTMLElement).click()
    expect(document.cookie).toBe('')
  })

  it('ignores messages from other windows or origins', () => {
    const factory = vi.fn()
    win.__an_b = factory
    const preview = load()

    preview.onMessage({ source: win.parent, origin: 'https://evil.test', data: render() })
    preview.onMessage({ source: {}, origin: win.location.origin, data: render() })
    preview.onMessage({ source: win.parent, origin: 'null', data: render() })
    expect(factory).not.toHaveBeenCalled()
  })

  it('listens to real message events', () => {
    const factory = vi.fn(() => ({ show: vi.fn(), fab: vi.fn(), close: vi.fn() }))
    win.__an_b = factory
    load()
    window.dispatchEvent(new MessageEvent('message', { data: render(), origin: 'https://evil.test', source: window }))
    expect(factory).not.toHaveBeenCalled()
    window.dispatchEvent(new MessageEvent('message', { data: render(), origin: win.location.origin, source: window }))
    expect(factory).toHaveBeenCalledOnce()
  })

  it.each([
    ['not an object', 'render'],
    ['another type', { ...render(), type: 'analytics-banner-preview:other' }],
    ['an unknown view', render({ view: 'modal' })],
    ['a bad locale', render({ locale: 'en"><script>' })],
    ['no stylesheet', { ...render(), config: config({ css: undefined }) }],
    ['a huge stylesheet', { ...render(), config: config({ css: 'x'.repeat(300000) }) }],
    ['markup in the icon path', { ...render(), config: config({ ri: '"/><script>alert(1)</script>' }) }],
    ['no texts', { ...render(), config: config({ texts: {} }) }],
    ['an unknown text key', { ...render(), config: config({ texts: { en: { ...config().texts.en, onclick: 'x' } } }) }],
    ['a missing text', { ...render(), config: config({ texts: { en: { title: 'x' } } }) }],
    ['a non-string text', { ...render(), config: config({ texts: { en: { ...config().texts.en, body: { toString: 1 } } } }) }],
    ['a bad text locale', { ...render(), config: config({ texts: { __proto__x: config().texts.en } }) }],
    ['no floating flag', { ...render(), config: config({ fl: 'yes' }) }],
    ['no version', { ...render(), config: config({ v: '1' }) }]
  ])('refuses a message with %s', (_label, data) => {
    const factory = vi.fn()
    win.__an_b = factory
    const preview = load()
    expect(preview.parse(data)).toBeNull()
    preview.onMessage(fromParent(data))
    expect(factory).not.toHaveBeenCalled()
  })

  it('passes on only the fields the banner reads', () => {
    win.__an_b = vi.fn()
    const parsed = load().parse({ ...render(), config: { ...config(), extra: 'x' } })
    expect(parsed!.config).not.toHaveProperty('extra')
  })

  it('reports a missing banner module to the parent instead of rendering', () => {
    const post = vi.spyOn(win.parent, 'postMessage')
    const preview = load()
    expect(preview.render({ view: 'banner', locale: 'en', config: config() })).toBe(false)
    expect(post).toHaveBeenCalledWith({ type: MSG_ERROR, reason: 'banner-module-missing' }, win.location.origin)
  })
})
