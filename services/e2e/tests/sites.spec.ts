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
    // The field clearing is not proof the tag landed: assert the tag itself, or the next test
    // fails instead of this one.
    await expect(dialog.getByText(domain, { exact: true })).toBeVisible()
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

test('domains are committed without Enter, and *. means "with subdomains"', async ({ page }, testInfo) => {
  const name = `Blog ${testInfo.project.name} ${Date.now()}`
  await preparePage(page, null)
  await login(page)

  await page.getByTestId('sites-menu').click()
  await page.getByRole('menuitem', { name: /Add site|Aggiungi sito/ }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  const siteName = dialog.getByLabel(/Site name|Nome del sito/)
  await siteName.fill(name)

  const input = dialog.getByPlaceholder('example.com')
  // 1. Typed, then focus moves elsewhere: the blur commits the tag.
  await input.click()
  await input.pressSequentially('*.frascella.dev')
  await siteName.click()
  await expect(dialog.getByText('*.frascella.dev', { exact: true })).toBeVisible()

  // 2. A comma commits the tag as well.
  await input.click()
  await input.pressSequentially('skeda.fit,')
  await expect(dialog.getByText('skeda.fit', { exact: true })).toBeVisible()
  await expect(input).toHaveValue('')

  // 3. Still in the field when Create is clicked: it is sent too.
  await input.pressSequentially('analytics.frascella.dev')
  await dialog.getByRole('button', { name: /^Create$|^Crea$/ }).click()

  await expectToast(page, /Site created|Sito creato/)
  await expect(page).toHaveURL(/\/settings\?site=\d+/)

  const line = (await console_('site:list')).split('\n').find(row => row.includes(name))
  expect(line, 'the new site is listed by the console').toBeTruthy()
  // site:list prints `*.host` for a domain that includes its subdomains.
  expect(line).toContain('*.frascella.dev')
  expect(line).toMatch(/(^|[\s,|])skeda\.fit([\s,|]|$)/)
  expect(line).toMatch(/(^|[\s,|])analytics\.frascella\.dev([\s,|]|$)/)
  expect(line).not.toContain('*.skeda.fit')
  expect(line).not.toContain('*.analytics.frascella.dev')
})

test('an invalid domain is flagged in the modal before anything is sent', async ({ page }) => {
  await preparePage(page, null)
  await login(page)

  await page.getByTestId('sites-menu').click()
  await page.getByRole('menuitem', { name: /Add site|Aggiungi sito/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel(/Site name|Nome del sito/).fill('Never created')

  let posted = false
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().endsWith('/api/v1/sites')) posted = true
  })

  const input = dialog.getByPlaceholder('example.com')
  await input.click()
  await input.pressSequentially('bad_host.com')
  await dialog.getByRole('button', { name: /^Create$|^Crea$/ }).click()

  await expect(dialog.getByText(/Not a valid domain: bad_host\.com|Dominio non valido: bad_host\.com/)).toBeVisible()
  await expect(dialog).toBeVisible()
  expect(posted).toBe(false)
})
