import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import TotpSetup from '~/components/security/TotpSetup.vue'
import { problem, readJson, seedState, waitFor } from '../support/api'
import { makeUser } from '../support/fixtures'

let confirmCode: string | null = null
let disablePassword: string | null = null

registerEndpoint('/api/v1/auth/totp/setup', {
  method: 'POST',
  handler: () => ({ data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/Analytics:admin', qr_svg: '<svg role="img"></svg>' } })
})
registerEndpoint('/api/v1/auth/totp/confirm', {
  method: 'POST',
  handler: async (event) => {
    confirmCode = (await readJson<{ code: string }>(event)).code
    if (confirmCode !== '123456') return problem(event, 422, 'invalid_code', { detail: 'Invalid code' })
    return { data: { recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] } }
  }
})
registerEndpoint('/api/v1/auth/totp', {
  method: 'DELETE',
  handler: async (event) => {
    disablePassword = (await readJson<{ password: string }>(event)).password
    return null
  }
})
registerEndpoint('/api/v1/auth/totp/recovery-codes', { method: 'POST', handler: () => ({ data: { recovery_codes: ['eeee-ffff'] } }) })
registerEndpoint('/api/v1/auth/me', {
  method: 'GET',
  handler: () => ({ data: { user: makeUser({ mfa_enabled: true }), csrf_token: 'csrf', session: { id: 'a', absolute_expires_at: '2026-10-01T00:00:00Z' } } })
})

type Wrapper = Awaited<ReturnType<typeof mountSuspended>>

async function enterCode(wrapper: Wrapper, code: string) {
  wrapper.findComponent({ name: 'UPinInput' }).vm.$emit('update:modelValue', [...code])
  await nextTick()
  await nextTick()
}

async function tick(ms = 20) {
  await new Promise(resolve => setTimeout(resolve, ms))
  await nextTick()
}

describe('TotpSetup', () => {
  beforeEach(() => {
    clearNuxtState()
    confirmCode = null
    disablePassword = null
  })

  it('enrols an authenticator app and shows the recovery codes once', async () => {
    seedState({ user: makeUser({ mfa_enabled: false }) })
    const wrapper = await mountSuspended(TotpSetup)

    await wrapper.get('[data-testid="totp-start"]').trigger('click')
    await tick()

    const setup = wrapper.get('[data-testid="totp-setup"]')
    expect(setup.get('img').attributes('src')).toContain('data:image/svg+xml')
    expect(setup.findAll('input').some(i => i.element.value === 'JBSWY3DPEHPK3PXP')).toBe(true)

    await enterCode(wrapper, '123456')
    await wrapper.get('[data-testid="totp-confirm"]').trigger('click')
    await tick()

    expect(confirmCode).toBe('123456')
    const codes = wrapper.get('[data-testid="recovery-codes"]')
    expect(codes.text()).toContain('aaaa-bbbb')
    expect(codes.text()).toContain('cccc-dddd')
    await waitFor(() => (useState('auth:user').value as { mfa_enabled: boolean }).mfa_enabled)
    expect(useState('auth:user').value).toMatchObject({ mfa_enabled: true })
  })

  it('shows the server error for a wrong code', async () => {
    seedState({ user: makeUser({ mfa_enabled: false }) })
    const wrapper = await mountSuspended(TotpSetup)

    await wrapper.get('[data-testid="totp-start"]').trigger('click')
    await tick()
    await enterCode(wrapper, '000000')
    await wrapper.get('[data-testid="totp-confirm"]').trigger('click')
    await tick()

    expect(wrapper.get('[data-testid="totp-error"]').text()).toContain('Invalid code')
    expect(wrapper.find('[data-testid="recovery-codes"]').exists()).toBe(false)
  })

  it('offers regeneration and disabling when already enabled', async () => {
    seedState({ user: makeUser({ mfa_enabled: true }) })
    const wrapper = await mountSuspended(TotpSetup)

    expect(wrapper.text()).toContain('Enabled')
    await wrapper.findAll('button').find(b => b.text() === 'New recovery codes')!.trigger('click')
    await tick()
    expect(wrapper.get('[data-testid="recovery-codes"]').text()).toContain('eeee-ffff')

    await wrapper.findAll('button').find(b => b.text() === 'Disable')!.trigger('click')
    await tick()
    const password = document.querySelector('input[type="password"]') as HTMLInputElement
    password.value = 'hunter2hunter2'
    password.dispatchEvent(new Event('input'))
    await tick()
    ;(document.querySelectorAll('button')[document.querySelectorAll('button').length - 1] as HTMLElement).click()
    await tick()

    expect(disablePassword).toBe('hunter2hunter2')
  })
})
