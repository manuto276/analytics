import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import ProfilePage from '~/pages/settings/profile.vue'
import type { User } from '~/types'
import { setResponseStatus } from 'h3'
import { problem, readJson, seedState } from '../support/api'
import { authConfig, makeUser } from '../support/fixtures'
import { mountInApp } from '../support/mount'

type Reply = { status: number, code: string, extra?: Record<string, unknown> } | 'ok'

let mailerEnabled = true
let emailReply: Reply = 'ok'
const patches: Record<string, unknown>[] = []
const emailRequests: Record<string, unknown>[] = []
let cancels = 0

registerEndpoint('/api/v1/auth/config', {
  method: 'GET',
  handler: () => ({ data: { ...authConfig, mailer_enabled: mailerEnabled } })
})
registerEndpoint('/api/v1/auth/me', {
  method: 'PATCH',
  handler: async (event) => {
    const body = await readJson<Partial<User>>(event)
    patches.push(body)
    return { data: { user: makeUser(body), csrf_token: 'csrf-456', session: { id: '0123456789abcdef', absolute_expires_at: '2026-10-01T00:00:00Z' } } }
  }
})
registerEndpoint('/api/v1/auth/email', {
  method: 'POST',
  handler: async (event) => {
    const body = await readJson<{ email: string }>(event)
    emailRequests.push(body)
    if (emailReply !== 'ok') return problem(event, emailReply.status, emailReply.code, emailReply.extra)
    setResponseStatus(event, 202)
    return { data: { pending_email: body.email } }
  }
})
registerEndpoint('/api/v1/auth/email', {
  method: 'DELETE',
  handler: (event) => {
    cancels++
    setResponseStatus(event, 204)
    return null
  }
})

const until = (assertion: () => void) => vi.waitFor(assertion, { timeout: 5000, interval: 10 })

type Wrapper = Awaited<ReturnType<typeof mountInApp>>

async function mountProfile(user: User = makeUser()) {
  seedState({ user })
  const wrapper = await mountInApp(ProfilePage, { route: '/settings/profile', attach: true })
  await until(() => expect(wrapper.find('[data-testid="profile-details"]').exists()).toBe(true))
  return wrapper
}

/** Error text linked to an input through aria-describedby. */
function describedBy(wrapper: Wrapper, selector: string) {
  const ids = (wrapper.get(selector).attributes('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
  return ids.map(id => document.getElementById(id)?.textContent ?? '').join(' ')
}

async function requestChange(wrapper: Wrapper, email = 'new@example.com', password = 'correct horse') {
  await wrapper.get('input[data-testid="new-email"]').setValue(email)
  await wrapper.get('input[data-testid="email-password"]').setValue(password)
  await wrapper.get('[data-testid="email-form"]').trigger('submit')
}

describe('profile page', () => {
  beforeEach(() => {
    clearNuxtState()
    mailerEnabled = true
    emailReply = 'ok'
    patches.length = 0
    emailRequests.length = 0
    cancels = 0
    useToast().clear()
  })

  afterEach(async () => {
    await useNuxtApp().$i18n.setLocale('en')
  })

  it('saves the display name and the language', async () => {
    const wrapper = await mountProfile()

    await wrapper.get('input[data-testid="profile-display-name"]').setValue('Grace Hopper')
    wrapper.findComponent({ name: 'USelect' }).vm.$emit('update:modelValue', 'it')
    await nextTick()
    await wrapper.get('[data-testid="profile-details"]').trigger('submit')

    await until(() => expect(patches).toEqual([{ display_name: 'Grace Hopper', locale: 'it' }]))
    await until(() => expect(useNuxtApp().$i18n.locale.value).toBe('it'))
    expect(useAuth().user.value?.display_name).toBe('Grace Hopper')
  })

  it('does not save an empty display name', async () => {
    const wrapper = await mountProfile()

    await wrapper.get('input[data-testid="profile-display-name"]').setValue('  ')
    await wrapper.get('[data-testid="profile-details"]').trigger('submit')

    await until(() => expect(describedBy(wrapper, 'input[data-testid="profile-display-name"]')).toContain('Required'))
    expect(patches).toHaveLength(0)
  })

  it('shows the current address and links to the Security page', async () => {
    const wrapper = await mountProfile()

    expect(wrapper.get('[data-testid="profile-email"]').text()).toBe('admin@example.com')
    expect(wrapper.get('[data-testid="security-link"]').attributes('href')).toContain('/settings/security')
  })

  it('requests an email change and shows the pending notice', async () => {
    const wrapper = await mountProfile()

    await requestChange(wrapper)

    await until(() => expect(wrapper.find('[data-testid="pending-email"]').exists()).toBe(true))
    expect(emailRequests).toEqual([{ email: 'new@example.com', current_password: 'correct horse' }])
    expect(wrapper.get('[data-testid="pending-email"]').text()).toContain('We sent a confirmation link to new@example.com')
    expect(useAuth().user.value?.pending_email).toBe('new@example.com')
    // The password is not kept around, and focus moves to the notice.
    expect((wrapper.get('input[data-testid="email-password"]').element as HTMLInputElement).value).toBe('')
    await until(() => expect(document.activeElement?.getAttribute('data-testid')).toBe('pending-email'))
  })

  it('cancels a pending change', async () => {
    const wrapper = await mountProfile(makeUser({ pending_email: 'next@example.com' }))

    expect(wrapper.get('[data-testid="pending-email"]').text()).toContain('next@example.com')
    await wrapper.get('[data-testid="pending-email"] button').trigger('click')

    await until(() => expect(wrapper.find('[data-testid="pending-email"]').exists()).toBe(false))
    expect(cancels).toBe(1)
    expect(useAuth().user.value?.pending_email).toBeNull()
  })

  it('validates the new address on the client', async () => {
    const wrapper = await mountProfile()

    await requestChange(wrapper, 'not-an-email')

    await until(() => expect(describedBy(wrapper, 'input[data-testid="new-email"]')).toContain('Enter a valid email address'))
    expect(emailRequests).toHaveLength(0)
  })

  it('shows a 409 on the email field', async () => {
    emailReply = { status: 409, code: 'email_taken' }
    const wrapper = await mountProfile()

    await requestChange(wrapper, 'taken@example.com')

    await until(() => expect(describedBy(wrapper, 'input[data-testid="new-email"]')).toContain('already used by another account'))
    await until(() => expect(document.activeElement?.getAttribute('data-testid')).toBe('new-email'))
  })

  it('shows a wrong password on the password field', async () => {
    emailReply = { status: 422, code: 'validation_failed', extra: { errors: { current_password: ['Invalid password.'] } } }
    const wrapper = await mountProfile()

    await requestChange(wrapper, 'new@example.com', 'wrong')

    await until(() => expect(describedBy(wrapper, 'input[data-testid="email-password"]')).toContain('The current password is not correct.'))
    expect((wrapper.get('input[data-testid="email-password"]').element as HTMLInputElement).value).toBe('')
    await until(() => expect(document.activeElement?.getAttribute('data-testid')).toBe('email-password'))
  })

  it('shows 422 field errors from the server on the email field', async () => {
    emailReply = { status: 422, code: 'validation_failed', extra: { errors: { email: ['Must be a valid email address.'] } } }
    const wrapper = await mountProfile()

    await requestChange(wrapper, 'odd@example.com')

    await until(() => expect(describedBy(wrapper, 'input[data-testid="new-email"]')).toContain('Must be a valid email address.'))
  })

  it('shows the retry-after time on 429 as an alert', async () => {
    emailReply = { status: 429, code: 'rate_limited', extra: { retry_after: 30 } }
    const wrapper = await mountProfile()

    await requestChange(wrapper)

    await until(() => expect(wrapper.find('[data-testid="email-error"]').exists()).toBe(true))
    expect(wrapper.get('[data-testid="email-error"]').attributes('role')).toBe('alert')
    expect(wrapper.get('[data-testid="email-error"]').text()).toContain('Try again in 30 seconds')
  })

  it('hides the form and explains why on 501 mailer_disabled', async () => {
    emailReply = { status: 501, code: 'mailer_disabled' }
    const wrapper = await mountProfile()

    await requestChange(wrapper)

    await until(() => expect(wrapper.find('[data-testid="mailer-disabled"]').exists()).toBe(true))
    expect(wrapper.find('[data-testid="email-form"]').exists()).toBe(false)
  })

  it('does not offer the change without a configured mailer', async () => {
    mailerEnabled = false
    const wrapper = await mountProfile()

    expect(wrapper.find('[data-testid="email-form"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="mailer-disabled"]').text()).toContain('needs a configured mailer')
  })
})
