// Copies the generated SPA into services/api/public without touching the API front controller.
import { cpSync, existsSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const source = join(root, '.output/public')
const target = resolve(root, '../api/public')
const PROTECTED = new Set(['index.php', '.htaccess'])

if (!existsSync(source)) {
  console.error('Missing .output/public; run "pnpm generate" first.')
  process.exit(1)
}

// The consent preview runs the tracker's real banner module (copied by nuxt.config.ts); a SPA
// without it would ship a preview that cannot render.
if (!existsSync(join(source, '_preview/banner.js'))) {
  console.error('Missing .output/public/_preview/banner.js; run "pnpm build" in services/tracker, then "pnpm generate" again.')
  process.exit(1)
}

// Remove the previous build first, so hashed assets and routes that no longer
// exist do not pile up next to the front controller.
for (const entry of readdirSync(target)) {
  if (PROTECTED.has(entry)) continue
  rmSync(join(target, entry), { recursive: true, force: true })
}

for (const entry of readdirSync(source)) {
  if (PROTECTED.has(entry)) continue
  cpSync(join(source, entry), join(target, entry), { recursive: true, force: true, dereference: true })
}
console.log(`Copied ${source} -> ${target}`)
