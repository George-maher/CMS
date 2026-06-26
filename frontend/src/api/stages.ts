import type { Stage, Classe } from '@/types'
import client from './client'

export async function listStages(search?: string): Promise<Stage[]> {
  const params = search ? { search } : {}
  const { data } = await client.get<{ data: Stage[] }>('/stages', { params })
  return data.data
}

export async function getStage(id: number): Promise<Stage> {
  const { data } = await client.get<{ data: Stage }>(`/stages/${id}`)
  return data.data
}

export async function createStage(payload: { name: string }): Promise<Stage> {
  const { data } = await client.post<{ data: Stage }>('/stages', payload)
  return data.data
}

export async function bulkCreateStages(count: number): Promise<Stage[]> {
  const { data } = await client.post<{ data: Stage[] }>('/stages/bulk', { count })
  return data.data
}

export async function updateStage(id: number, payload: Partial<Stage>): Promise<Stage> {
  const { data } = await client.put<{ data: Stage }>(`/stages/${id}`, payload)
  return data.data
}

export async function deleteStage(id: number): Promise<void> {
  await client.delete(`/stages/${id}`)
}

export async function getStageClasses(stageId: number, search?: string): Promise<Classe[]> {
  const params = search ? { search } : {}
  const { data } = await client.get<{ data: Classe[] }>(`/stages/${stageId}/classes`, { params })
  return data.data
}
