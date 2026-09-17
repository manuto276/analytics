import { expect, type Page } from '@playwright/test'

/** Credentials created by deploy/docker/scripts/e2e-setup.sh / global-setup.ts. */
export const ADMIN = {
  email: process.env.E2E_ADMIN_EMAIL ?? 'admin@analytics.test',
  password: process.env.E2E_ADMIN_PASSWORD ?? 'Fixture-Passw0rd-2026',
}

/**
 * Makes the SPA deterministic before the first script runs: light colour mode
 * (the preference is read from localStorage) and a preselected site, so the
 * layout does not rewrite the URL mid-test.
 */
export async function preparePage(page: Page, siteId: number | null = 1): Promise<void> {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await page.addInitScript(([site]) => {
    try {
      window.localStorage.setItem('nuxt-color-mode', 'light')
      if (site !== null) window.localStorage.setItem('analytics:site', String(site))
    } catch {
      // storage can be unavailable; the test still works
    }
  }, [siteId] as const)
}

/** Logs in through the real form and waits for the dashboard shell. */
export async function login(
  page: Page,
  email: string = ADMIN.email,
  password: string = ADMIN.password,
  { expectMfa = false }: { expectMfa?: boolean } = {},
): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: /Sign in|Accedi/ }).click()

  if (expectMfa) {
    await expect(page).toHaveURL(/\/login\/mfa/)
    return
  }
  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
}

/** Types a 6-digit code into the Nuxt UI pin input (it submits on completion). */
export async function fillPin(page: Page, code: string, testId?: string): Promise<void> {
  const scope = testId ? page.getByTestId(testId) : page
  const inputs = scope.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]')
  await expect(inputs.first()).toBeVisible()
  await inputs.first().click()
  await page.keyboard.type(code, { delay: 30 })
}

/** Builds a dashboard URL with the report query parameters of the SPA. */
export function reportUrl(
  path: string,
  { site = 1, period, from, to, interval, compare }: {
    site?: number
    period?: string
    from?: string
    to?: string
    interval?: string
    compare?: string
  } = {},
): string {
  const params = new URLSearchParams({ site: String(site) })
  if (period) params.set('period', period)
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  if (interval) params.set('interval', interval)
  if (compare) params.set('compare', compare)
  return `${path}?${params.toString()}`
}

/**
 * Opens a report page and waits for its data request, because `useReport`
 * keeps the previous values on screen while refetching.
 */
export async function gotoReport(page: Page, path: string, reportName: string, query: Parameters<typeof reportUrl>[1] = {}): Promise<void> {
  const response = page.waitForResponse(
    r => r.url().includes(`/reports/${reportName}`) && r.status() === 200,
    { timeout: 20_000 },
  )
  await page.goto(reportUrl(path, query))
  await response
}

/**
 * Waits for a Nuxt UI toast. The toast renders both a live-region span and a
 * visible title, so the locator is deliberately not strict.
 */
export async function expectToast(page: Page, text: RegExp | string): Promise<void> {
  await expect(page.getByText(text).first()).toBeVisible({ timeout: 15_000 })
}

/** Reads a KPI value from the Overview cards ("Visitors", "Visits", …). */
export async function overviewMetric(page: Page, label: string | RegExp): Promise<string> {
  const card = page.getByTestId('overview-stats').getByRole('button', { name: label })
  await expect(card).toBeVisible()
  return (await card.locator('span.text-2xl').first().innerText()).trim()
}

/** Parses "1,234" / "1.234" / "12.3K" into a number for tolerant assertions. */
export function parseMetric(value: string): number {
  const text = value.replace(/ /g, ' ').trim()
  if (text === '—' || text === '') return Number.NaN
  const compact = text.match(/^([\d.,]+)\s*(K|M|Mln|k)$/)
  const digits = (compact ? compact[1]! : text).replace(/[^\d.,]/g, '')
  // Both locales are unambiguous here: the last separator is the decimal one
  // only when it is followed by one or two digits.
  const normalised = /[.,]\d{1,2}$/.test(digits)
    ? digits.replace(/[.,](?=\d{3})/g, '').replace(',', '.')
    : digits.replace(/[.,]/g, '')
  const base = Number.parseFloat(normalised)
  if (!compact) return base
  return compact[2] === 'K' || compact[2] === 'k' ? base * 1000 : base * 1_000_000
}

/** The numeric cells of a report table row identified by its first column. */
export async function tableRow(page: Page, firstCell: string | RegExp): Promise<string[]> {
  const row = page.getByRole('row').filter({ hasText: firstCell }).first()
  await expect(row).toBeVisible()
  return (await row.getByRole('cell').allInnerTexts()).map(cell => cell.trim())
}
