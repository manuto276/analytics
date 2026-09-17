import { expect, test } from '@playwright/test'
import { console_ } from '../support/console'
import { fillPin, login, preparePage, reportUrl } from '../support/dashboard'
import { freshTotpCode } from '../support/totp'

/**
 * Sign-in, authenticator enrolment and the two ways back in (plan §13.2, M2/M8).
 *
 * A dedicated user per project keeps the shared admin free of MFA, since
 * confirming TOTP also revokes the other sessions of that account.
 */
test.describe.configure({ mode: 'serial' })

const PASSWORD = 'Fixture-Passw0rd-2026'

let email: string
let secret: string
let recoveryCodes: string[] = []

test.beforeAll(async ({}, testInfo) => {
  email = `mfa-${testInfo.project.name}-${Date.now()}@analytics.test`
  await console_('user:create-admin', [`--email=${email}`, '--name=MFA user'], PASSWORD)
})

test('an admin created on the console can sign in', async ({ page }) => {
  await preparePage(page, null)
  await login(page, email, PASSWORD)

  await expect(page).toHaveURL(/\/(\?|$)/)
  await expect(page.getByRole('button', { name: /Search/ })).toBeVisible()
})

test('the user enrols an authenticator app', async ({ page }) => {
  await preparePage(page, null)
  await login(page, email, PASSWORD)
  await page.goto('/settings/security')

  await page.getByTestId('totp-start').click()
  const setup = page.getByTestId('totp-setup')
  await expect(setup).toBeVisible()

  secret = (await setup.locator('input[readonly]').first().inputValue()).replace(/\s/g, '')
  expect(secret, 'the shared secret is shown as base32').toMatch(/^[A-Z2-7]{16,}$/)

  await fillPin(page, await freshTotpCode(secret), 'totp-code')
  await page.getByTestId('totp-confirm').click()

  const codes = page.getByTestId('recovery-codes')
  await expect(codes).toBeVisible()
  recoveryCodes = (await codes.locator('li').allInnerTexts()).map(code => code.trim())
  expect(recoveryCodes).toHaveLength(10)
  expect(recoveryCodes[0]).toMatch(/^[a-z0-9]{5}-[a-z0-9]{5}$/)
})

test('signing in now asks for the authenticator code', async ({ page }) => {
  await preparePage(page, null)
  await login(page, email, PASSWORD, { expectMfa: true })

  await expect(page.getByRole('heading', { name: /Two-factor authentication|Autenticazione a due fattori/ })).toBeVisible()

  await fillPin(page, await freshTotpCode(secret))
  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: /Search/ })).toBeVisible()
})

test('a wrong code is refused and a recovery code still works', async ({ page }) => {
  await preparePage(page, null)
  await login(page, email, PASSWORD, { expectMfa: true })

  // A mistyped code keeps the visitor on the step with a message: the backend
  // answers 401 invalid_mfa_code and the pending session stays alive.
  await fillPin(page, '000000')
  await expect(page.getByText(/not valid|non valido/i).first()).toBeVisible()
  await expect(page).toHaveURL(/\/login\/mfa/)

  // A recovery code gets the user in, without signing in again.
  await page.getByRole('button', { name: /Use a recovery code|Usa un codice di recupero/ }).click()
  await page.getByLabel(/Recovery code|Codice di recupero/).fill(recoveryCodes[0]!)
  await page.getByRole('button', { name: /Verify|Verifica/ }).click()

  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: /Search/ })).toBeVisible()
})

test('an unauthenticated visitor is sent to the login page and back', async ({ page }) => {
  await preparePage(page, 1)
  await page.goto(reportUrl('/pages', { site: 1 }))

  await expect(page).toHaveURL(/\/login\?redirect=/)
})
