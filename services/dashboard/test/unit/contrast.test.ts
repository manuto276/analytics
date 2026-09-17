import { describe, expect, it } from 'vitest'
import { contrastRatio, isHexColor, meetsContrast, relativeLuminance, themeContrast } from '~/utils/contrast'

describe('contrast', () => {
  it('computes WCAG contrast ratios', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBe(21)
    expect(contrastRatio('#ffffff', '#ffffff')).toBe(1)
    expect(contrastRatio('#767676', '#ffffff')).toBeCloseTo(4.54, 2)
  })

  it('validates hex colours', () => {
    expect(isHexColor('#AABBCC')).toBe(true)
    expect(isHexColor('#abc')).toBe(false)
    expect(Number.isNaN(relativeLuminance('red'))).toBe(true)
    expect(Number.isNaN(contrastRatio('red', '#ffffff'))).toBe(true)
  })

  it('applies the 4.5:1 threshold', () => {
    expect(meetsContrast('#767676', '#ffffff')).toBe(true)
    expect(meetsContrast('#777777', '#ffffff')).toBe(false)
    expect(meetsContrast('bad', '#ffffff')).toBe(false)
  })

  it('checks banner text and button pairs', () => {
    expect(themeContrast({ bg: '#ffffff', fg: '#111111', ac: '#1d4ed8', acf: '#ffffff' })).toMatchObject({ textOk: true, actionOk: true, ok: true })
    const bad = themeContrast({ bg: '#ffffff', fg: '#eeeeee', ac: '#ffff00', acf: '#ffffff' })
    expect(bad.textOk).toBe(false)
    expect(bad.actionOk).toBe(false)
    expect(bad.ok).toBe(false)
  })
})
