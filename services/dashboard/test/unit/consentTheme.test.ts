// @vitest-environment node
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  BUTTON_LAYOUTS, CSS_MAX_BYTES, DESKTOP_POSITIONS, FONT_FAMILY_MAX, LINE_HEIGHT, MOBILE_POSITIONS, RANGES, REOPEN_ICONS,
  REOPEN_POSITIONS, REOPEN_SIZES, REOPEN_VARIANTS, SHADOWS, THEME_SCHEMA_ID, completeTheme, cssErrorPosition, defaultTheme,
  exportTheme, importTheme, offsetOf
} from '~/utils/consentTheme'
import { fitScale } from '~/utils/bannerPreview'

type Node = { [key: string]: unknown, properties?: Record<string, Node>, $ref?: string, enum?: unknown[], default?: unknown, minimum?: number, maximum?: number, maxLength?: number }

const schema = JSON.parse(readFileSync(fileURLToPath(new URL('../../../../docs/api/consent-theme.v2.schema.json', import.meta.url)), 'utf8')) as Node & { $defs: Record<string, Node> }

function node(path: string): Node {
  let current: Node = schema
  for (const part of path.split('.')) {
    current = current.properties![part]!
    if (current.$ref) current = { ...schema.$defs[current.$ref.split('/').pop()!]!, ...current }
  }
  return current
}

describe('theme v2 mirrors docs/api/consent-theme.v2.schema.json', () => {
  it('has the same identifier', () => {
    expect(THEME_SCHEMA_ID).toBe(schema.$id)
  })

  it('has the same enums', () => {
    expect(node('shadow').enum).toEqual([...SHADOWS])
    expect(node('layout.desktop.position').enum).toEqual([...DESKTOP_POSITIONS])
    expect(node('layout.mobile.position').enum).toEqual([...MOBILE_POSITIONS])
    expect(node('layout.desktop.buttons').enum).toEqual([...BUTTON_LAYOUTS])
    expect(node('reopen.icon').enum).toEqual([...REOPEN_ICONS])
    expect(node('reopen.size').enum).toEqual([...REOPEN_SIZES])
    expect(node('reopen.desktop.variant').enum).toEqual([...REOPEN_VARIANTS])
    expect(node('reopen.mobile.position').enum).toEqual([...REOPEN_POSITIONS])
  })

  it('has the same ranges and limits', () => {
    for (const [path, [min, max]] of Object.entries(RANGES)) {
      expect([node(path).minimum, node(path).maximum], path).toEqual([min, max])
    }
    expect([node('font.lineHeight').minimum, node('font.lineHeight').maximum]).toEqual([...LINE_HEIGHT])
    expect(node('css').maxLength).toBe(CSS_MAX_BYTES)
    expect(node('font.family').maxLength).toBe(FONT_FAMILY_MAX)
  })

  it('has the same defaults', () => {
    const d = defaultTheme()
    const leaves: [string, unknown][] = [
      ['colors.background', d.colors.background], ['colors.text', d.colors.text], ['colors.accent', d.colors.accent],
      ['colors.accentText', d.colors.accentText], ['colors.border', d.colors.border], ['colors.link', d.colors.link],
      ['colors.backdrop', d.colors.backdrop], ['font.family', d.font.family], ['font.size', d.font.size], ['font.lineHeight', d.font.lineHeight],
      ['shape.radius', d.shape.radius], ['shape.buttonRadius', d.shape.buttonRadius], ['spacing.padding', d.spacing.padding],
      ['spacing.gap', d.spacing.gap], ['border.width', d.border.width], ['shadow', d.shadow], ['layout.breakpoint', d.layout.breakpoint],
      ['layout.desktop.position', d.layout.desktop.position], ['layout.desktop.maxWidth', d.layout.desktop.maxWidth],
      ['layout.desktop.offset', d.layout.desktop.offset], ['layout.mobile.position', d.layout.mobile.position],
      ['layout.mobile.offset', d.layout.mobile.offset], ['reopen.icon', d.reopen.icon], ['reopen.size', d.reopen.size],
      ['reopen.background', d.reopen.background], ['reopen.text', d.reopen.text], ['reopen.desktop', d.reopen.desktop],
      ['reopen.mobile', d.reopen.mobile], ['css', d.css]
    ]
    for (const [path, value] of leaves) expect(node(path).default, path).toEqual(value)
  })
})

describe('completeTheme', () => {
  it('fills what is missing and ignores unknown or mistyped fields', () => {
    const theme = completeTheme({ colors: { accent: '#0f766e', wat: 1 }, shape: { radius: 'big' }, font: { size: 14 }, extra: true })
    expect(theme.colors.accent).toBe('#0f766e')
    expect(theme.colors).not.toHaveProperty('wat')
    expect(theme.shape.radius).toBe(8)
    expect(theme.font.size).toBe(14)
    expect(theme).not.toHaveProperty('extra')
    expect(completeTheme(null)).toEqual(defaultTheme())
  })
})

describe('JSON import and export', () => {
  it('round-trips a theme through export and import', () => {
    const theme = completeTheme({
      colors: { background: '#111827', text: '#f9fafb', link: '#93c5fd', backdrop: '#00000080' },
      font: { family: '"Inter", sans-serif', size: 15, lineHeight: 1.6 },
      layout: { breakpoint: 720, mobile: { position: 'sheet', buttons: 'stack' } },
      reopen: { icon: 'shield', background: '#0f766e', text: '#ffffff', mobile: { variant: 'hidden', position: 'bottom-right', offset: 8 } },
      css: '.button {\n  font-weight: 700;\n}'
    })
    const text = exportTheme(theme)
    expect(JSON.parse(text).$schema).toBe(THEME_SCHEMA_ID)
    expect(importTheme(text)).toEqual({ ok: true, theme })
  })

  it('accepts a partial theme', () => {
    expect(importTheme('{"shadow":"lg"}')).toEqual({ ok: true, theme: completeTheme({ shadow: 'lg' }) })
    expect(importTheme('{}')).toEqual({ ok: true, theme: defaultTheme() })
  })

  it('reports text that is not JSON', () => {
    const result = importTheme('{"colors": ')
    expect(result.ok).toBe(false)
    expect(result).toMatchObject({ reason: 'json' })
  })

  it('reports every field that breaks the schema', () => {
    const result = importTheme(JSON.stringify({
      bg: '#ffffff',
      colors: { text: 'red', backdrop: '#0000' },
      font: { family: 'x;}*{display:none', size: 40 },
      layout: { breakpoint: 100, desktop: { position: 'left' } },
      reopen: { icon: 'skull', desktop: { variant: 'blink' } },
      css: 'x'.repeat(CSS_MAX_BYTES + 1)
    }))
    expect(result.ok).toBe(false)
    if (result.ok || result.reason !== 'schema') throw new Error('expected schema issues')
    const paths = result.issues.map(issue => issue.split(':')[0])
    for (const path of ['colors.text', 'colors.backdrop', 'font.family', 'font.size', 'layout.breakpoint', 'layout.desktop.position', 'reopen.icon', 'reopen.desktop.variant', 'css']) {
      expect(paths, path).toContain(path)
    }
    expect(result.issues.join('\n')).toContain('bg')
  })

  it('refuses a v1 theme and a non-object', () => {
    expect(importTheme('{"bg":"#ffffff","fg":"#111827"}').ok).toBe(false)
    expect(importTheme('[1,2]').ok).toBe(false)
    expect(importTheme('"theme"').ok).toBe(false)
  })
})

describe('custom CSS error positions', () => {
  it('reads the server format and finds the offset', () => {
    expect(cssErrorPosition('Line 4, column 3: The property "display" is not allowed')).toEqual({ line: 4, column: 3 })
    expect(cssErrorPosition('Must be at most 8192 bytes.')).toBeNull()
    const css = '.banner {}\n.title {\n  display: none;\n}'
    expect(css.slice(offsetOf(css, 3, 3))).toMatch(/^display/)
    expect(offsetOf(css, 99, 1)).toBeLessThanOrEqual(css.length)
  })
})

describe('preview viewport', () => {
  it('scales a viewport down to fit, never up', () => {
    expect(fitScale({ width: 1280, height: 800 }, { width: 640, height: 0 })).toBe(0.5)
    expect(fitScale({ width: 390, height: 844 }, { width: 600, height: 422 })).toBe(0.5)
    expect(fitScale({ width: 390, height: 844 }, { width: 2000, height: 2000 })).toBe(1)
    expect(fitScale({ width: 390, height: 844 }, { width: 0, height: 0 })).toBe(1)
  })
})
