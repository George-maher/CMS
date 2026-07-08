import type { ChurchDeletionSummary } from '@/types'
import client from './client'

export async function getDeletionSummary(id: number): Promise<ChurchDeletionSummary> {
  const { data } = await client.get(`/platform/churches/${id}/deletion-summary`)
  return data.data
}

export async function softDeleteChurch(id: number, confirmation: string, password: string): Promise<{ message: string }> {
  const { data } = await client.post(`/platform/churches/${id}/soft-delete`, { confirmation, password })
  return data
}

export async function restoreChurch(id: number, confirmation: string, password: string): Promise<{ message: string }> {
  const { data } = await client.post(`/platform/churches/${id}/restore`, { confirmation, password })
  return data
}

export async function hardDeleteChurch(id: number, confirmation: string, password: string): Promise<{ message: string }> {
  const { data } = await client.post(`/platform/churches/${id}/hard-delete`, { confirmation, password })
  return data
}

export interface DeletedChurchListItem {
  id: number
  name: string
  slug: string
  priest_name: string | null
  contact_email: string | null
  is_active: boolean
  users_count: number
  deleted_at: string
  deleted_by: { id: number; name: string; email: string } | null
  deletion_type: string | null
  recoverable_until: string | null
  created_at: string
}

export interface DeletedChurchDetail extends DeletedChurchListItem {
  service_name: string | null
  main_servant_name: string | null
  priest_phone: string | null
  phone: string | null
  address: string | null
  description: string | null
  is_suspended: boolean
  member_count: number
  updated_at: string
}

export interface DeletedChurchesResponse {
  data: DeletedChurchListItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export async function getDeletedChurches(params: Record<string, string | number | undefined>): Promise<DeletedChurchesResponse> {
  const { data } = await client.get('/platform/churches/deleted-history', { params })
  return data
}

export async function getDeletedChurchDetail(id: number): Promise<{ data: DeletedChurchDetail }> {
  const { data } = await client.get(`/platform/churches/${id}/deleted-detail`)
  return data
}
