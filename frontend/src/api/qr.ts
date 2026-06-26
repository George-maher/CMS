import type { ApiResponse, CreateQRInvitePayload, PaginationMeta, QRInvite } from '@/types'
import client from './client'

export async function createQRInvite(payload: CreateQRInvitePayload): Promise<{ invite: QRInvite; url: string }> {
  const { data } = await client.post<ApiResponse<{ invite: QRInvite; url: string }>>('/qr/invites', payload)
  return data.data
}

export async function listQRInvites(
  params?: Record<string, string | number>,
): Promise<{ data: QRInvite[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: QRInvite[]; meta: PaginationMeta }>('/qr/invites', { params })
  return data
}

export interface ValidateQRResult {
  valid: boolean
  type: string
  invite: QRInvite
  classes: ClasseInfo[]
  creator_class_id?: number
  creator_class_name?: string
  attendance_context_id?: number | null
  attendance_context?: { id: number; name: string; slug: string } | null
}

export async function validateQRToken(token: string): Promise<ValidateQRResult> {
  const { data } = await client.get<ApiResponse<ValidateQRResult>>(`/qr/validate/${token}`)
  return data.data
}

export async function revokeQRInvite(id: number): Promise<void> {
  await client.post(`/qr/invites/${id}/revoke`)
}

export interface ClasseInfo {
  id: number
  name: string
}

export interface UsedByUserEntry {
  id: number
  name: string
  role?: string
  phone?: string | null
  member_id?: string | null
  class_id?: number | null
  class_name?: string | null
  stage_name?: string | null
  used_at: string
}

export interface InviteDetails {
  valid: boolean
  type: string
  type_label: string
  role: string
  role_label: string
  creator_name: string | null
  creator_class_id: number | null
  creator_class_name: string | null
  class_id: number | null
  class_name: string | null
  classes: ClasseInfo[]
  expires_at: string
  is_expired: boolean
  is_used: boolean
  is_revoked: boolean
  use_count: number
  max_uses: number | null
  remaining_uses: number | null
  usage_label: string | null
  used_by_users: UsedByUserEntry[] | null
}

export async function getInviteDetails(token: string): Promise<InviteDetails> {
  const { data } = await client.get<ApiResponse<InviteDetails>>(`/invite/${token}`)
  return data.data
}

export interface AcceptInviteResult {
  user: import('@/types').User
  role: string
  requires_relogin: boolean
}

export async function acceptInvite(token: string): Promise<AcceptInviteResult> {
  const { data } = await client.post<ApiResponse<AcceptInviteResult>>(
    `/invite/${token}/accept`,
  )
  return data.data
}
