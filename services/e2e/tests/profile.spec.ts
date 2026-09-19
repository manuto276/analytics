import { expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { console_ } from '../support/console'
import { expectToast, login, preparePage } from '../support/dashboard'

/**
 * The profile page: display name and language are saved on the account, not only in the browser.
 * A dedicated user per project keeps the shared admin in English for the other specs.
 *
 * The email change is not covered here: it needs a mailer, and the test stack has none.
 */
const PASSWORD = 'Fixture-Passw0rd-2026'

let email: string

test.beforeAll(async ({}, testInfo) => {
  email = `profile-${testInfo.project.name}-${Date.now()}@analytics.test`
  await console_('user:create-admin', [`--email=${email}`, '--name=Profile user'], PASSWORD)
})

test('the display name and the language persist across a reload', async ({ page }) => {
  await preparePage(page, null)
  await login(page, email, PASSWORD)

  // Reachable from the user menu, above Security.
  await page.getByRole('button', { name: 'Profile user' }).click()
  const menuItems = page.getByRole('menuitem')
  await expect(menuItems.filter({ hasText: /^Profile$/ })).toBeVisible()
  const labels = (await menuItems.allInnerTexts()).map(label => label.trim())
  expect(labels.indexOf('Profile')).toBeLessThan(labels.indexOf('Account security'))
  await menuItems.filter({ hasText: /^Profile$/ }).click()
  await expect(page).toHaveURL(/\/settings\/profile/)

  const newName = `Grace ${Date.now()}`
  await page.getByLabel('Display name').fill(newName)
  await page.getByRole('combobox', { name: 'Language' }).click()
  await page.getByRole('option', { name: 'Italiano' }).click()
  await page.getByRole('button', { name: 'Save changes' }).click()

  await expectToast(page, /Profilo aggiornato|Profile updated/)
  await expect(page.getByLabel('Nome visualizzato')).toBeVisible()

  await page.reload()
  await expect(page.getByLabel('Nome visualizzato')).toHaveValue(newName)
  await expect(page.getByRole('combobox', { name: 'Lingua' })).toContainText('Italiano')

  // Stored on the account: a fresh API session sees both values.
  const api = await Api.login(email, PASSWORD)
  try {
    const me = await api.get<{ data: { user: { display_name: string, locale: string } } }>('/auth/me')
    expect(me.data.user).toMatchObject({ display_name: newName, locale: 'it' })
  } finally {
    await api.dispose()
  }
})
