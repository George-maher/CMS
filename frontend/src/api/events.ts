import type { Event, EventViewer, PaginationMeta } from '@/types'
import client from './client'

function toFormData(data: Record<string, unknown>): FormData {
  const fd = new FormData()
  for (const [key, value] of Object.entries(data)) {
    if (value === null || value === undefined || value === '') {
      continue
    }
    if (value instanceof File) {
      fd.append(key, value)
    } else if (value === true) {
      fd.append(key, '1')
    } else if (value === false) {
      fd.append(key, '0')
    } else if (Array.isArray(value)) {
      value.forEach((item) => fd.append(`${key}[]`, String(item)))
    } else if (typeof value === 'object') {
      fd.append(key, JSON.stringify(value))
    } else {
      fd.append(key, String(value))
    }
  }
  return fd
}

export async function listEvents(
  params?: Record<string, string | number | boolean>,
): Promise<{ data: Event[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: Event[]; meta: PaginationMeta }>('/events', { params })
  return data
}

export async function getEvent(id: number): Promise<Event> {
  const { data } = await client.get<{ data: Event }>(`/events/${id}`)
  return data.data
}

export async function createEvent(payload: Record<string, unknown>): Promise<Event> {
  const hasFile = Object.values(payload).some((v) => v instanceof File)
  const { data } = await client.post<{ data: Event }>('/events', hasFile ? toFormData(payload) : payload)
  return data.data
}

export async function updateEvent(id: number, payload: Record<string, unknown>): Promise<Event> {
  const hasFile = Object.values(payload).some((v) => v instanceof File)

  if (hasFile) {
    const fd = toFormData(payload)
    fd.append('_method', 'PUT')
    const { data } = await client.post<{ data: Event }>(`/events/${id}`, fd)
    return data.data
  }

  const { data } = await client.put<{ data: Event }>(`/events/${id}`, payload)
  return data.data
}

export async function deleteEvent(id: number): Promise<void> {
  await client.delete(`/events/${id}`)
}

export async function trackEventView(id: number): Promise<void> {
  await client.post(`/events/${id}/track-view`)
}

export async function getEventViewSummary(id: number): Promise<{
  event_id: number
  total_views: number
  total_target_members: number
  view_percentage: number
  not_viewed_count: number
}> {
  const { data } = await client.get<{ data: { event_id: number; total_views: number; total_target_members: number; view_percentage: number; not_viewed_count: number } }>(`/events/${id}/analytics/summary`)
  return data.data
}

export async function getEventViewedUsers(
  id: number,
  params?: { class_id?: number; search?: string },
): Promise<EventViewer[]> {
  const { data } = await client.get<{ data: EventViewer[] }>(`/events/${id}/analytics/viewed`, { params })
  return data.data
}

export async function getEventNotViewedUsers(
  id: number,
  params?: { class_id?: number; search?: string },
): Promise<EventViewer[]> {
  const { data } = await client.get<{ data: EventViewer[] }>(`/events/${id}/analytics/not-viewed`, { params })
  return data.data
}
