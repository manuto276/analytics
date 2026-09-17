// Fails when app/types/api.d.ts is stale compared to docs/api/openapi.yaml.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const dir = mkdtempSync(join(tmpdir(), 'openapi-types-'))
const generated = join(dir, 'api.d.ts')

try {
  execFileSync(join(root, 'node_modules/.bin/openapi-typescript'), ['../../docs/api/openapi.yaml', '-o', generated], { cwd: root, stdio: 'pipe' })
  const fresh = readFileSync(generated, 'utf8')
  const current = readFileSync(join(root, 'app/types/api.d.ts'), 'utf8')
  if (fresh !== current) {
    console.error('app/types/api.d.ts is out of date. Run "pnpm openapi-types" and commit the result.')
    process.exit(1)
  }
  console.log('app/types/api.d.ts is up to date.')
} finally {
  rmSync(dir, { recursive: true, force: true })
}
