import { describe, expect, it } from 'vitest'
import { ApiError, isApiError } from '~/utils/apiError'

describe('ApiError', () => {
  it('exposes problem details', () => {
    const error = new ApiError(422, { title: 'Invalid', code: 'validation_failed', detail: 'Check fields', errors: { email: ['Invalid email', 'Too long'] } })
    expect(error.message).toBe('Check fields')
    expect(error.code).toBe('validation_failed')
    expect(error.isValidation).toBe(true)
    expect(error.fieldErrors()).toEqual([{ name: 'email', message: 'Invalid email' }])
    expect(isApiError(error)).toBe(true)
    expect(isApiError(new Error('x'))).toBe(false)
  })

  it('falls back when the body is not a problem', () => {
    expect(new ApiError(500, null, 'boom')).toMatchObject({ code: 'http_500', message: 'boom', errors: {} })
    expect(new ApiError(0, null).code).toBe('network_error')
    expect(new ApiError(403, { title: 'Forbidden' }).message).toBe('Forbidden')
  })
})
