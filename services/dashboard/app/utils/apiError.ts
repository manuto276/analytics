import type { Problem } from '~/types'

/** Error thrown by useApi for every non-2xx response (RFC 9457 problem details). */
export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly errors: Record<string, string[]>
  readonly problem: Partial<Problem> | null

  constructor(status: number, problem: Partial<Problem> | null, fallbackMessage = 'Request failed') {
    super(problem?.detail || problem?.title || fallbackMessage)
    this.name = 'ApiError'
    this.status = status
    this.code = problem?.code ?? (status === 0 ? 'network_error' : `http_${status}`)
    this.errors = problem?.errors ?? {}
    this.problem = problem
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  /** First message per field, handy for UForm `setErrors`. */
  fieldErrors(): { name: string, message: string }[] {
    return Object.entries(this.errors).map(([name, messages]) => ({ name, message: messages[0] ?? '' }))
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}
