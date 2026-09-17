import { createHash } from 'node:crypto'
import { describe, expect, it } from 'vitest'
// @ts-expect-error plain ESM script without types
import { inlineScriptHashes, toPhp } from '../../scripts/csp-hashes.mjs'

describe('csp hashes', () => {
  it('hashes inline scripts only', () => {
    const html = '<script type="module" src="/_nuxt/a.js"></script><script>window.a=1</script><script type="importmap">{"imports":{}}</script><script></script>'
    const expected = (s: string) => `sha256-${createHash('sha256').update(s).digest('base64')}`
    expect(inlineScriptHashes(html)).toEqual([expected('window.a=1'), expected('{"imports":{}}')])
  })

  it('renders a PHP config file', () => {
    const php = toPhp(['sha256-abc'])
    expect(php).toContain('\'script_hashes\' => [')
    expect(php).toContain('\'sha256-abc\',')
    expect(php.startsWith('<?php')).toBe(true)
  })
})
