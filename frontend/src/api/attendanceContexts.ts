import type { ApiResponse, AttendanceContext, PaginationMeta } from '@/types'
import client from './client'

export interface AttendanceContextFormData {
  name: string
  name_ar?: string
  description?: string
  is_active?: boolean
}

export async function getContextsForManagement(
  params?: Record<string, string | number>,
): Promise<{ data: AttendanceContext[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: AttendanceContext[]; meta: PaginationMeta }>(
    '/attendance-contexts/manage',
    { params },
  )
  return data
}

export async function getActiveContexts(): Promise<AttendanceContext[]> {
  const response = await client.get<{ data: AttendanceContext[] } | AttendanceContext[]>('/attendance-contexts')
  const raw = response.data
  const list = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' && 'data' in raw ? raw.data : [])
  const result = Array.isArray(list) ? list : []
  return result
}

export async function getContextById(id: number): Promise<AttendanceContext> {
  const { data } = await client.get<{ data: AttendanceContext }>(`/attendance-contexts/${id}`)
  return data.data
}

export async function createContext(payload: AttendanceContextFormData): Promise<AttendanceContext> {
  const { data } = await client.post<ApiResponse<AttendanceContext>>('/attendance-contexts', payload)
  return data.data
}

export async function updateContext(id: number, payload: AttendanceContextFormData): Promise<AttendanceContext> {
  const { data } = await client.put<ApiResponse<AttendanceContext>>(`/attendance-contexts/${id}`, payload)
  return data.data
}

export async function toggleContextActive(id: number): Promise<AttendanceContext> {
  const { data } = await client.patch<ApiResponse<AttendanceContext>>(`/attendance-contexts/${id}/toggle-active`)
  return data.data
}

export async function deleteContext(id: number): Promise<void> {
  await client.delete(`/attendance-contexts/${id}`)
}
