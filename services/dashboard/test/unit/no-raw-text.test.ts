// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { parse } from 'vue/compiler-sfc'

/**
 * Every user-visible string must go through vue-i18n. This scans .vue templates for
 * text nodes and static text attributes that contain letters.
 */

const ROOT = fileURLToPath(new URL('../../app', import.meta.url))
const TEXT_ATTRIBUTES = new Set(['label', 'title', 'description', 'placeholder', 'aria-label', 'alt', 'text', 'empty'])

/** file (relative to app/) → allowed literal strings */
const ALLOWLIST: Record<string, string[]> = {}

interface AstNode {
  type: number
  content?: string | AstNode
  children?: AstNode[]
  branches?: AstNode[]
  props?: { type: number, name: string, value?: { content: string } }[]
  loc: { start: { line: number } }
}

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : []
  })
}

function findRawText(source: string): { line: number, text: string }[] {
  const { descriptor } = parse(source)
  const ast = descriptor.template?.ast as unknown as AstNode | undefined
  const found: { line: number, text: string }[] = []

  function visit(node: AstNode) {
    // 2 = TEXT
    if (node.type === 2 && typeof node.content === 'string' && /\p{L}/u.test(node.content)) {
      found.push({ line: node.loc.start.line, text: node.content.trim() })
    }
    // 1 = ELEMENT
    if (node.type === 1) {
      for (const prop of node.props ?? []) {
        // 6 = static ATTRIBUTE
        if (prop.type === 6 && TEXT_ATTRIBUTES.has(prop.name) && prop.value && /\p{L}/u.test(prop.value.content)) {
          found.push({ line: node.loc.start.line, text: `${prop.name}="${prop.value.content}"` })
        }
      }
    }
    for (const child of node.children ?? []) visit(child)
    for (const branch of node.branches ?? []) visit(branch)
  }

  if (ast) visit(ast)
  return found
}

describe('no raw text in templates', () => {
  it('detects raw text (self-check)', () => {
    const hits = findRawText('<template><div title="Hello"><p>Raw words</p><p>{{ t(\'ok\') }}</p><p v-if="a">Branch</p></div></template>')
    expect(hits.map(h => h.text)).toEqual(['title="Hello"', 'Raw words', 'Branch'])
  })

  it('templates only render translated strings', () => {
    const violations: string[] = []
    for (const file of vueFiles(ROOT)) {
      const rel = relative(ROOT, file)
      const allowed = ALLOWLIST[rel] ?? []
      for (const hit of findRawText(readFileSync(file, 'utf8'))) {
        if (!allowed.includes(hit.text)) violations.push(`${rel}:${hit.line} ${hit.text}`)
      }
    }
    expect(violations).toEqual([])
  })
})
