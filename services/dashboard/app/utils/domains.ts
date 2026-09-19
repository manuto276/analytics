/**
 * Client-side mirror of the API's `DomainMatcher::normalizeHost()`: lenient on what people paste
 * (scheme, path, port, upper case, trailing dot), strict on the resulting host name. Any TLD is
 * accepted, so new ones such as `.dev` or `.fit` never need a code change.
 */

const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
const HOST_PATTERN = new RegExp(`^(?=.{1,190}$)${LABEL}(?:\\.${LABEL})+$`)

/** Separators accepted when several domains are typed or pasted at once. */
export const DOMAIN_DELIMITER = /[\s,;]+/

function toAscii(host: string): string {
  if (!/[^\x20-\x7e]/.test(host)) return host
  try {
    return new URL(`http://${host}`).hostname
  } catch {
    return ''
  }
}

/** Normalised host, or null when the input is not a valid host name. */
export function normalizeHost(input: string): string | null {
  let host = input.trim().toLowerCase()
  if (!host) return null
  if (host.includes('://')) {
    try {
      host = new URL(host).hostname
    } catch {
      return null
    }
  }
  host = (host.split('/')[0] ?? '').replace(/\.+$/, '').replace(/:\d+$/, '')
  host = toAscii(host)
  if (host === 'localhost') return host
  return HOST_PATTERN.test(host) ? host : null
}

export interface DomainEntry {
  host: string
  include_subdomains: boolean
}

/**
 * Parses one domain as typed by a person. A leading `*.` is the notation the CLI and the docs use
 * for "this domain and its subdomains". Returns null when the host part is invalid.
 */
export function parseDomain(input: string): DomainEntry | null {
  const raw = input.trim()
  const wildcard = raw.startsWith('*.')
  const host = normalizeHost(wildcard ? raw.slice(2) : raw)
  return host ? { host, include_subdomains: wildcard } : null
}

/** How a domain entry is written back in a tag input (`*.example.com` for subdomains). */
export function formatDomain(entry: DomainEntry): string {
  return entry.include_subdomains ? `*.${entry.host}` : entry.host
}

/** Splits text that may hold several domains (pasted lists, "a.com, b.com"). */
export function splitDomains(text: string): string[] {
  return text.split(DOMAIN_DELIMITER).map(s => s.trim()).filter(Boolean)
}

/**
 * Turns the API's per-entry validation errors (`domains.1.host`, `domains.1`, `domains`) into one
 * message for the domains field, naming the offending entries.
 */
export function domainFieldErrors(
  errors: Record<string, string[]>,
  entries: string[]
): { domains: string | null, rest: { name: string, message: string }[], invalidIndexes: number[] } {
  const messages: string[] = []
  const invalidIndexes: number[] = []
  const rest: { name: string, message: string }[] = []
  for (const [name, list] of Object.entries(errors)) {
    const match = /^domains(?:\.(\d+))?(?:\.\w+)?$/.exec(name)
    if (!match) {
      rest.push({ name, message: list[0] ?? '' })
      continue
    }
    const message = list[0] ?? ''
    if (match[1] === undefined) {
      messages.push(message)
      continue
    }
    const index = Number(match[1])
    invalidIndexes.push(index)
    const entry = entries[index]
    messages.push(entry ? `${entry}: ${message}` : message)
  }
  return { domains: messages.length ? messages.join(' ') : null, rest, invalidIndexes }
}
