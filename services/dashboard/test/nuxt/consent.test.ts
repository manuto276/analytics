import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ConsentEditor from '~/components/consent/ConsentEditor.vue'
import ConsentPublishModal from '~/components/consent/ConsentPublishModal.vue'
import { completeTheme, defaultTheme, exportTheme } from '~/utils/consentTheme'
import type { ConsentDraft, ThemeV2 } from '~/utils/consentTheme'
import type { ConsentThemeV2 } from '~/types'
import { seedState } from '../support/api'

// The editor's complete theme is a valid request body (Schemas['ConsentThemeV2']).
const _bodyCheck: ConsentThemeV2 = defaultTheme()
void _bodyCheck

function config(theme: Partial<ThemeV2> = {}, overrides: Partial<ConsentDraft> = {}): ConsentDraft {
  return {
    texts: {
      en: { title: 'We use cookies', body: 'Choose what we may store.', accept: 'Accept', reject: 'Reject', close: 'Close', policy: 'Privacy policy', reopen: 'Cookie settings' },
      it: { title: 'Usiamo i cookie', body: 'Scegli cosa possiamo salvare.', accept: 'Accetta', reject: 'Rifiuta', close: 'Chiudi', policy: 'Informativa', reopen: 'Impostazioni cookie' }
    },
    policy_urls: { en: 'https://example.com/privacy', it: 'https://example.com/privacy-it' },
    default_locale: 'en',
    theme: { ...defaultTheme(), ...theme },
    accepted_ttl_days: 180,
    rejected_ttl_days: 30,
    show_floating_reopen: true,
    ...overrides
  }
}

type Props = Record<string, unknown>

/** Mounts the editor with a live v-model, so the returned model reflects every edit. */
async function mountEditor(input: ConsentDraft, props: Props = {}) {
  // Reactive, like the page's ref: the sections edit the theme in place.
  const model = reactive(input)
  const wrapper = await mountSuspended(ConsentEditor, {
    props: { 'modelValue': model, 'onUpdate:modelValue': (v: ConsentDraft) => Object.assign(model, v), ...props }
  })
  return { wrapper, model }
}

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 3000, interval: 10 })

describe('ConsentEditor', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
  })

  describe('colours', () => {
    it('checks every pair the server checks against WCAG AA', async () => {
      const { wrapper } = await mountEditor(config())
      const checks = wrapper.findAll('[data-testid="contrast-check"] li')

      expect(checks.map(c => c.attributes('data-testid'))).toEqual(['contrast-text', 'contrast-accentText', 'contrast-link', 'contrast-reopen'])
      expect(checks.every(c => c.attributes('data-ok') === 'true')).toBe(true)
      expect(wrapper.get('[data-testid="contrast-text"]').text()).toContain('Text on background')
      expect(wrapper.get('[data-testid="contrast-text"]').text()).toContain('17.74:1')
      expect(wrapper.text()).not.toContain('Contrast must be at least')
      expect((wrapper.vm as unknown as { contrast: { ok: boolean } }).contrast.ok).toBe(true)
    })

    it('flags failing pairs live, including the reopen button', async () => {
      const theme = defaultTheme()
      theme.colors.link = '#dddddd'
      theme.reopen.background = '#ffffff'
      theme.reopen.text = '#eeeeee'
      const { wrapper, model } = await mountEditor(config(theme))

      expect(wrapper.get('[data-testid="contrast-link"]').attributes('data-ok')).toBe('false')
      expect(wrapper.get('[data-testid="contrast-reopen"]').attributes('data-ok')).toBe('false')
      expect(wrapper.get('[data-testid="contrast-text"]').attributes('data-ok')).toBe('true')

      await wrapper.get('input[data-testid="color-theme.colors.text"]').setValue('#eeeeee')
      expect(model.theme.colors.text).toBe('#eeeeee')
      expect(wrapper.get('[data-testid="contrast-text"]').attributes('data-ok')).toBe('false')
      expect(wrapper.get('[data-testid="contrast-text"]').text()).toContain('Contrast must be at least 4.5:1')
    })

    it('edits every colour and the backdrop', async () => {
      const { wrapper, model } = await mountEditor(config())
      for (const key of ['background', 'text', 'accent', 'accentText', 'link', 'border'] as const) {
        expect(wrapper.find(`input[data-testid="color-theme.colors.${key}"]`).exists()).toBe(true)
      }
      await wrapper.get('input[data-testid="color-theme.colors.accent"]').setValue('#0f766e')
      expect(model.theme.colors.accent).toBe('#0f766e')

      expect(wrapper.find('input[data-testid="color-theme.colors.backdrop"]').exists()).toBe(false)
      await wrapper.get('[data-testid="section-colors"] button[role="switch"]').trigger('click')
      expect(model.theme.colors.backdrop).toBe('#11182780')
      expect(wrapper.find('input[data-testid="color-theme.colors.backdrop"]').exists()).toBe(true)
    })
  })

  describe('typography', () => {
    it('switches between the page font, the system font and font names', async () => {
      const { wrapper, model } = await mountEditor(config())
      expect(wrapper.find('input[data-testid="font-family"]').exists()).toBe(false)

      model.theme.font.family = '"Inter", sans-serif'
      await nextTick()
      const family = wrapper.get<HTMLInputElement>('input[data-testid="font-family"]')
      expect(family.element.value).toBe('"Inter", sans-serif')

      await family.setValue('Inter; }')
      expect(wrapper.get('[data-testid="section-typography"]').text()).toContain('Use font family names separated by commas')
    })

    it('inherits the page size until a size is set', async () => {
      const { wrapper, model } = await mountEditor(config())
      expect(model.theme.font.size).toBeNull()
      await wrapper.get('[data-testid="font-size-inherit"]').trigger('click')
      expect(model.theme.font.size).toBe(16)
    })
  })

  describe('shape and spacing', () => {
    it('shows the values and the server errors of each field', async () => {
      const theme = defaultTheme()
      theme.shape.radius = 12
      const { wrapper } = await mountEditor(config(theme), { errors: { 'theme.shape.buttonRadius': ['Must be between 0 and 999.'] } })
      const section = wrapper.get('[data-testid="section-shape"]')

      expect(section.findAll('input').map(i => (i.element as HTMLInputElement).value)).toContain('12')
      expect(section.text()).toContain('Must be between 0 and 999.')
      expect(section.text()).toContain('Shadow')
    })
  })

  describe('layout', () => {
    it('edits desktop and mobile separately, with the breakpoint', async () => {
      const { wrapper, model } = await mountEditor(config())
      expect(wrapper.find('[data-testid="layout-desktop"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="layout-mobile"]').exists()).toBe(false)
      expect(wrapper.get('[data-testid="section-layout"]').text()).toContain('Screens 640px wide or narrower')

      await wrapper.setProps({ device: 'mobile' })
      expect(wrapper.find('[data-testid="layout-desktop"]').exists()).toBe(false)
      expect(wrapper.get('[data-testid="layout-mobile"]').text()).toContain('equal gaps on both sides')

      // A bottom sheet has no side gap to set.
      model.theme.layout.mobile.position = 'sheet'
      await nextTick()
      expect(wrapper.get('[data-testid="mobile-offset"]').attributes('disabled')).toBeDefined()
    })

    it('follows the device tab chosen in the preview', async () => {
      const onDevice = vi.fn()
      const { wrapper } = await mountEditor(config(), { 'device': 'desktop', 'onUpdate:device': onDevice })
      const tabs = wrapper.get('[data-testid="layout-device"]').findAll('[role="tab"]')
      await tabs[1]!.trigger('mousedown', { button: 0 })
      await tabs[1]!.trigger('click')
      await until(() => expect(onDevice).toHaveBeenCalledWith('mobile'))
    })
  })

  describe('reopen button', () => {
    it('warns when the button is hidden on a device class (Garante B3)', async () => {
      const { wrapper, model } = await mountEditor(config())
      expect(wrapper.find('[data-testid="reopen-hidden-warning"]').exists()).toBe(false)

      model.theme.reopen.mobile.variant = 'hidden'
      await nextTick()
      const warning = wrapper.get('[data-testid="reopen-hidden-warning"]')
      expect(warning.text()).toContain('hidden on: Mobile')
      expect(warning.text()).toContain('data-analytics-consent')

      model.theme.reopen.desktop.variant = 'hidden'
      await nextTick()
      expect(wrapper.get('[data-testid="reopen-hidden-warning"]').text()).toContain('Desktop, Mobile')
    })

    it('uses the button colours until own colours are switched on', async () => {
      const { wrapper, model } = await mountEditor(config())
      expect(wrapper.find('input[data-testid="color-theme.reopen.background"]').exists()).toBe(false)

      await wrapper.get('[data-testid="reopen-own-colors"]').trigger('click')
      expect(model.theme.reopen.background).toBe('#1d4ed8')
      expect(model.theme.reopen.text).toBe('#ffffff')
      await wrapper.get('input[data-testid="color-theme.reopen.text"]').setValue('#1d4ed9')
      expect(wrapper.get('[data-testid="section-reopen"] [data-testid="contrast-reopen"]').attributes('data-ok')).toBe('false')
    })

    it('shows the placement of the selected device and notes when the button is off', async () => {
      const { wrapper } = await mountEditor(config({}, { show_floating_reopen: false }), { device: 'mobile' })
      expect(wrapper.find('[data-testid="reopen-mobile"]').exists()).toBe(true)
      expect(wrapper.get('[data-testid="section-reopen"]').text()).toContain('The floating reopen button is off')
    })
  })

  describe('advanced', () => {
    it('shows the server custom CSS errors inline and jumps to them', async () => {
      const css = '.banner { max-width: 480px }\n.button:first-child { font-size: 20px }'
      const { wrapper } = await mountSuspended(ConsentEditor, {
        attachTo: document.body,
        props: {
          modelValue: config({ css }),
          errors: { 'theme.css': ['Line 2, column 1: Selector ".button:first-child" is not allowed.', 'Something without a position'] }
        }
      }).then(w => ({ wrapper: w }))
      const errors = wrapper.get('[data-testid="css-errors"]')
      expect(errors.attributes('role')).toBe('alert')
      expect(errors.text()).toContain('Line 2, column 1: Selector ".button:first-child" is not allowed.')
      expect(errors.text()).toContain('Something without a position')

      const goTo = errors.findAll('button')
      expect(goTo).toHaveLength(1)
      expect(goTo[0]!.text()).toBe('Go to line 2')
      await goTo[0]!.trigger('click')
      const textarea = wrapper.get<HTMLTextAreaElement>('textarea[data-testid="custom-css"]').element
      expect(textarea.selectionStart).toBe(css.indexOf('.button'))
      expect(wrapper.text()).toContain(`${css.length} / 8192 bytes`)
    })

    it('shows the theme as JSON, the same text it exports', async () => {
      const { wrapper } = await mountEditor(config())
      const json = wrapper.get('[data-testid="json-view"]').text()
      expect(JSON.parse(json)).toEqual(JSON.parse(exportTheme(defaultTheme())))
      expect(json).toContain('"$schema"')
    })

    it('imports a valid theme, filling the missing fields with the defaults', async () => {
      const { wrapper, model } = await mountEditor(config({ css: '.title { font-weight: 700 }' }))
      await wrapper.get('textarea[data-testid="json-import"]').setValue(JSON.stringify({ colors: { accent: '#0f766e' }, layout: { mobile: { position: 'sheet' } } }))
      await wrapper.get('[data-testid="json-apply"]').trigger('click')

      expect(model.theme).toEqual(completeTheme({ colors: { accent: '#0f766e' }, layout: { mobile: { position: 'sheet' } } }))
      expect(model.theme.css).toBe('')
      expect(wrapper.find('[data-testid="json-error"]').exists()).toBe(false)
      expect((wrapper.get('textarea[data-testid="json-import"]').element as HTMLTextAreaElement).value).toBe('')
    })

    it('refuses text that is not JSON and leaves the theme alone', async () => {
      const { wrapper, model } = await mountEditor(config())
      const before = JSON.stringify(model.theme)
      await wrapper.get('textarea[data-testid="json-import"]').setValue('{ colors: ')
      await wrapper.get('[data-testid="json-apply"]').trigger('click')

      expect(wrapper.get('[data-testid="json-error"]').text()).toContain('This is not valid JSON.')
      expect(JSON.stringify(model.theme)).toBe(before)
    })

    it('refuses JSON that does not match the theme schema and names the fields', async () => {
      const { wrapper, model } = await mountEditor(config())
      const before = JSON.stringify(model.theme)
      await wrapper.get('textarea[data-testid="json-import"]').setValue(JSON.stringify({
        colors: { text: 'red' },
        layout: { mobile: { position: 'bottom-right' } },
        reopen: { desktop: { offset: 500 } },
        bg: '#ffffff'
      }))
      await wrapper.get('[data-testid="json-apply"]').trigger('click')

      const error = wrapper.get('[data-testid="json-error"]').text()
      expect(error).toContain('This JSON is not a valid theme')
      expect(error).toContain('colors.text')
      expect(error).toContain('layout.mobile.position')
      expect(error).toContain('reopen.desktop.offset')
      expect(error).toContain('bg')
      expect(JSON.stringify(model.theme)).toBe(before)
    })

    it('imports a theme file', async () => {
      const { wrapper, model } = await mountEditor(config())
      const theme = completeTheme({ shape: { radius: 20, buttonRadius: 999 }, shadow: 'lg' })
      const input = wrapper.get<HTMLInputElement>('input[data-testid="json-file"]')
      Object.defineProperty(input.element, 'files', { value: [new File([exportTheme(theme)], 'theme.json', { type: 'application/json' })], configurable: true })
      await input.trigger('change')

      await until(() => expect(model.theme).toEqual(theme))
    })

    it('exports the theme as a file', async () => {
      const createObjectURL = vi.fn(() => 'blob:theme')
      const revokeObjectURL = vi.fn()
      vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
      const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
      try {
        const { wrapper } = await mountEditor(config())
        await wrapper.get('[data-testid="json-export"]').trigger('click')
        expect(click).toHaveBeenCalledOnce()
        const blob = (createObjectURL.mock.calls[0] as unknown as [Blob])[0]
        expect(JSON.parse(await blob.text())).toEqual(JSON.parse(exportTheme(defaultTheme())))
      } finally {
        click.mockRestore()
        vi.unstubAllGlobals()
      }
    })
  })

  it('adds and removes locales without touching the default one', async () => {
    const { wrapper, model } = await mountEditor(config())

    const input = wrapper.findAll('input').find(i => i.attributes('aria-label') === 'Add language')!
    await input.setValue('de')
    await wrapper.findAll('button').find(b => b.text() === 'Add language')!.trigger('click')
    await nextTick()

    expect(Object.keys(model.texts)).toContain('de')
    expect(model.texts.de).toEqual(model.texts.en)
    expect(model.policy_urls.de).toBe('https://example.com/privacy')

    const remove = wrapper.findAll('button').find(b => b.text() === 'Remove language')
    await remove!.trigger('click')
    await nextTick()
    expect(Object.keys(model.texts)).not.toContain('de')
    expect(Object.keys(model.texts)).toContain('en')
  })

  it('shows server errors on the text fields', async () => {
    const { wrapper } = await mountEditor(config(), { errors: { 'texts.en.accept': ['This field is required.'] } })
    expect(wrapper.text()).toContain('This field is required.')
  })

  it('locks every field in read-only mode', async () => {
    const { wrapper } = await mountEditor(config(), { readonly: true })

    expect(wrapper.findAll('button').some(b => b.text() === 'Add language')).toBe(false)
    expect(wrapper.find('[data-testid="json-import"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="json-file"]').exists()).toBe(false)
    expect(wrapper.findAll('input').every(i => i.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('textarea').every(i => i.attributes('disabled') !== undefined)).toBe(true)
  })
})

describe('ConsentPublishModal', () => {
  it('publishes with the material-change flag and previews the next version', async () => {
    const onPublish = vi.fn()
    await mountSuspended(ConsentPublishModal, { props: { open: true, currentVersion: 3, onPublish } })
    await nextTick()

    expect(document.body.textContent).toContain('Consent version after publishing: 3')

    const toggle = document.querySelector('[data-testid="material-change"]') as HTMLElement
    toggle.click()
    await nextTick()
    expect(document.body.textContent).toContain('Consent version after publishing: 4')
    expect(document.body.textContent).toContain('All previous choices will be invalidated')

    ;(document.querySelector('[data-testid="confirm-publish"]') as HTMLElement).click()
    expect(onPublish).toHaveBeenCalledWith(true)
  })

  it('always bumps the version for a first publication', async () => {
    await mountSuspended(ConsentPublishModal, { props: { open: true, currentVersion: null } })
    await nextTick()

    expect(document.body.textContent).toContain('Consent version after publishing: 1')
  })
})
