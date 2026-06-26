import type { ApiResponse, DailyVerse, PaginationMeta } from '@/types'
import client from './client'

export async function getActiveVerse(): Promise<DailyVerse | null> {
  try {
    const { data } = await client.get<ApiResponse<DailyVerse | null>>('/verses/active')
    return data.data ?? null
  } catch {
    return null
  }
}

export async function listVerses(
  params?: Record<string, string | number>,
): Promise<{ data: DailyVerse[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: DailyVerse[]; meta: PaginationMeta }>('/verses', { params })
  return data
}

export async function getVerse(id: number): Promise<DailyVerse> {
  const { data } = await client.get<ApiResponse<DailyVerse>>(`/verses/${id}`)
  return data.data
}

export async function createVerse(payload: { verse_text: string; reference: string; is_active?: boolean }): Promise<DailyVerse> {
  const { data } = await client.post<ApiResponse<DailyVerse>>('/verses', payload)
  return data.data
}

export async function updateVerse(id: number, payload: { verse_text?: string; reference?: string; is_active?: boolean }): Promise<DailyVerse> {
  const { data } = await client.put<ApiResponse<DailyVerse>>(`/verses/${id}`, payload)
  return data.data
}

export async function deleteVerse(id: number): Promise<void> {
  await client.delete(`/verses/${id}`)
}

export async function activateVerse(id: number): Promise<DailyVerse> {
  const { data } = await client.post<ApiResponse<DailyVerse>>(`/verses/${id}/activate`)
  return data.data
}
