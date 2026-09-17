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

  // Enter commits a tag without submitting the modal (the form has no implicit
  // submit button), so both domains can be typed before creating the site.
  for (const domain of ['shop.example.com', 'checkout.example.com']) {
    const input = dialog.getByPlaceholder('example.com')
    await input.click()
    await input.pressSequentially(domain)
    await input.press('Enter')
    await expect(input).toHaveValue('')
  }
  // Both tags survived: `site:list` in the next test asserts what was stored.

  await dialog.getByRole('button', { name: /^Create$|^Crea$/ }).click()

  await expectToast(page, /Site created|Sito creato/)
  await expect(page).toHaveURL(/\/settings\?site=\d+/)
  await expect(page.getByRole('heading', { name: /Settings|Impostazioni/ })).toBeVisible()
})

test('the new site shows its public key and snippet, and accepts another domain', async ({ page }) => {
  await preparePage(page, null)
  await login(page)

  const list = await console_('site:list')
  const line = list.split('\n').find(row => row.includes(siteName))
  expect(line, 'the new site is listed by the console').toBeTruthy()
  const siteId = Number(line!.match(/\|\s*(\d+)\s*\|/)?.[1])
  const publicKey = line!.match(/pk_[A-Za-z0-9]{21}/)?.[0]
  expect(publicKey).toBeTruthy()
  expect(line).toContain('shop.example.com')
  expect(line).toContain('checkout.example.com')

  await page.goto(`/settings?site=${siteId}`)

  // The snippet is a read-only copy field, so the key lives in its value.
  const snippet = page.getByLabel(/Tracking snippet|Snippet di tracciamento/).first()
  await expect(snippet).toBeVisible()
  expect(await snippet.inputValue()).toContain(`https://analytics.test/t/${publicKey}.js`)

  const proxySnippet = page.getByLabel(/First-party proxy snippet|Snippet proxy/).first()
  expect(await proxySnippet.inputValue()).toContain(`/stats/${publicKey}.js`)

  // A third domain can be added from the site settings and saved.
  const domainFields = page.getByPlaceholder('example.com')
  const existing = await domainFields.count()
  await page.getByRole('button', { name: /Add domain|Aggiungi dominio/ }).click()
  await expect(domainFields).toHaveCount(existing + 1)
  await domainFields.nth(existing).fill('help.example.com')
  await page.getByTestId('save-site').click()
  await expectToast(page, /Changes saved|Modifiche salvate/)

  await expect.poll(async () => await console_('site:list')).toContain('help.example.com')

  const inputValues = await page.locator('input').evaluateAll(
    inputs => inputs.map(input => (input as HTMLInputElement).value),
  )
  expect(inputValues).toContain('shop.example.com')
  expect(inputValues).toContain('checkout.example.com')
  expect(inputValues).toContain('help.example.com')
})
