import type { ApiResponse, ProfileUpdateRequest } from '@/types'
import client from './client'

// --- Member endpoints ---

export async function submitProfileUpdateRequest(payload: { name?: string; phone?: string; email?: string; address?: string }): Promise<{ message: string; data: ProfileUpdateRequest }> {
  const { data } = await client.post<{ message: string; data: ProfileUpdateRequest }>('/profile-update-requests', payload)
  return data
}

export async function listMyProfileUpdateRequests(
  params?: Record<string, string | number>,
): Promise<{ data: ProfileUpdateRequest[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const { data } = await client.get('/profile-update-requests/my', { params })
  return data
}

// --- Admin/Servant endpoints ---

export async function listProfileUpdateRequests(
  params?: Record<string, string | number>,
): Promise<{ data: ProfileUpdateRequest[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const { data } = await client.get('/profile-update-requests', { params })
  return data
}

export async function getProfileUpdateRequest(id: number): Promise<ProfileUpdateRequest> {
  const { data } = await client.get<ApiResponse<ProfileUpdateRequest>>(`/profile-update-requests/${id}`)
  return data.data
}

export async function approveProfileUpdateRequest(id: number): Promise<{ message: string; data: ProfileUpdateRequest }> {
  const { data } = await client.post<{ message: string; data: ProfileUpdateRequest }>(`/profile-update-requests/${id}/approve`)
  return data
}

export async function rejectProfileUpdateRequest(id: number, reason: string): Promise<{ message: string; data: ProfileUpdateRequest }> {
  const { data } = await client.post<{ message: string; data: ProfileUpdateRequest }>(`/profile-update-requests/${id}/reject`, { reason })
  return data
}

// --- Own profile update (admin/servant) ---

export async function updateOwnProfile(payload: { name?: string; phone?: string; email?: string; address?: string }): Promise<{ message: string }> {
  const { data } = await client.put<{ message: string }>('/profile', payload)
  return data
}
