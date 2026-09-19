import { describe, expect, it } from 'vitest'
import { domainFieldErrors, formatDomain, normalizeHost, parseDomain, splitDomains } from '~/utils/domains'

describe('normalizeHost (mirror of DomainMatcher::normalizeHost)', () => {
  it.each([
    ['example.com', 'example.com'],
    ['  WWW.Example.COM  ', 'www.example.com'],
    ['https://www.example.com/pricing?a=1', 'www.example.com'],
    ['example.com:8080', 'example.com'],
    ['example.com/path', 'example.com'],
    ['example.com.', 'example.com'],
    ['frascella.dev', 'frascella.dev'],
    ['skeda.fit', 'skeda.fit'],
    ['shop.example.photography', 'shop.example.photography'],
    ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
    ['bücher.example', 'xn--bcher-kva.example'],
    ['localhost', 'localhost']
  ])('accepts %s', (input, expected) => {
    expect(normalizeHost(input)).toBe(expected)
  })

  it.each(['', '   ', 'example', 'bad_host.com', '-example.com', 'example-.com', 'exa mple.com', 'a..b.com', '*.example.com', 'http://', `${'a'.repeat(64)}.com`])(
    'rejects %j',
    (input) => {
      expect(normalizeHost(input)).toBeNull()
    }
  )
})

describe('parseDomain', () => {
  it('reads a leading *. as "with subdomains"', () => {
    expect(parseDomain('*.Frascella.dev')).toEqual({ host: 'frascella.dev', include_subdomains: true })
    expect(parseDomain('skeda.fit')).toEqual({ host: 'skeda.fit', include_subdomains: false })
    expect(parseDomain('*.')).toBeNull()
    expect(parseDomain('*.*.example.com')).toBeNull()
  })

  it('formats an entry back to the typed notation', () => {
    expect(formatDomain({ host: 'frascella.dev', include_subdomains: true })).toBe('*.frascella.dev')
    expect(formatDomain({ host: 'skeda.fit', include_subdomains: false })).toBe('skeda.fit')
  })
})

describe('splitDomains', () => {
  it('splits on commas, semicolons and whitespace', () => {
    expect(splitDomains(' a.com, b.com;c.com\n\td.com  ,')).toEqual(['a.com', 'b.com', 'c.com', 'd.com'])
    expect(splitDomains('')).toEqual([])
  })
})

describe('domainFieldErrors', () => {
  it('names the offending entries and keeps other fields apart', () => {
    const result = domainFieldErrors({
      'domains.1.host': ['Must be a valid host name.'],
      'domains.3': ['Must be an object with host and include_subdomains.'],
      'name': ['Required.']
    }, ['a.com', '*.b_c.com'])
    expect(result.domains).toBe('*.b_c.com: Must be a valid host name. Must be an object with host and include_subdomains.')
    expect(result.invalidIndexes).toEqual([1, 3])
    expect(result.rest).toEqual([{ name: 'name', message: 'Required.' }])
  })

  it('passes a list-level error through', () => {
    expect(domainFieldErrors({ domains: ['Must be a list of 1 to 50 domains.'] }, []).domains).toBe('Must be a list of 1 to 50 domains.')
    expect(domainFieldErrors({}, []).domains).toBeNull()
  })
})
