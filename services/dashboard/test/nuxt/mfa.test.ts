import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, registerEndpoint } from '@nuxt/test-utils/runtime'
import MfaPage from '~/pages/login/mfa.vue'
import { problem, readJson, seedState, waitFor } from '../support/api'
import { makeUser } from '../support/fixtures'
import { mountInApp } from '../support/mount'

const { navigateToMock } = vi.hoisted(() => ({ navigateToMock: vi.fn() }))
mockNuxtImport('navigateTo', () => navigateToMock)

type Reply = { status: number, code: string, detail?: string, retryAfter?: number } | 'ok'

let reply: Reply = 'ok'
const sentCodes: string[] = []

registerEndpoint('/api/v1/auth/mfa', {
  method: 'POST',
  handler: async (event) => {
    sentCodes.push((await readJson<{ code: string }>(event)).code)
    if (reply === 'ok') return { data: { status: 'ok', user: makeUser(), csrf_token: 'csrf' } }
    return problem(event, reply.status, reply.code, {
      detail: reply.detail,
      ...(reply.retryAfter ? { retry_after: reply.retryAfter } : {})
    })
  }
})

type Wrapper = Awaited<ReturnType<typeof mountInApp>>

async function submitCode(wrapper: Wrapper, code = '123456') {
  wrapper.findComponent({ name: 'UPinInput' }).vm.$emit('update:modelValue', [...code])
  await nextTick()
  await wrapper.get('[data-testid="mfa-submit"]').trigger('submit')
  await new Promise(resolve => setTimeout(resolve, 30))
  await nextTick()
}

function pinValue(wrapper: Wrapper) {
  return wrapper.findComponent({ name: 'UPinInput' }).props('modelValue') as string[]
}

describe('MFA page', () => {
  beforeEach(async () => {
    clearNuxtState()
    seedState({ user: null, sites: [] })
    useState('auth:loaded').value = true
    navigateToMock.mockReset()
    sentCodes.length = 0
    reply = 'ok'
    useToast().clear()
  })

  it('signs in with a valid code', async () => {
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })
    await submitCode(wrapper)

    expect(sentCodes).toEqual(['123456'])
    expect(navigateToMock).toHaveBeenCalledWith('/')
    expect(wrapper.find('[data-testid="mfa-error"]').exists()).toBe(false)
  })

  it('keeps a wrong code on the page, shows the error and clears the input', async () => {
    reply = { status: 401, code: 'invalid_mfa_code', detail: 'Wrong code' }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa', attach: true })
    await submitCode(wrapper, '000000')

    expect(navigateToMock).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="mfa-error"]').text()).toContain('That code is not valid')
    expect(pinValue(wrapper)).toEqual([])
    expect(document.activeElement?.tagName).toBe('INPUT')
  })

  it('treats a 422 the same way', async () => {
    reply = { status: 422, code: 'validation_failed' }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })
    await submitCode(wrapper, '12')

    expect(navigateToMock).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="mfa-error"]').text()).toContain('That code is not valid')
  })

  it('sends the visitor back to the password form when the pending session is gone', async () => {
    reply = { status: 401, code: 'unauthorized' }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })
    await submitCode(wrapper)

    expect(navigateToMock).toHaveBeenCalledWith('/login')
    expect(useToast().toasts.value.at(-1)?.title).toContain('sign-in attempt expired')
  })

  it('does the same for mfa_not_pending', async () => {
    reply = { status: 400, code: 'mfa_not_pending' }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })
    await submitCode(wrapper)

    expect(navigateToMock).toHaveBeenCalledWith('/login')
  })

  it('shows the retry-after message when the account is locked', async () => {
    reply = { status: 429, code: 'account_locked', retryAfter: 45 }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })
    await submitCode(wrapper)

    expect(navigateToMock).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="mfa-error"]').text()).toContain('Try again in 45 seconds')
  })

  it('accepts a recovery code and keeps errors inline there too', async () => {
    reply = { status: 401, code: 'invalid_mfa_code' }
    const wrapper = await mountInApp(MfaPage, { route: '/login/mfa' })

    await wrapper.findAll('button').find(b => b.text() === 'Use a recovery code')!.trigger('click')
    await nextTick()
    await wrapper.get('[data-testid="mfa-recovery"]').setValue('aaaa-bbbb')
    await wrapper.get('[data-testid="mfa-submit"]').trigger('submit')
    await waitFor(() => wrapper.find('[data-testid="mfa-error"]').exists())

    expect(sentCodes).toEqual(['aaaa-bbbb'])
    expect(navigateToMock).not.toHaveBeenCalled()
    expect((wrapper.get('[data-testid="mfa-recovery"]').element as HTMLInputElement).value).toBe('')
  })
})
