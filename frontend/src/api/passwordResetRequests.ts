import type { ApiResponse, PasswordResetRequest } from '@/types'
import client from './client'

export async function submitPasswordResetRequest(payload: { email: string; notes?: string }): Promise<{ message: string }> {
  const { data } = await client.post<{ message: string }>('/password-reset-requests', payload)
  return data
}

export async function listPasswordResetRequests(
  params?: Record<string, string | number>,
): Promise<{ data: PasswordResetRequest[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const { data } = await client.get('/password-reset-requests', { params })
  return data
}

export async function getPasswordResetRequest(id: number): Promise<PasswordResetRequest> {
  const { data } = await client.get<ApiResponse<PasswordResetRequest>>(`/password-reset-requests/${id}`)
  return data.data
}

export async function approvePasswordResetRequest(id: number): Promise<void> {
  await client.post(`/password-reset-requests/${id}/approve`)
}

export async function rejectPasswordResetRequest(id: number, reason: string): Promise<void> {
  await client.post(`/password-reset-requests/${id}/reject`, { reason })
}

export async function completePasswordReset(payload: { token: string; password: string; password_confirmation: string }): Promise<{ message: string }> {
  const { data } = await client.post<{ message: string }>('/password-reset-requests/reset', payload)
  return data
}
