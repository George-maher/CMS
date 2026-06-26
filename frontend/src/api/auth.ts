import type { ApiResponse, ForgotPasswordPayload, LoginPayload, RegisterPayload, ResetPasswordPayload, User } from '@/types'
import client from './client'

const platformAdminLoginPath = import.meta.env.VITE_PLATFORM_ADMIN_LOGIN_PATH || 'platform-secure-admin-login'

interface AuthResult {
  user: User
  token: string
  token_type: string
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

export async function forgotPassword(payload: ForgotPasswordPayload): Promise<{ message: string }> {
  const { data } = await client.post<{ message: string }>('/auth/forgot-password', payload)
  return data
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<{ message: string }> {
  const { data } = await client.post<{ message: string }>('/auth/reset-password', payload)
  return data
}
