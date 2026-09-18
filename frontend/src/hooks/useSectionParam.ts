import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Keeps a settings section in the URL (`?section=…`) so refresh, Back, and
 * direct links land on the same section. Unknown values fall back to the
 * default, and the default itself is kept out of the URL to keep links clean.
 */
export function useSectionParam<T extends string>(sections: readonly T[], fallback: T, param = 'section') {
  const [searchParams, setSearchParams] = useSearchParams()
  const raw = searchParams.get(param)
  const active = (sections as readonly string[]).includes(raw ?? '') ? (raw as T) : fallback

  const setActive = useCallback((next: T) => {
    setSearchParams((current) => {
      const params = new URLSearchParams(current)
      if (next === fallback) params.delete(param)
      else params.set(param, next)
      return params
    })
  }, [setSearchParams, fallback, param])

  return [active, setActive] as const
}
