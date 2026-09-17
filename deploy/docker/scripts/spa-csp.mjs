// Copies nothing and changes nothing on disk except the generated CSP config:
// it reads the inline <script> blocks of the generated SPA and writes the sha256
// hashes the backend sends in `script-src` (services/api reads config/csp.php).
//
// Usage: node spa-csp.mjs <spa-public-dir> <output-csp.php>
//
// Kept in deploy/docker so a package build does not depend on the dashboard
// exposing a `generate:api` script (services/dashboard/scripts/csp-hashes.mjs
// does the same for local development).
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'

const [publicDirArg, outputArg] = process.argv.slice(2)
if (!publicDirArg || !outputArg) {
  console.error('Usage: node spa-csp.mjs <spa-public-dir> <output-csp.php>')
  process.exit(2)
}
const publicDir = resolve(publicDirArg)
const outputFile = resolve(outputArg)

export function inlineScriptHashes(html) {
  const hashes = []
  for (const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    const [, attrs, content] = match
    if (/\bsrc\s*=/i.test(attrs) || content.length === 0) continue
    hashes.push(`sha256-${createHash('sha256').update(content, 'utf8').digest('base64')}`)
  }
  return hashes
}

const entries = ['index.html', '200.html'].map(f => join(publicDir, f)).filter(f => existsSync(f))
if (entries.length === 0) {
  console.error(`No index.html or 200.html in ${publicDir}: the dashboard build produced no SPA.`)
  process.exit(1)
}

const hashes = [...new Set(entries.flatMap(f => inlineScriptHashes(readFileSync(f, 'utf8'))))].sort()
const body = `<?php

declare(strict_types=1);

// Generated at build time by deploy/docker/scripts/spa-csp.mjs. Do not edit by hand.
return [
    'script_hashes' => [
${hashes.map(h => `        '${h}',`).join('\n')}
    ],
];
`
mkdirSync(dirname(outputFile), { recursive: true })
writeFileSync(outputFile, body)
console.log(`spa-csp: ${hashes.length} inline script hash(es) from ${entries.length} file(s) -> ${outputFile}`)
