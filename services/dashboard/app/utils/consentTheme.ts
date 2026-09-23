/**
 * Consent banner theme v2 (docs/api/consent-theme.v2.schema.json) on the dashboard side: the complete
 * shape the editor works on, its defaults, and the JSON import/export used to reuse a theme across
 * sites. The server stays the authority (ranges, contrast, custom CSS): the schema here mirrors
 * docs/api/consent-theme.v2.schema.json so an import fails early and names the field
 * (test/unit/consentTheme.test.ts keeps the two in step).
 */
import * as z from 'zod'
import type { ConsentConfigInput } from '~/types'

export const DESKTOP_POSITIONS = ['bottom', 'bottom-left', 'bottom-right', 'top', 'center'] as const
export const MOBILE_POSITIONS = ['bottom', 'top', 'center', 'sheet'] as const
export const BUTTON_LAYOUTS = ['row', 'stack'] as const
export const SHADOWS = ['none', 'sm', 'md', 'lg'] as const
export const REOPEN_ICONS = ['cookie', 'shield', 'fingerprint', 'settings'] as const
export const REOPEN_SIZES = ['sm', 'md', 'lg'] as const
export const REOPEN_VARIANTS = ['text', 'icon', 'icon-text', 'hidden'] as const
export const REOPEN_POSITIONS = ['bottom-left', 'bottom-right'] as const

/** Inclusive integer ranges, by path inside the theme (ConsentThemeV2::RANGES on the server). */
export const RANGES = {
  'font.size': [12, 20],
  'shape.radius': [0, 32],
  'shape.buttonRadius': [0, 999],
  'spacing.padding': [8, 48],
  'spacing.gap': [0, 32],
  'border.width': [0, 4],
  'layout.breakpoint': [480, 1024],
  'layout.desktop.maxWidth': [280, 1200],
  'layout.desktop.offset': [0, 64],
  'layout.mobile.offset': [0, 32],
  'reopen.desktop.offset': [0, 64],
  'reopen.mobile.offset': [0, 64]
} as const satisfies Record<string, readonly [number, number]>
export const LINE_HEIGHT: readonly [number, number] = [1, 2]
export const CSS_MAX_BYTES = 8192
export const FONT_FAMILY_MAX = 200
export const THEME_SCHEMA_ID = 'https://github.com/manuto276/analytics/docs/api/consent-theme.v2.schema.json'

export type DesktopPosition = typeof DESKTOP_POSITIONS[number]
export type MobilePosition = typeof MOBILE_POSITIONS[number]
export type ButtonLayout = typeof BUTTON_LAYOUTS[number]
export type ReopenVariant = typeof REOPEN_VARIANTS[number]
export type ReopenPosition = typeof REOPEN_POSITIONS[number]

export interface ReopenPlacement {
  variant: ReopenVariant
  position: ReopenPosition
  offset: number
}

/** A complete v2 theme, as the server stores it and returns it in `theme_v2`. */
export interface ThemeV2 {
  colors: { background: string, text: string, accent: string, accentText: string, border: string, link: string, backdrop: string | null }
  font: { family: string, size: number | null, lineHeight: number }
  shape: { radius: number, buttonRadius: number }
  spacing: { padding: number, gap: number }
  border: { width: number }
  shadow: typeof SHADOWS[number]
  layout: {
    breakpoint: number
    desktop: { position: DesktopPosition, maxWidth: number, offset: number, buttons: ButtonLayout }
    mobile: { position: MobilePosition, offset: number, buttons: ButtonLayout }
  }
  reopen: {
    icon: typeof REOPEN_ICONS[number]
    size: typeof REOPEN_SIZES[number]
    background: string | null
    text: string | null
    desktop: ReopenPlacement
    mobile: ReopenPlacement
  }
  css: string
}

export function defaultTheme(): ThemeV2 {
  return {
    colors: { background: '#ffffff', text: '#111827', accent: '#1d4ed8', accentText: '#ffffff', border: '#e5e7eb', link: '#1d4ed8', backdrop: null },
    font: { family: 'inherit', size: null, lineHeight: 1.5 },
    shape: { radius: 8, buttonRadius: 8 },
    spacing: { padding: 16, gap: 8 },
    border: { width: 0 },
    shadow: 'md',
    layout: {
      breakpoint: 640,
      desktop: { position: 'bottom', maxWidth: 576, offset: 16, buttons: 'row' },
      mobile: { position: 'bottom', offset: 16, buttons: 'row' }
    },
    reopen: {
      icon: 'cookie',
      size: 'md',
      background: null,
      text: null,
      desktop: { variant: 'text', position: 'bottom-left', offset: 16 },
      mobile: { variant: 'icon', position: 'bottom-left', offset: 16 }
    },
    css: ''
  }
}

type Plain = Record<string, unknown>
const isPlain = (value: unknown): value is Plain => !!value && typeof value === 'object' && !Array.isArray(value)

/** `base` with every key of `patch` that exists in `base` (recursively) and has the same kind of value. */
function merge<T>(base: T, patch: unknown): T {
  if (!isPlain(base) || !isPlain(patch)) return base
  const out: Plain = { ...base }
  for (const [key, value] of Object.entries(base)) {
    if (!(key in patch)) continue
    const next = patch[key]
    if (isPlain(value)) out[key] = merge(value, next)
    else if (next === null || typeof next === typeof value || value === null) out[key] = next
  }
  return out as T
}

/**
 * The complete theme for a partial one (a `theme_v2` from the API, or an imported file): missing
 * fields take the defaults. Values are not range-checked here — the server does that on save.
 */
export function completeTheme(partial: unknown): ThemeV2 {
  return merge(defaultTheme(), partial)
}

export function cloneTheme(theme: ThemeV2): ThemeV2 {
  return JSON.parse(JSON.stringify(theme)) as ThemeV2
}

/* ---------------------------------------------------------------- JSON schema (import) ---------- */

const HEX = /^#[0-9a-fA-F]{6}$/
const BACKDROP = /^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/
const FAMILY_NAME = String.raw`("[A-Za-z0-9 _-]+"|'[A-Za-z0-9 _-]+'|-?[A-Za-z][A-Za-z0-9 _-]*)`
export const FONT_FAMILY = new RegExp(String.raw`^(inherit|system|\s*${FAMILY_NAME}\s*(,\s*${FAMILY_NAME}\s*)*)$`)

const hex = z.string().regex(HEX, 'Must be a colour like #1d4ed8')
const int = (range: readonly [number, number]) => z.number().int().min(range[0]).max(range[1])
const placement = (offset: readonly [number, number]) => z.strictObject({
  variant: z.enum(REOPEN_VARIANTS).optional(),
  position: z.enum(REOPEN_POSITIONS).optional(),
  offset: int(offset).optional()
})

/** Theme v2 as accepted on write: every field optional, no unknown keys (additionalProperties: false). */
export const themeV2Schema = z.strictObject({
  colors: z.strictObject({
    background: hex.optional(),
    text: hex.optional(),
    accent: hex.optional(),
    accentText: hex.optional(),
    border: hex.optional(),
    link: hex.optional(),
    backdrop: z.string().regex(BACKDROP, 'Must be a colour like #00000080').nullable().optional()
  }).optional(),
  font: z.strictObject({
    family: z.string().max(FONT_FAMILY_MAX).regex(FONT_FAMILY, 'Use inherit, system or a list of font family names').optional(),
    size: int(RANGES['font.size']).nullable().optional(),
    lineHeight: z.number().min(LINE_HEIGHT[0]).max(LINE_HEIGHT[1]).optional()
  }).optional(),
  shape: z.strictObject({
    radius: int(RANGES['shape.radius']).optional(),
    buttonRadius: int(RANGES['shape.buttonRadius']).optional()
  }).optional(),
  spacing: z.strictObject({
    padding: int(RANGES['spacing.padding']).optional(),
    gap: int(RANGES['spacing.gap']).optional()
  }).optional(),
  border: z.strictObject({ width: int(RANGES['border.width']).optional() }).optional(),
  shadow: z.enum(SHADOWS).optional(),
  layout: z.strictObject({
    breakpoint: int(RANGES['layout.breakpoint']).optional(),
    desktop: z.strictObject({
      position: z.enum(DESKTOP_POSITIONS).optional(),
      maxWidth: int(RANGES['layout.desktop.maxWidth']).optional(),
      offset: int(RANGES['layout.desktop.offset']).optional(),
      buttons: z.enum(BUTTON_LAYOUTS).optional()
    }).optional(),
    mobile: z.strictObject({
      position: z.enum(MOBILE_POSITIONS).optional(),
      offset: int(RANGES['layout.mobile.offset']).optional(),
      buttons: z.enum(BUTTON_LAYOUTS).optional()
    }).optional()
  }).optional(),
  reopen: z.strictObject({
    icon: z.enum(REOPEN_ICONS).optional(),
    size: z.enum(REOPEN_SIZES).optional(),
    background: hex.nullable().optional(),
    text: hex.nullable().optional(),
    desktop: placement(RANGES['reopen.desktop.offset']).optional(),
    mobile: placement(RANGES['reopen.mobile.offset']).optional()
  }).optional(),
  css: z.string().refine(css => new TextEncoder().encode(css).length <= CSS_MAX_BYTES, `Must be at most ${CSS_MAX_BYTES} bytes`).optional()
})

export type ThemeImport
  = | { ok: true, theme: ThemeV2 }
    | { ok: false, reason: 'json', message: string }
    | { ok: false, reason: 'schema', issues: string[] }

/**
 * Parse a theme file. `$schema` (written by {@link exportTheme}) is ignored; everything else must
 * match the v2 schema. Missing fields take the defaults, so a file may hold only what it changes.
 */
export function importTheme(text: string): ThemeImport {
  let data: unknown
  try {
    data = JSON.parse(text)
  } catch (error) {
    return { ok: false, reason: 'json', message: (error as Error).message }
  }
  if (isPlain(data) && '$schema' in data) {
    const { $schema: _ignored, ...rest } = data
    data = rest
  }
  const result = themeV2Schema.safeParse(data)
  if (!result.success) {
    return {
      ok: false,
      reason: 'schema',
      issues: result.error.issues.map(issue => `${issue.path.length ? issue.path.join('.') : '(theme)'}: ${issue.message}`)
    }
  }
  return { ok: true, theme: completeTheme(result.data) }
}

/** The theme as a JSON file another site can import (with `$schema` for editors that validate). */
export function exportTheme(theme: ThemeV2): string {
  return `${JSON.stringify({ $schema: THEME_SCHEMA_ID, ...theme }, null, 2)}\n`
}

/** `Line L, column C: …` → { line, column } (both 1-based), as the server reports custom CSS errors. */
export function cssErrorPosition(message: string): { line: number, column: number } | null {
  const match = /^Line (\d+), column (\d+):/.exec(message)
  return match ? { line: Number(match[1]), column: Number(match[2]) } : null
}

/** Character offset of a 1-based line and column in `text`. */
export function offsetOf(text: string, line: number, column: number): number {
  const lines = text.split('\n')
  let offset = 0
  for (let i = 0; i < Math.min(line - 1, lines.length); i++) offset += lines[i]!.length + 1
  return Math.min(text.length, offset + Math.max(0, column - 1))
}

/** The consent configuration as the editor holds it: always a complete v2 theme (never v1). */
export type ConsentDraft = Omit<ConsentConfigInput, 'theme'> & { theme: ThemeV2 }
