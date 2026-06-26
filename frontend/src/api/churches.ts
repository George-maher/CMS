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
