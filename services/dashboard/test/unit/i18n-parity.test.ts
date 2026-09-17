// @vitest-environment node
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

type Messages = { [key: string]: string | Messages }

function load(locale: string): Messages {
  const file = fileURLToPath(new URL(`../../i18n/locales/${locale}.json`, import.meta.url))
  return JSON.parse(readFileSync(file, 'utf8')) as Messages
}

function flatten(messages: Messages, prefix = ''): Array<[string, string | Messages]> {
  return Object.entries(messages).flatMap(([key, value]): Array<[string, string | Messages]> => {
    const path = prefix ? `${prefix}.${key}` : key
    return value !== null && typeof value === 'object' ? flatten(value, path) : [[path, value]]
  })
}

const locales = { en: load('en'), it: load('it') }

describe('i18n parity', () => {
  it('en and it define identical key sets', () => {
    const keys = (m: Messages) => flatten(m).map(([k]) => k).sort()
    expect(keys(locales.it)).toEqual(keys(locales.en))
  })

  it('has no empty translations', () => {
    for (const messages of Object.values(locales)) {
      for (const [key, value] of flatten(messages)) {
        expect(typeof value === 'string' && value.trim() !== '', key).toBe(true)
      }
    }
  })

  it('defines the main navigation items', () => {
    expect(Object.keys(locales.en.nav as Messages)).toEqual(expect.arrayContaining([
      'overview', 'pages', 'sources', 'campaigns', 'audience', 'events',
      'goals', 'funnels', 'attribution', 'retention', 'realtime', 'settings'
    ]))
  })
})
