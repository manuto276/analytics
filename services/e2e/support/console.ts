/**
 * Runs `bin/analytics` commands from inside the Playwright container.
 *
 * The suite has no docker client, so operator commands go through the
 * test-only bridge that runs next to the application in the compose test stack
 * (services/e2e/support/console-bridge.php, service `console`).
 */
const baseUrl = process.env.E2E_CONSOLE_URL ?? 'http://console:8099'

export interface ConsoleResult {
  exit: number
  stdout: string
  stderr: string
}

export async function runConsole(command: string, args: string[] = [], stdin = ''): Promise<ConsoleResult> {
  const response = await fetch(`${baseUrl}/run`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ command, args, stdin }),
  })
  if (!response.ok) {
    throw new Error(`console bridge answered ${response.status}: ${await response.text()}`)
  }
  return await response.json() as ConsoleResult
}

/** Same, but fails the test when the command exits non-zero. */
export async function console_(command: string, args: string[] = [], stdin = ''): Promise<string> {
  const result = await runConsole(command, args, stdin)
  if (result.exit !== 0) {
    throw new Error(`bin/analytics ${command} ${args.join(' ')} exited ${result.exit}\n${result.stdout}\n${result.stderr}`)
  }
  return result.stdout
}

/** Recomputes the rollups so a report reflects the events just collected. */
export async function runRollups(siteId?: number): Promise<void> {
  await console_('rollup:run', siteId === undefined ? [] : [`--site=${siteId}`])
}

/** The public key of the site created for this run (first site of the instance). */
export async function firstSitePublicKey(): Promise<string> {
  const out = await console_('site:list')
  const key = out.match(/pk_[A-Za-z0-9]{21}/)?.[0]
  if (!key) throw new Error(`no site found:\n${out}`)
  return key
}
