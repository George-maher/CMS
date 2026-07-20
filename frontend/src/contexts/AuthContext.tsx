/* eslint-disable react-refresh/only-export-components */
import { createContext, useEffect, useRef, useState, type ReactNode } from 'react'
import type { LoginPayload, RegisterPayload, User } from '@/types'
import * as authApi from '@/api/auth'
import { logCatch } from '@/lib/debug'
import { clearAllData } from '@/lib/db'

interface AuthContextType {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  login: (payload: LoginPayload) => Promise<User>
  platformLogin: (payload: LoginPayload) => Promise<User>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
}

export const AuthContext = createContext<AuthContextType | undefined>(undefined)

function decodeUser(): User | null {
  try {
    const raw = localStorage.getItem('auth_user')
    return raw ? (JSON.parse(raw) as User) : null
  } catch (e) {
    logCatch('AuthContext.decodeUser', e)
    return null
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(decodeUser)
  const [token, setToken] = useState<string | null>(localStorage.getItem('auth_token'))
  const [isLoading, setIsLoading] = useState(true)
  const validatedRef = useRef(false)

  useEffect(() => {
    if (validatedRef.current) return
    if (token) {
      authApi
        .getMe()
        .then((u) => {
          validatedRef.current = true
          setUser(u)
          localStorage.setItem('auth_user', JSON.stringify(u))
        })
        .catch((e) => {
          logCatch('AuthContext.getMe', e)
          validatedRef.current = true
          setToken(null)
          setUser(null)
          localStorage.removeItem('auth_token')
          localStorage.removeItem('auth_user')
        })
        .finally(() => setIsLoading(false))
    } else {
      validatedRef.current = true
      Promise.resolve().then(() => setIsLoading(false))
    }
  }, [token])

  const login = async (payload: LoginPayload): Promise<User> => {
    const result = await authApi.login(payload)
    setToken(result.token)
    setUser(result.user)
    localStorage.setItem('auth_token', result.token)
    localStorage.setItem('auth_user', JSON.stringify(result.user))
    return result.user
  }

  const platformLogin = async (payload: LoginPayload): Promise<User> => {
    const result = await authApi.platformLogin(payload)
    setToken(result.token)
    setUser(result.user)
    localStorage.setItem('auth_token', result.token)
    localStorage.setItem('auth_user', JSON.stringify(result.user))
    return result.user
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
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    clearAllData().catch(e => logCatch('AuthContext.clearAllData', e))
  }

  const refreshUser = async () => {
    const u = await authApi.getMe()
    setUser(u)
    localStorage.setItem('auth_user', JSON.stringify(u))
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


