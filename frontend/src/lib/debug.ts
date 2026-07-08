import type { AxiosError } from 'axios'

const DEV = import.meta.env.DEV

export function logError(context: string, ...args: unknown[]) {
  if (DEV) {
    console.group(`[DEBUG] ${context}`)
    args.forEach((arg, i) => {
      if (arg instanceof Error) {
        console.error(`Error[${i}]:`, arg.message)
        console.error(`Stack[${i}]:`, arg.stack)
      } else {
        console.error(`Arg[${i}]:`, arg)
      }
    })
    console.groupEnd()
  }
}

export function logCatch(context: string, error: unknown) {
  if (!DEV) return
  const err = error as Error & { response?: unknown; config?: { url?: string; method?: string; data?: unknown } }
  console.group(`[CATCH] ${context}`)
  console.error('Error:', err)
  if (err.stack) console.error('Stack:', err.stack)
  if (err.response) console.error('Response:', err.response)
  if (err.config) {
    console.error('URL:', err.config.url)
    console.error('Method:', err.config.method)
  }
  console.groupEnd()
}

export function logAxiosError(context: string, error: AxiosError) {
  if (!DEV) return
  const config = error.config
  const response = error.response
  console.group(`[API ERROR] ${context}`)
  console.error('URL:', config?.url)
  console.error('Method:', config?.method?.toUpperCase())
  console.error('Status:', response?.status)
  console.error('Response Body:', response?.data)
  console.error('Request Payload:', config?.data)
  console.error('Error Message:', error.message)
  console.error('Full Error:', error)
  console.groupEnd()
}
