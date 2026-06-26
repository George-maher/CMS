import type { ApiResponse, Feedback, PaginationMeta } from '@/types'
import client from './client'

export async function submitFeedback(payload: { message: string; category?: string; is_anonymous?: boolean }): Promise<Feedback> {
  const { data } = await client.post<ApiResponse<Feedback>>('/feedback', payload)
  return data.data
}

export async function listFeedback(
  params?: Record<string, string | number | boolean>,
): Promise<{ data: Feedback[]; meta: PaginationMeta; unresolved_count: number }> {
  const { data } = await client.get<{ data: Feedback[]; meta: PaginationMeta; unresolved_count: number }>('/feedback', { params })
  return data
}

export async function getFeedback(id: number): Promise<Feedback> {
  const { data } = await client.get<ApiResponse<Feedback>>(`/feedback/${id}`)
  return data.data
}

export async function replyToFeedback(id: number, message: string): Promise<Feedback> {
  const { data } = await client.post<ApiResponse<Feedback>>(`/feedback/${id}/reply`, { message })
  return data.data
}

export async function resolveFeedback(id: number): Promise<void> {
  await client.patch(`/feedback/${id}/resolve`)
}

export async function getMyFeedback(
  params?: Record<string, string | number>,
): Promise<{ data: Feedback[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: Feedback[]; meta: PaginationMeta }>('/feedback/mine', { params })
  return data
}

export async function markFeedbackSeen(id: number): Promise<Feedback> {
  const { data } = await client.post<{ data: Feedback }>(`/feedback/${id}/mark-seen`)
  return data.data
}
