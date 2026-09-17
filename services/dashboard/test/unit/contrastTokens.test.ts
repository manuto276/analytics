// @vitest-environment node
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { contrastRatio, WCAG_AA_NORMAL } from '~/utils/contrast'

const mainCss = readFileSync(fileURLToPath(new URL('../../app/assets/css/main.css', import.meta.url)), 'utf8')

/** The brand palette as declared in app/assets/css/main.css. */
function green(shade: number): string {
  const match = new RegExp(`--color-green-${shade}:\\s*(#[0-9a-fA-F]{6})`).exec(mainCss)
  if (!match) throw new Error(`--color-green-${shade} is not declared in main.css`)
  return match[1]!
}

/** The shade main.css pins --ui-primary to in light mode. */
function lightPrimaryShade(): number {
  const match = /--ui-primary:\s*var\(--ui-color-primary-(\d+)\)/.exec(mainCss)
  if (!match) throw new Error('main.css no longer overrides --ui-primary for light mode')
  return Number(match[1])
}

/**
 * Nuxt UI text tokens resolve to the neutral palette (zinc in app.config.ts):
 * light  text-muted → zinc-500, text-toned → zinc-600 on bg #fff / bg-elevated zinc-100
 * dark   text-muted → zinc-400, text-toned → zinc-300 on bg zinc-900 / bg-elevated zinc-800
 * Muted text on an elevated surface (tab lists, the auth footer) is below AA,
 * which is why those places use text-toned.
 */
const zinc = {
  100: '#f4f4f5',
  300: '#d4d4d8',
  400: '#9f9fa9',
  500: '#71717b',
  600: '#52525c',
  800: '#27272a',
  900: '#18181b'
} as const

const white = '#ffffff'

describe('text token contrast', () => {
  it('muted text on an elevated surface fails AA, which is the bug we fixed', () => {
    expect(contrastRatio(zinc[500], zinc[100])).toBeLessThan(WCAG_AA_NORMAL)
  })

  it.each([
    ['light, on background', zinc[600], white],
    ['light, on elevated', zinc[600], zinc[100]],
    ['dark, on background', zinc[300], zinc[900]],
    ['dark, on elevated', zinc[300], zinc[800]]
  ])('toned text passes AA (%s)', (_name, fg, bg) => {
    expect(contrastRatio(fg, bg)).toBeGreaterThanOrEqual(WCAG_AA_NORMAL)
  })
})

describe('inverted text on solid surfaces', () => {
  it('the selected tab pill (neutral) passes AA in both modes', () => {
    // color: 'neutral' + variant: 'pill' → bg-inverted with text-inverted.
    expect(contrastRatio(white, zinc[900])).toBeGreaterThanOrEqual(WCAG_AA_NORMAL)
    expect(contrastRatio(zinc[900], white)).toBeGreaterThanOrEqual(WCAG_AA_NORMAL)
  })

  it('white text on the default brand green fails, which is why light mode darkens it', () => {
    expect(contrastRatio(white, green(500))).toBeLessThan(WCAG_AA_NORMAL)
  })

  it('solid primary surfaces pass AA with the pinned light-mode shade', () => {
    const shade = lightPrimaryShade()
    expect(shade).toBeGreaterThanOrEqual(600)
    // Buttons, badges and other solid primary surfaces write with text-inverted (white in light mode).
    expect(contrastRatio(white, green(shade))).toBeGreaterThanOrEqual(WCAG_AA_NORMAL)
  })

  it('dark mode keeps the lighter green, where inverted text is near-black', () => {
    expect(contrastRatio(zinc[900], green(400))).toBeGreaterThanOrEqual(WCAG_AA_NORMAL)
  })
})
