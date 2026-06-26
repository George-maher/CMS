import type { ApiResponse, ChurchApplication, PlatformDashboardStats } from '@/types'
import client from './client'

export async function submitChurchApplication(formData: FormData): Promise<{ application: ChurchApplication; user: { id: number; email: string } }> {
  const { data } = await client.post('/church-applications', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return { application: data.data, user: data.user }
}

export async function getPendingStatus(): Promise<{
  application_status: string
  application: ChurchApplication | null
  user: { id: number; name: string; email: string }
}> {
  const { data } = await client.get('/pending/status')
  return data.data
}

export async function getPlatformDashboard(): Promise<PlatformDashboardStats> {
  const { data } = await client.get<ApiResponse<PlatformDashboardStats>>('/platform/dashboard')
  return data.data
}

export async function listApplications(status?: string, page = 1, perPage = 15): Promise<{ data: ChurchApplication[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const params: Record<string, string | number> = { page, per_page: perPage }
  if (status) params.status = status
  const { data } = await client.get('/platform/applications', { params })
  return data
}

export async function getApplication(id: number): Promise<ChurchApplication> {
  const { data } = await client.get<ApiResponse<ChurchApplication>>(`/platform/applications/${id}`)
  return data.data
}

export async function approveApplication(id: number, notes?: string): Promise<void> {
  await client.post(`/platform/applications/${id}/approve`, { notes })
}

export async function rejectApplication(id: number, rejection_reason: string): Promise<void> {
  await client.post(`/platform/applications/${id}/reject`, { rejection_reason })
}
