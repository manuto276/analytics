// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

type Messages = { [key: string]: string | Messages }

const APP = fileURLToPath(new URL('../../app', import.meta.url))
const en = JSON.parse(readFileSync(fileURLToPath(new URL('../../i18n/locales/en.json', import.meta.url)), 'utf8')) as Messages

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) return files(path)
    return /\.(vue|ts)$/.test(path) && !path.endsWith('api.d.ts') ? [path] : []
  })
}

function has(key: string): boolean {
  let node: string | Messages | undefined = en
  for (const part of key.split('.')) {
    if (!node || typeof node === 'string') return false
    node = node[part]
  }
  return typeof node === 'string'
}

describe('i18n usage', () => {
  it('every static t() key exists in en.json', () => {
    const missing = new Set<string>()
    for (const file of files(APP)) {
      const source = readFileSync(file, 'utf8')
      for (const match of source.matchAll(/\bt\('([a-zA-Z0-9_.]+)'/g)) {
        if (!has(match[1]!)) missing.add(match[1]!)
      }
      for (const match of source.matchAll(/label: '((?:columns|metrics)\.[a-zA-Z_]+)'/g)) {
        if (!has(match[1]!)) missing.add(match[1]!)
      }
    }
    expect([...missing]).toEqual([])
  })
})
