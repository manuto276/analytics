/** WCAG 2.x contrast helpers for the consent banner theme. */

export const WCAG_AA_NORMAL = 4.5

export function isHexColor(value: string): boolean {
  return /^#[0-9a-f]{6}$/i.test(value)
}

function channel(value: number): number {
  const c = value / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

export function relativeLuminance(hex: string): number {
  if (!isHexColor(hex)) return Number.NaN
  const r = Number.parseInt(hex.slice(1, 3), 16)
  const g = Number.parseInt(hex.slice(3, 5), 16)
  const b = Number.parseInt(hex.slice(5, 7), 16)
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

export function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)
  if (Number.isNaN(la) || Number.isNaN(lb)) return Number.NaN
  const [hi, lo] = la > lb ? [la, lb] : [lb, la]
  return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100
}

export function meetsContrast(a: string, b: string, minimum = WCAG_AA_NORMAL): boolean {
  const ratio = contrastRatio(a, b)
  return !Number.isNaN(ratio) && ratio >= minimum
}

export type ContrastPairKey = 'text' | 'accentText' | 'link' | 'reopen'

export interface ContrastPair {
  key: ContrastPairKey
  /** Field the server reports the failure under (theme.colors.text…). */
  field: string
  foreground: string
  background: string
  ratio: number
  ok: boolean
}

export interface ThemeContrast {
  pairs: ContrastPair[]
  ok: boolean
}

interface ContrastTheme {
  colors: { background: string, text: string, accent: string, accentText: string, link: string }
  reopen: { background: string | null, text: string | null }
}

/**
 * The four pairs the server enforces at 4.5:1 (ThemeValidator): text/background, button
 * text/button, link/background, and the reopen button's text/background (which default to the
 * button colours).
 */
export function themeContrast(theme: ContrastTheme): ThemeContrast {
  const c = theme.colors
  const pair = (key: ContrastPairKey, field: string, foreground: string, background: string): ContrastPair => {
    const ratio = contrastRatio(foreground, background)
    return { key, field, foreground, background, ratio, ok: !Number.isNaN(ratio) && ratio >= WCAG_AA_NORMAL }
  }
  const pairs = [
    pair('text', 'theme.colors.text', c.text, c.background),
    pair('accentText', 'theme.colors.accentText', c.accentText, c.accent),
    pair('link', 'theme.colors.link', c.link, c.background),
    pair('reopen', 'theme.reopen.text', theme.reopen.text ?? c.accentText, theme.reopen.background ?? c.accent)
  ]
  return { pairs, ok: pairs.every(p => p.ok) }
}
