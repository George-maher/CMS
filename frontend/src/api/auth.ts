import type { ApiResponse, ApplicationStatus, LoginPayload, RegisterPayload, User } from '@/types'
import client from './client'

const platformAdminLoginPath = import.meta.env.VITE_PLATFORM_ADMIN_LOGIN_PATH || 'platform-secure-admin-login'

export type AccessState = 'restricted' | 'full'

export interface AuthResult {
  user: User
  token: string
  token_type: string
  application_status: ApplicationStatus
  rejection_reason: string | null
  access_state: AccessState
}

interface RegisterResult {
  user: User
}

export async function login(payload: LoginPayload): Promise<AuthResult> {
  const { data } = await client.post<ApiResponse<AuthResult>>('/auth/login', payload)
  return data.data
}

export async function platformLogin(payload: LoginPayload): Promise<AuthResult> {
  const { data } = await client.post<ApiResponse<AuthResult>>(`/auth/${platformAdminLoginPath}`, payload)
  return data.data
}

export async function register(payload: RegisterPayload): Promise<RegisterResult> {
  const { data } = await client.post<ApiResponse<{ user: User }>>('/auth/register', payload)
  return data.data
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout')
}

export async function getMe(): Promise<User> {
  const { data } = await client.get<ApiResponse<{ user: User }>>('/auth/me')
  return data.data.user
}
