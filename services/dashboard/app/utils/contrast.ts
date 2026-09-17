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

export interface ThemeContrast {
  text: number
  action: number
  textOk: boolean
  actionOk: boolean
  ok: boolean
}

/** Banner text on background (fg/bg) and button text on accent (acf/ac) must both reach 4.5:1. */
export function themeContrast(theme: { bg: string, fg: string, ac: string, acf: string }): ThemeContrast {
  const text = contrastRatio(theme.fg, theme.bg)
  const action = contrastRatio(theme.acf, theme.ac)
  const textOk = !Number.isNaN(text) && text >= WCAG_AA_NORMAL
  const actionOk = !Number.isNaN(action) && action >= WCAG_AA_NORMAL
  return { text, action, textOk, actionOk, ok: textOk && actionOk }
}
