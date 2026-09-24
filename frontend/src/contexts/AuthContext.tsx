/* eslint-disable react-refresh/only-export-components */
import { createContext, useEffect, useRef, useState, type ReactNode } from 'react'
import type { LoginPayload, RegisterPayload, User } from '@/types'
import * as authApi from '@/api/auth'
import type { AuthResult } from '@/api/auth'
import { logCatch } from '@/lib/debug'
import { clearAllData } from '@/lib/db'
import { clearRequestCache } from '@/api/client'

interface AuthContextType {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  login: (payload: LoginPayload) => Promise<AuthResult>
  platformLogin: (payload: LoginPayload) => Promise<AuthResult>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
}

export const AuthContext = createContext<AuthContextType | undefined>(undefined)

const STORAGE_USER_KEY = 'auth_user'
const STORAGE_TOKEN_KEY = 'auth_token'
const STORAGE_VALIDATED_AT_KEY = 'auth_validated_at'

function decodeUser(): User | null {
  try {
    const raw = localStorage.getItem(STORAGE_USER_KEY)
    return raw ? (JSON.parse(raw) as User) : null
  } catch (e) {
    logCatch('AuthContext.decodeUser', e)
    return null
  }
}

function getLastValidatedAt(): number {
  try {
    return parseInt(localStorage.getItem(STORAGE_VALIDATED_AT_KEY) || '0', 10)
  } catch {
    return 0
  }
}

/**
 * Determines if the stored session needs server-side revalidation.
 * - Never validated → must validate
 * - Older than 5 minutes → revalidate in background
 * - Younger than 5 minutes → trust it, skip API call entirely
 */
function needsValidation(): boolean {
  const lastValidated = getLastValidatedAt()
  return Date.now() - lastValidated > 5 * 60 * 1000
}

function markValidated(): void {
  localStorage.setItem(STORAGE_VALIDATED_AT_KEY, String(Date.now()))
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(decodeUser)
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(STORAGE_TOKEN_KEY))
  const [isLoading, setIsLoading] = useState(true)
  const validatingRef = useRef(false)

  useEffect(() => {
    if (!token) {
      setIsLoading(false)
      return
    }

    if (!needsValidation()) {
      setIsLoading(false)
      return
    }

    if (validatingRef.current) return
    validatingRef.current = true

    authApi
      .getMe()
      .then((u) => {
        setUser(u)
        localStorage.setItem(STORAGE_USER_KEY, JSON.stringify(u))
        markValidated()
      })
      .catch((e) => {
        logCatch('AuthContext.getMe', e)
        const status = e?.response?.status
        if (status === 401) {
          // Only an explicit 401 from the server invalidates the session.
          // Offline / network errors (status undefined) must keep the cached
          // session — this app is offline-first and a flaky connection must
          // not log the user out.
          setToken(null)
          setUser(null)
          localStorage.removeItem(STORAGE_TOKEN_KEY)
          localStorage.removeItem(STORAGE_USER_KEY)
          localStorage.removeItem(STORAGE_VALIDATED_AT_KEY)
        }
      })
      .finally(() => {
        validatingRef.current = false
        setIsLoading(false)
      })
  }, [token])

  const login = async (payload: LoginPayload): Promise<AuthResult> => {
    const result = await authApi.login(payload)
    clearRequestCache()
    setToken(result.token)
    setUser(result.user)
    localStorage.setItem(STORAGE_TOKEN_KEY, result.token)
    localStorage.setItem(STORAGE_USER_KEY, JSON.stringify(result.user))
    markValidated()
    return result
  }

  const platformLogin = async (payload: LoginPayload): Promise<AuthResult> => {
    const result = await authApi.platformLogin(payload)
    clearRequestCache()
    setToken(result.token)
    setUser(result.user)
    localStorage.setItem(STORAGE_TOKEN_KEY, result.token)
    localStorage.setItem(STORAGE_USER_KEY, JSON.stringify(result.user))
    markValidated()
    return result
  }

  const register = async (payload: RegisterPayload): Promise<void> => {
    await authApi.register(payload)
  }

  const logout = async () => {
    try {
      await authApi.logout()
    } catch (e) {
      logCatch('AuthContext.logout', e)
    }
    setToken(null)
    setUser(null)
    clearRequestCache()
    localStorage.removeItem(STORAGE_TOKEN_KEY)
    localStorage.removeItem(STORAGE_USER_KEY)
    localStorage.removeItem(STORAGE_VALIDATED_AT_KEY)
    clearAllData().catch(e => logCatch('AuthContext.clearAllData', e))
  }

  const refreshUser = async () => {
    const u = await authApi.getMe()
    setUser(u)
    localStorage.setItem(STORAGE_USER_KEY, JSON.stringify(u))
    markValidated()
  }

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        isAuthenticated: !!token && !!user,
        isLoading,
        login,
        platformLogin,
        register,
        logout,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

