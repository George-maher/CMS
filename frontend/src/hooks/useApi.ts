import { useState, useCallback, useRef, useEffect } from 'react'
import client from '../api/client'
import { logCatch } from '@/lib/debug'
import type { AxiosRequestConfig, AxiosError } from 'axios'

interface ApiState<T> {
  data: T | null
  loading: boolean
  error: string | null
  statusCode: number | null
}

interface UseApiReturn<T> extends ApiState<T> {
  execute: (...args: unknown[]) => Promise<T | null>
  reset: () => void
}

export function useApi<T = unknown>(
  configOrUrl: AxiosRequestConfig | string,
  options?: { immediate?: boolean; onSuccess?: (data: T) => void; onError?: (error: string) => void },
): UseApiReturn<T> {
  const [state, setState] = useState<ApiState<T>>({
    data: null,
    loading: false,
    error: null,
    statusCode: null,
  })

  const optionsRef = useRef(options)
  optionsRef.current = options

  const execute = useCallback(async (...args: unknown[]): Promise<T | null> => {
    setState(prev => ({ ...prev, loading: true, error: null }))

    try {
      const config: AxiosRequestConfig =
        typeof configOrUrl === 'string' ? { url: configOrUrl, method: 'get' } : { ...configOrUrl }

      if (args.length > 0) {
        const queryParams = args[0]
        if (typeof queryParams === 'object' && queryParams !== null) {
          ;(config.params as Record<string, unknown>) = { ...config.params, ...(queryParams as Record<string, unknown>) }
        }
      }

      const response = await client.request<T>(config)
      const responseData = response.data

      setState({
        data: responseData,
        loading: false,
        error: null,
        statusCode: response.status,
      })

      optionsRef.current?.onSuccess?.(responseData)
      return responseData
    } catch (err) {
      logCatch('useApi.execute', err)
      const axiosError = err as AxiosError<{ message?: string }>
      const message = axiosError.response?.data?.message || axiosError.message || 'An unexpected error occurred'

      setState({
        data: null,
        loading: false,
        error: message,
        statusCode: axiosError.response?.status || 0,
      })

      optionsRef.current?.onError?.(message)
      return null
    }
  }, [configOrUrl])

  const reset = useCallback(() => {
    setState({ data: null, loading: false, error: null, statusCode: null })
  }, [])

  useEffect(() => {
    if (optionsRef.current?.immediate) {
      execute()
    }
  }, [execute])

  return { ...state, execute, reset }
}
