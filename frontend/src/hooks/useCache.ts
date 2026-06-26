import { useState, useCallback, useRef } from 'react'

interface CacheEntry<T> {
  data: T
  expiresAt: number
}

const DEFAULT_TTL = 5 * 60 * 1000

const globalStore = new Map<string, CacheEntry<any>>()

export function useCache<T = any>(options?: { ttl?: number; key?: string; global?: boolean }) {
  const ttl = options?.ttl ?? DEFAULT_TTL
  const global = options?.global ?? true
  const prefix = options?.key ?? 'default'
  const localRef = useRef<Map<string, CacheEntry<T>>>(new Map())

  const getStore = useCallback(() => (global ? globalStore : localRef.current), [global])

  const get = useCallback(
    (key: string): T | null => {
      const store = getStore()
      const entry = store.get(`${prefix}:${key}`)
      if (!entry) return null
      if (Date.now() > entry.expiresAt) {
        store.delete(`${prefix}:${key}`)
        return null
      }
      return entry.data
    },
    [getStore, prefix],
  )

  const setState = useCallback(
    (key: string, data: T, customTtl?: number) => {
      const store = getStore()
      store.set(`${prefix}:${key}`, {
        data,
        expiresAt: Date.now() + (customTtl ?? ttl),
      })
    },
    [getStore, prefix, ttl],
  )

  const invalidate = useCallback(
    (key?: string) => {
      const store = getStore()
      if (key) {
        store.delete(`${prefix}:${key}`)
      } else {
        const pattern = `${prefix}:`
        for (const k of store.keys()) {
          if (k.startsWith(pattern)) store.delete(k)
        }
      }
    },
    [getStore, prefix],
  )

  const clearAll = useCallback(() => {
    getStore().clear()
  }, [getStore])

  return { get, set: setState, invalidate, clearAll }
}

export function useCacheState<T = any>(initialValue: T, key: string, options?: { ttl?: number; global?: boolean }) {
  const cache = useCache<T>({ ...options, key })
  const [state, setState] = useState<T>(() => {
    const cached = cache.get(key)
    return cached !== null ? cached : initialValue
  })

  const set = useCallback(
    (value: T | ((prev: T) => T)) => {
      setState(prev => {
        const next = typeof value === 'function' ? (value as (prev: T) => T)(prev) : value
        cache.set(key, next)
        return next
      })
    },
    [cache, key],
  )

  const invalidate = useCallback(() => {
    cache.invalidate(key)
  }, [cache, key])

  return { value: state, set, invalidate }
}
