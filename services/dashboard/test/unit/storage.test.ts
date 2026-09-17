import { afterEach, describe, expect, it, vi } from 'vitest'
import { readStorage, writeStorage } from '~/utils/storage'

describe('storage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('reads and writes localStorage', () => {
    writeStorage('k', 'v')
    expect(readStorage('k')).toBe('v')
    writeStorage('k', null)
    expect(readStorage('k')).toBeNull()
  })

  it('never throws when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    expect(readStorage('k')).toBeNull()
    expect(() => writeStorage('k', 'v')).not.toThrow()
  })
})
