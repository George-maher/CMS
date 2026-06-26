import { useState, useCallback, useRef } from 'react'
import client from '../api/client'
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

export function usePagination<T = any>(
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

  const extractData = (responseData: any): T[] => {
    if (dataKey) {
      const nested = responseData[dataKey]
      if (Array.isArray(nested)) return nested
      if (nested?.data && Array.isArray(nested.data)) return nested.data
      return []
    }
    if (responseData?.data && Array.isArray(responseData.data)) return responseData.data
    if (Array.isArray(responseData)) return responseData
    return []
  }

  const extractMeta = (responseData: any): PaginationMeta | null => {
    if (responseData?.meta) return responseData.meta as PaginationMeta
    if (responseData?.pagination) return responseData.pagination as PaginationMeta
    return null
  }

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
      const responseData = response.data as any

      const extractedItems = extractData(responseData)
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
