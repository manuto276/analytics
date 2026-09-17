import { expect, test } from '@playwright/test'
import { console_ } from '../support/console'
import { expectToast, login, preparePage } from '../support/dashboard'

/**
 * Site management from the dashboard (plan §13.2, M3): an admin creates a site
 * with its domains and gets the snippet to paste on the tracked site.
 */
test.describe.configure({ mode: 'serial' })

let siteName: string

test.beforeAll(async ({}, testInfo) => {
  siteName = `Shop ${testInfo.project.name} ${Date.now()}`
})

test('an admin creates a site with its domains', async ({ page }) => {
  await preparePage(page, null)
  await login(page)

  await page.getByTestId('sites-menu').click()
  await page.getByRole('menuitem', { name: /Add site|Aggiungi sito/ }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await dialog.getByLabel(/Site name|Nome del sito/).fill(siteName)

  // One domain goes into the tag input; the second one is added from the site
  // settings in the next test.
  const domainInput = dialog.getByPlaceholder('example.com')
  await domainInput.click()
  await domainInput.pressSequentially('shop.example.com')
  await domainInput.press('Enter')

  // Engine difference (reported to the dashboard owner): in Firefox the Enter
  // that commits a tag also submits the surrounding form, so the modal is
  // already gone; in Chromium and WebKit the Create button still has to be
  // pressed.
  await page.waitForTimeout(800)
  if (await dialog.isVisible()) {
    await dialog.getByRole('button', { name: /^Create$|^Crea$/ }).click()
  }

  await expectToast(page, /Site created|Sito creato/)
  await expect(page).toHaveURL(/\/settings\?site=\d+/)
  await expect(page.getByRole('heading', { name: /Settings|Impostazioni/ })).toBeVisible()
})

test('the new site shows its public key and snippet, and accepts a second domain', async ({ page }) => {
  await preparePage(page, null)
  await login(page)

  const list = await console_('site:list')
  const line = list.split('\n').find(row => row.includes(siteName))
  expect(line, 'the new site is listed by the console').toBeTruthy()
  const siteId = Number(line!.match(/\|\s*(\d+)\s*\|/)?.[1])
  const publicKey = line!.match(/pk_[A-Za-z0-9]{21}/)?.[0]
  expect(publicKey).toBeTruthy()
  expect(line).toContain('shop.example.com')

  await page.goto(`/settings?site=${siteId}`)

  // The snippet is a read-only copy field, so the key lives in its value.
  const snippet = page.getByLabel(/Tracking snippet|Snippet di tracciamento/).first()
  await expect(snippet).toBeVisible()
  expect(await snippet.inputValue()).toContain(`https://analytics.test/t/${publicKey}.js`)

  const proxySnippet = page.getByLabel(/First-party proxy snippet|Snippet proxy/).first()
  expect(await proxySnippet.inputValue()).toContain(`/stats/${publicKey}.js`)

  // A second domain is added from the site settings and saved.
  const domainFields = page.getByPlaceholder('example.com')
  const existing = await domainFields.count()
  await page.getByRole('button', { name: /Add domain|Aggiungi dominio/ }).click()
  await expect(domainFields).toHaveCount(existing + 1)
  await domainFields.nth(existing).fill('checkout.example.com')
  await page.getByTestId('save-site').click()
  await expectToast(page, /Changes saved|Modifiche salvate/)

  await expect.poll(async () => await console_('site:list')).toContain('checkout.example.com')

  const inputValues = await page.locator('input').evaluateAll(
    inputs => inputs.map(input => (input as HTMLInputElement).value),
  )
  expect(inputValues).toContain('shop.example.com')
  expect(inputValues).toContain('checkout.example.com')
})
