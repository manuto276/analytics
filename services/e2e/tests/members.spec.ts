import { expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { createSite } from '../support/tracking'
import { login, preparePage, reportUrl } from '../support/dashboard'

/**
 * Invitations and per-site roles (plan §13.2, M2/M3): an admin invites a
 * viewer, the viewer accepts the link and signs in automatically, and the
 * viewer cannot reach the management pages of the site.
 */
test.describe.configure({ mode: 'serial' })

const VIEWER_PASSWORD = 'Guest-Passw0rd-2026'

let site: { id: number, publicKey: string }
let viewerEmail: string
let inviteLink: string

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-members-${testInfo.project.name}-${Date.now()}`)
  viewerEmail = `guest-${testInfo.project.name}-${Date.now()}@analytics.test`
})

test('an admin invites a viewer and gets a shareable link', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)
  await page.goto(reportUrl('/settings/members', { site: site.id }))

  await page.getByRole('button', { name: /Invite people|Invita persone/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel(/^Email$/).fill(viewerEmail)
  // The role defaults to viewer, which is what this scenario needs.
  await dialog.getByRole('button', { name: /Create invitation|Crea invito/ }).click()

  const link = dialog.locator('input[readonly]').first()
  await expect(link).toBeVisible()
  inviteLink = await link.inputValue()
  expect(inviteLink).toMatch(/\/invite\/[A-Za-z0-9_-]{43}$/)

  await page.keyboard.press('Escape')
  await expect(page.getByTestId('site-invitations')).toContainText(viewerEmail)
})

test('the viewer accepts the invitation and lands in the dashboard', async ({ page }) => {
  await preparePage(page, site.id)
  await page.goto(inviteLink)

  await expect(page.getByRole('heading', { name: /Accept invitation|Accetta invito/ })).toBeVisible()
  await expect(page.getByText(viewerEmail)).toBeVisible()

  await page.getByLabel(/^Name$|^Nome$/).fill('Guest viewer')
  await page.getByLabel(/^Password$/).fill(VIEWER_PASSWORD)
  await page.getByLabel(/Confirm password|Conferma password/).fill(VIEWER_PASSWORD)
  await page.getByRole('button', { name: /Create account|Crea account/ }).click()

  await expect(page).not.toHaveURL(/\/invite\//, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: /Search/ })).toBeVisible()
})

test('the viewer can read the reports but not manage the site', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page, viewerEmail, VIEWER_PASSWORD)

  // Reading is allowed.
  const overview = page.waitForResponse(r => r.url().includes('/reports/overview') && r.status() === 200)
  await page.goto(reportUrl('/', { site: site.id }))
  await overview
  await expect(page.getByTestId('overview-stats')).toBeVisible()

  // Managing is not: the settings shell hides the site:manage sections and the
  // site form has no save button.
  await page.goto(reportUrl('/settings', { site: site.id }))
  await expect(page.getByRole('heading', { name: /Settings|Impostazioni/ })).toBeVisible()
  await expect(page.getByRole('link', { name: /^Members$|^Membri$/ })).toHaveCount(0)
  await expect(page.getByRole('link', { name: /API keys|Chiavi API/ })).toHaveCount(0)
  await expect(page.getByTestId('save-site')).toHaveCount(0)

  // Deep-linking into a management page shows the permission notice instead.
  await page.goto(reportUrl('/settings/api-keys', { site: site.id }))
  await expect(page.getByText(/Only site administrators|Solo gli amministratori/).first()).toBeVisible()
})

test('the API refuses site changes made by a viewer', async () => {
  const viewer = await Api.login(viewerEmail, VIEWER_PASSWORD)

  const response = await viewer.raw().patch(`/api/v1/sites/${site.id}`, {
    data: { name: 'renamed by a viewer' },
    headers: { 'X-CSRF-Token': viewer.csrfToken() },
  })
  expect(response.status()).toBe(403)

  const problem = await response.json()
  expect(problem.code).toBe('forbidden')
  await viewer.dispose()
})
