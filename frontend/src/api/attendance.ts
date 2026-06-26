import type { ApiResponse, Attendance, PaginationMeta, User } from '@/types'
import client from './client'

export async function recordAttendance(qrToken: string, contextId: number, eventId?: number, method?: string): Promise<{ attendance: Attendance; points_earned: number }> {
  const payload: Record<string, unknown> = { qr_token: qrToken, attendance_context_id: contextId }
  if (eventId) payload.event_id = eventId
  if (method) payload.method = method
  const { data } = await client.post<ApiResponse<{ attendance: Attendance; points_earned: number }>>(
    '/attendances/record',
    payload,
  )
  return data.data
}

export async function getAttendanceHistory(
  userId?: number,
  params?: Record<string, string | number>,
): Promise<{ data: Attendance[]; meta: PaginationMeta }> {
  const url = userId ? `/attendances/history/${userId}` : '/attendances/history'
  const { data } = await client.get<{ data: Attendance[]; meta: PaginationMeta }>(url, { params })
  return data
}

export async function getTodayAttendance(): Promise<{ data: Attendance[]; count: number }> {
  const { data } = await client.get<{ data: Attendance[]; count: number }>('/attendances/today')
  return data
}

export async function getAttendanceByClass(
  classId: number,
  params?: Record<string, string>,
): Promise<{ data: Attendance[]; count: number }> {
  const { data } = await client.get<{ data: Attendance[]; count: number }>(
    `/attendances/by-class/${classId}`,
    { params },
  )
  return data
}

export async function lookupByToken(qrToken: string): Promise<{ member?: User; attendance_context_id?: number; attendance_context?: { id: number; name: string; slug: string } | null }> {
  const { data } = await client.get<{ data: { member?: User; attendance_context_id?: number; attendance_context?: { id: number; name: string; slug: string } | null } }>(`/attendances/lookup/${qrToken}`)
  return data.data
}

export async function lookupByMemberId(memberId: string): Promise<{ member: User }> {
  const { data } = await client.get<{ data: { member: User } }>(`/attendances/lookup-member-id/${memberId}`)
  return data.data
}

export async function recordAttendanceByMemberId(
  memberId: string,
  contextId: number,
  eventId?: number,
  method?: string,
): Promise<{ attendance: Attendance; points_earned: number }> {
  const payload: Record<string, unknown> = { member_id: memberId, attendance_context_id: contextId }
  if (eventId) payload.event_id = eventId
  if (method) payload.method = method
  const { data } = await client.post<ApiResponse<{ attendance: Attendance; points_earned: number }>>(
    '/attendances/record-by-member-id',
    payload,
  )
  return data.data
}

export async function getAttendanceStats(userId?: number): Promise<{ total_attendances: number; this_month: number }> {
  const url = userId ? `/attendances/stats/${userId}` : '/attendances/stats'
  const { data } = await client.get<ApiResponse<{ total_attendances: number; this_month: number }>>(url)
  return data.data
}

export interface AbsentMember {
  id: number
  name: string
  phone: string | null
  classe: { id: number; name: string } | null
  last_attendance_date: string | null
  attendance_count: number
  total_sessions: number
  attendance_percentage: number
  consecutive_absences: number
  month_absences: number
}

export interface AbsentMembersResponse {
  summary: {
    total_members: number
    present_count: number
    absent_count: number
  }
  absent_members: AbsentMember[]
}

export async function getFilteredAttendances(params: {
  attendance_context_id?: number
  class_id?: number
  user_id?: number
  search?: string
  date_from?: string
  date_to?: string
  per_page?: number
}): Promise<{ data: Attendance[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: Attendance[]; meta: PaginationMeta }>(
    '/attendances/filtered',
    { params },
  )
  return data
}

export async function getAbsentMembers(params: {
  class_id: number
  event_id?: number
  context_id?: number
  date?: string
  date_from?: string
  date_to?: string
}): Promise<AbsentMembersResponse> {
  const { data } = await client.get<{ data: AbsentMembersResponse }>('/attendances/absent-members', { params })
  return data.data
}
