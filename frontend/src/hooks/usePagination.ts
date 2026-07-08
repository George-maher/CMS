import { useState, useCallback, useRef } from 'react'
import client from '../api/client'
import { logCatch } from '@/lib/debug'
import type { AxiosRequestConfig, AxiosError } from 'axios'

interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

interface PaginationState<T> {
  items: T[]
  meta: PaginationMeta | null
  loading: boolean
  loadingMore: boolean
  error: string | null
}

interface UsePaginationReturn<T> extends PaginationState<T> {
  loadPage: (page?: number) => Promise<void>
  loadMore: () => Promise<void>
  reload: () => Promise<void>
  setItems: (items: T[]) => void
  reset: () => void
}

function extractData<T>(responseData: unknown, dataKey?: string): T[] {
  const data = responseData as Record<string, unknown> | null
  if (dataKey && data) {
    const nested = data[dataKey]
    if (Array.isArray(nested)) return nested as T[]
    if (nested && typeof nested === 'object' && 'data' in (nested as Record<string, unknown>)) {
      const nestedData = (nested as Record<string, unknown>).data
      if (Array.isArray(nestedData)) return nestedData as T[]
    }
    return []
  }
  if (data?.data && Array.isArray(data.data)) return data.data as T[]
  if (Array.isArray(responseData)) return responseData as T[]
  return []
}

function extractMeta(responseData: unknown): PaginationMeta | null {
  const data = responseData as Record<string, unknown> | null
  if (data?.meta) return data.meta as PaginationMeta
  if (data?.pagination) return data.pagination as PaginationMeta
  return null
}

export function usePagination<T = unknown>(
  configOrUrl: AxiosRequestConfig | string,
  options?: { perPage?: number; dataKey?: string; immediate?: boolean },
): UsePaginationReturn<T> {
  const perPage = options?.perPage ?? 15
  const dataKey = options?.dataKey

  const [state, setState] = useState<PaginationState<T>>({
    items: [],
    meta: null,
    loading: false,
    loadingMore: false,
    error: null,
  })

  const currentPageRef = useRef(1)
  const loadingRef = useRef(false)

  const fetchPage = useCallback(async (page: number, append: boolean) => {
    if (loadingRef.current) return
    loadingRef.current = true

    setState(prev => ({
      ...prev,
      loading: !append,
      loadingMore: append,
      error: null,
    }))

    try {
      const config: AxiosRequestConfig =
        typeof configOrUrl === 'string' ? { url: configOrUrl, method: 'get' } : { ...configOrUrl }

      config.params = { ...config.params, page, per_page: perPage }

      const response = await client.request<T>(config)
      const responseData = response.data as unknown

      const extractedItems = extractData<T>(responseData, dataKey)
      const meta = extractMeta(responseData)

      setState(prev => ({
        items: append ? [...prev.items, ...extractedItems] : extractedItems,
        meta,
        loading: false,
        loadingMore: false,
        error: null,
      }))

      currentPageRef.current = page
    } catch (err) {
      logCatch('usePagination.fetchPage', err)
      const axiosError = err as AxiosError<{ message?: string }>
      const message = axiosError.response?.data?.message || axiosError.message || 'Failed to load data'

      setState(prev => ({
        ...prev,
        loading: false,
        loadingMore: false,
        error: message,
      }))
    } finally {
      loadingRef.current = false
    }
  }, [configOrUrl, perPage, dataKey])

  const loadPage = useCallback(async (page?: number) => {
    await fetchPage(page ?? 1, false)
  }, [fetchPage])

  const loadMore = useCallback(async () => {
    const nextPage = currentPageRef.current + 1
    if (state.meta && nextPage > state.meta.last_page) return
    await fetchPage(nextPage, true)
  }, [fetchPage, state.meta])

  const reload = useCallback(async () => {
    await fetchPage(1, false)
  }, [fetchPage])

  const setItems = useCallback((items: T[]) => {
    setState(prev => ({ ...prev, items }))
  }, [])

  const reset = useCallback(() => {
    setState({ items: [], meta: null, loading: false, loadingMore: false, error: null })
    currentPageRef.current = 1
  }, [])

  return { ...state, loadPage, loadMore, reload, setItems, reset }
}
