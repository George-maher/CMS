import type { PaginationMeta, MembershipRequest } from '@/types'
import client from './client'

export async function submitMembershipRequest(
  payload: FormData,
): Promise<{ message: string; data: MembershipRequest }> {
  const { data } = await client.post<{ message: string; data: MembershipRequest }>(
    '/membership-requests',
    payload,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return data
}

export async function listMembershipRequests(
  params?: Record<string, string | number>,
): Promise<{ data: MembershipRequest[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: MembershipRequest[]; meta: PaginationMeta }>(
    '/membership-requests',
    { params },
  )
  return data
}

export async function getMembershipRequest(id: number): Promise<MembershipRequest> {
  const { data } = await client.get<{ data: MembershipRequest }>(`/membership-requests/${id}`)
  return data.data
}

export async function approveMembershipRequest(id: number): Promise<void> {
  await client.post(`/membership-requests/${id}/approve`)
}

export async function rejectMembershipRequest(id: number, rejection_reason: string): Promise<void> {
  await client.post(`/membership-requests/${id}/reject`, { rejection_reason })
}

export async function listActiveChurches(): Promise<{ id: number; name: string; slug: string; address: string | null }[]> {
  const { data } = await client.get<{ data: { id: number; name: string; slug: string; address: string | null }[] }>(
    '/churches/active',
  )
  return data.data
}
