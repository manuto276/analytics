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

  it('checks the four pairs the server enforces', () => {
    const theme = {
      colors: { background: '#ffffff', text: '#111111', accent: '#1d4ed8', accentText: '#ffffff', link: '#1d4ed8' },
      reopen: { background: null, text: null }
    }
    const good = themeContrast(theme)
    expect(good.ok).toBe(true)
    expect(good.pairs.map(p => p.key)).toEqual(['text', 'accentText', 'link', 'reopen'])
    expect(good.pairs[3]).toMatchObject({ foreground: '#ffffff', background: '#1d4ed8', field: 'theme.reopen.text' })

    const bad = themeContrast({
      colors: { background: '#ffffff', text: '#eeeeee', accent: '#ffff00', accentText: '#ffffff', link: '#dddddd' },
      reopen: { background: '#000000', text: '#111111' }
    })
    expect(bad.ok).toBe(false)
    expect(bad.pairs.filter(p => !p.ok).map(p => p.field)).toEqual(['theme.colors.text', 'theme.colors.accentText', 'theme.colors.link', 'theme.reopen.text'])
  })
})
