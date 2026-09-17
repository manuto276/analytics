import type { WritableComputedRef } from 'vue'

type QueryValue = string | null | undefined | (string | null)[]

/** Rebuilds a query with one key set (or removed when the value is empty). */
function withKey(query: Record<string, QueryValue>, key: string, value: string | undefined): Record<string, QueryValue> {
  const next = Object.fromEntries(Object.entries(query).filter(([k]) => k !== key))
  if (value !== undefined) next[key] = value
  return next
}

function first(value: QueryValue): string | undefined {
  if (Array.isArray(value)) return value[0] ?? undefined
  return value ?? undefined
}

/**
 * Binds one URL query key to a writable ref, so a view can be deep-linked.
 * Writing the default value removes the key again; other keys are preserved.
 */
export function useQueryParam<T extends string>(key: string, defaultValue: T, allowed?: readonly T[]): WritableComputedRef<T> {
  const router = useRouter()
  const route = router.currentRoute

  return computed<T>({
    get() {
      const value = first(route.value.query[key])
      if (value === undefined) return defaultValue
      if (allowed && !(allowed as readonly string[]).includes(value)) return defaultValue
      return value as T
    },
    set(value: T) {
      void router.replace({ query: withKey(route.value.query, key, value === defaultValue ? undefined : value) })
    }
  })
}

/** Same as useQueryParam for an optional free-text value (undefined when absent). */
export function useOptionalQueryParam(key: string, maxLength = 128): WritableComputedRef<string | undefined> {
  const router = useRouter()
  const route = router.currentRoute

  return computed<string | undefined>({
    get() {
      const value = first(route.value.query[key])?.trim()
      return value ? value.slice(0, maxLength) : undefined
    },
    set(value: string | undefined) {
      void router.replace({ query: withKey(route.value.query, key, value ? value.slice(0, maxLength) : undefined) })
    }
  })
}

/** Numeric query key restricted to a fixed set of values. */
export function useNumericQueryParam<T extends number>(key: string, defaultValue: T, allowed: readonly T[]): WritableComputedRef<T> {
  const raw = useQueryParam(key, String(defaultValue), allowed.map(String))
  return computed<T>({
    get() {
      const value = Number(raw.value)
      return (allowed as readonly number[]).includes(value) ? value as T : defaultValue
    },
    set(value: T) {
      raw.value = String(value)
    }
  })
}
