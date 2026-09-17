import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ConsentEditor from '~/components/consent/ConsentEditor.vue'
import ConsentBannerPreview from '~/components/consent/ConsentBannerPreview.vue'
import ConsentPublishModal from '~/components/consent/ConsentPublishModal.vue'
import { seedState } from '../support/api'
import type { ConsentConfigInput } from '~/types'

function config(overrides: Partial<ConsentConfigInput> = {}): ConsentConfigInput {
  return {
    texts: {
      en: { title: 'We use cookies', body: 'Choose what we may store.', accept: 'Accept', reject: 'Reject', close: 'Close', policy: 'Privacy policy', reopen: 'Cookie settings' },
      it: { title: 'Usiamo i cookie', body: 'Scegli cosa possiamo salvare.', accept: 'Accetta', reject: 'Rifiuta', close: 'Chiudi', policy: 'Informativa', reopen: 'Impostazioni cookie' }
    },
    policy_urls: { en: 'https://example.com/privacy', it: 'https://example.com/privacy-it' },
    default_locale: 'en',
    theme: { bg: '#ffffff', fg: '#111111', ac: '#1d4ed8', acf: '#ffffff', rad: 8, pos: 'bottom' },
    accepted_ttl_days: 180,
    rejected_ttl_days: 30,
    show_floating_reopen: true,
    ...overrides
  }
}

describe('ConsentEditor', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState()
  })

  it('checks both contrast pairs against WCAG AA', async () => {
    const wrapper = await mountSuspended(ConsentEditor, { props: { modelValue: config() } })
    const check = wrapper.get('[data-testid="contrast-check"]')

    expect(check.text()).toContain('Text contrast 18.88:1')
    expect(check.text()).not.toContain('Contrast must be at least')
    expect(wrapper.vm.contrast.ok).toBe(true)
  })

  it('refuses colours below 4.5:1', async () => {
    const bad = config({ theme: { bg: '#ffffff', fg: '#dddddd', ac: '#ffee00', acf: '#ffffff', rad: 4, pos: 'bottom-left' } })
    const wrapper = await mountSuspended(ConsentEditor, { props: { modelValue: bad } })

    expect(wrapper.get('[data-testid="contrast-check"]').text()).toContain('Contrast must be at least 4.5:1')
    expect(wrapper.vm.contrast.textOk).toBe(false)
    expect(wrapper.vm.contrast.actionOk).toBe(false)
  })

  it('adds and removes locales without touching the default one', async () => {
    const model = config()
    const wrapper = await mountSuspended(ConsentEditor, { props: { 'modelValue': model, 'onUpdate:modelValue': (v: ConsentConfigInput) => Object.assign(model, v) } })

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

  it('locks every field in read-only mode', async () => {
    const wrapper = await mountSuspended(ConsentEditor, { props: { modelValue: config(), readonly: true } })

    expect(wrapper.findAll('button').some(b => b.text() === 'Add language')).toBe(false)
    expect(wrapper.findAll('input').every(i => i.attributes('disabled') !== undefined)).toBe(true)
  })
})

describe('ConsentBannerPreview', () => {
  it('renders the texts and theme of the selected locale', async () => {
    const wrapper = await mountSuspended(ConsentBannerPreview, { props: { config: config(), locale: 'it' } })
    const banner = wrapper.get('[data-testid="banner-preview"]')

    expect(banner.text()).toContain('Usiamo i cookie')
    expect(banner.text()).toContain('Accetta')
    expect(banner.text()).toContain('Impostazioni cookie')
    expect(banner.get('a').attributes('href')).toBe('https://example.com/privacy-it')
    expect(banner.get('[role="dialog"]').attributes('style')).toContain('background: #ffffff')
  })

  it('falls back to the default locale and drops unsafe policy links', async () => {
    const wrapper = await mountSuspended(ConsentBannerPreview, {
      props: { config: config({ policy_urls: { en: 'javascript:alert(1)' } }), locale: 'fr' }
    })

    expect(wrapper.text()).toContain('We use cookies')
    expect(wrapper.find('a').exists()).toBe(false)
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
