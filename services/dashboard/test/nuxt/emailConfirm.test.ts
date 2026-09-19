import { beforeEach, describe, expect, it, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import EmailConfirmPage from '~/pages/account/email/confirm.vue'
import { problem, readJson, seedState } from '../support/api'
import { authConfig, makeUser } from '../support/fixtures'
import { mountInApp } from '../support/mount'

type Reply = { status: number, code: string } | 'ok'

let reply: Reply = 'ok'
const tokens: string[] = []
let meCalls = 0

registerEndpoint('/api/v1/auth/email/confirm', {
  method: 'POST',
  handler: async (event) => {
    tokens.push((await readJson<{ token: string }>(event)).token)
    if (reply !== 'ok') return problem(event, reply.status, reply.code)
    return { data: { email: 'new@example.com' } }
  }
})
registerEndpoint('/api/v1/auth/me', {
  method: 'GET',
  handler: () => {
    meCalls++
    return { data: { user: makeUser({ email: 'new@example.com' }), csrf_token: 'csrf-123', session: { id: '0123456789abcdef', absolute_expires_at: '2026-10-01T00:00:00Z' } } }
  }
})
registerEndpoint('/api/v1/auth/config', { method: 'GET', handler: () => ({ data: authConfig }) })

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 5000, interval: 10 })

function mountConfirm(route = '/account/email/confirm?token=tok_123') {
  return mountInApp(EmailConfirmPage, { route, attach: true })
}

describe('email confirmation page', () => {
  beforeEach(() => {
    clearNuxtState()
    seedState({ user: null, sites: [] })
    reply = 'ok'
    tokens.length = 0
    meCalls = 0
  })

  it('is reachable signed out', () => {
    expect(isPublicRoute('/account/email/confirm')).toBe(true)
  })

  it('confirms the token and tells the visitor to sign in again', async () => {
    const wrapper = await mountConfirm()

    await until(() => expect(wrapper.find('[data-testid="email-confirm-done"]').exists()).toBe(true))
    expect(tokens).toEqual(['tok_123'])
    const done = wrapper.get('[data-testid="email-confirm-done"]').text()
    expect(done).toContain('Your email address is now new@example.com.')
    expect(done).toContain('other sessions were signed out')
    expect(wrapper.get('[data-testid="email-confirm-link"]').attributes('href')).toBe('/login')
    expect(meCalls).toBe(0)
    await until(() => expect(document.activeElement?.contains(wrapper.get('[data-testid="email-confirm-done"]').element)).toBe(true))
  })

  it('refreshes the user when this browser is signed in', async () => {
    seedState({ user: makeUser(), sites: [] })
    const wrapper = await mountConfirm()

    await until(() => expect(wrapper.find('[data-testid="email-confirm-done"]').exists()).toBe(true))
    expect(meCalls).toBe(1)
    expect(useAuth().user.value?.email).toBe('new@example.com')
    expect(wrapper.get('[data-testid="email-confirm-link"]').attributes('href')).toBe('/settings/profile')
  })

  it('explains an expired or used link (410)', async () => {
    reply = { status: 410, code: 'token_expired' }
    const wrapper = await mountConfirm()

    await until(() => expect(wrapper.find('[data-testid="email-confirm-error"]').exists()).toBe(true))
    const error = wrapper.get('[data-testid="email-confirm-error"]')
    expect(error.attributes('role')).toBe('alert')
    expect(error.text()).toContain('expired or was already used')
    expect(wrapper.get('[data-testid="email-confirm-link"]').attributes('href')).toBe('/login')
  })

  it('explains an address taken in the meantime (409)', async () => {
    reply = { status: 409, code: 'email_taken' }
    const wrapper = await mountConfirm()

    await until(() => expect(wrapper.find('[data-testid="email-confirm-error"]').exists()).toBe(true))
    expect(wrapper.get('[data-testid="email-confirm-error"]').text()).toContain('taken by another account')
  })

  it('does not call the API without a token', async () => {
    const wrapper = await mountConfirm('/account/email/confirm')

    await until(() => expect(wrapper.find('[data-testid="email-confirm-error"]').exists()).toBe(true))
    expect(wrapper.get('[data-testid="email-confirm-error"]').text()).toContain('incomplete')
    expect(tokens).toHaveLength(0)
  })

  it('shows the rate limit', async () => {
    reply = { status: 429, code: 'rate_limited' }
    const wrapper = await mountConfirm()

    await until(() => expect(wrapper.find('[data-testid="email-confirm-error"]').exists()).toBe(true))
    expect(wrapper.get('[data-testid="email-confirm-error"]').text()).toContain('Too many attempts')
  })
})
