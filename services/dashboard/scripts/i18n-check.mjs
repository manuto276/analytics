// Fails when locale files do not share exactly the same key set.
import { readFileSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const dir = join(dirname(fileURLToPath(import.meta.url)), '..', 'i18n', 'locales')

function flatten(obj, prefix = '') {
  return Object.entries(obj).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key
    return value !== null && typeof value === 'object' ? flatten(value, path) : [path]
  })
}

const files = readdirSync(dir).filter(f => f.endsWith('.json')).sort()
const keys = Object.fromEntries(files.map(f => [f, new Set(flatten(JSON.parse(readFileSync(join(dir, f), 'utf8'))))]))
const all = new Set(Object.values(keys).flatMap(s => [...s]))

let failed = false
for (const [file, set] of Object.entries(keys)) {
  const missing = [...all].filter(k => !set.has(k))
  if (missing.length) {
    failed = true
    console.error(`${file} is missing: ${missing.join(', ')}`)
  }
}
if (failed) process.exit(1)
console.log(`i18n OK: ${files.join(', ')} share ${all.size} keys`)
