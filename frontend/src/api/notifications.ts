import type { PaginationMeta, NotificationItem } from '@/types'
import client from './client'

export async function listNotifications(
  params?: Record<string, string | number>,
): Promise<{ data: NotificationItem[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: NotificationItem[]; meta: PaginationMeta }>(
    '/notifications',
    { params },
  )
  return data
}

export async function getUnreadCount(): Promise<number> {
  const { data } = await client.get<{ data: { unread_count: number } }>('/notifications/unread-count')
  return data.data.unread_count
}

export async function markAsRead(id: number): Promise<void> {
  await client.post(`/notifications/${id}/mark-read`)
}

export async function markAllAsRead(): Promise<void> {
  await client.post('/notifications/mark-all-read')
}
