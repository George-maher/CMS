import type { Classe, User } from '@/types'
import client from './client'

export async function listClasses(search?: string): Promise<Classe[]> {
  const params = search ? { search } : {}
  const { data } = await client.get<{ data: Classe[] }>('/classes', { params })
  return data.data
}

export async function getClasse(id: number): Promise<Classe> {
  const { data } = await client.get<{ data: Classe }>(`/classes/${id}`)
  return data.data
}

export async function createClasse(payload: { stage_id: number; name: string; description?: string }): Promise<Classe> {
  const { data } = await client.post<{ data: Classe }>('/classes', payload)
  return data.data
}

export async function updateClasse(id: number, payload: Partial<Classe>): Promise<Classe> {
  const { data } = await client.put<{ data: Classe }>(`/classes/${id}`, payload)
  return data.data
}

export async function deleteClasse(id: number): Promise<void> {
  await client.delete(`/classes/${id}`)
}

export async function getClasseDetail(id: number): Promise<{
  class: Classe
  member_count: number
  servant_count: number
  members: User[]
  servants: User[]
}> {
  const { data } = await client.get<{ data: {
    class: Classe
    member_count: number
    servant_count: number
    members: User[]
    servants: User[]
  } }>(`/classes/${id}/detail`)
  return data.data
}

export async function assignServantToClasse(classeId: number, userId: number): Promise<User> {
  const { data } = await client.post<{ data: User }>(`/classes/${classeId}/assign-servant`, { user_id: userId })
  return data.data
}

export async function removeServantFromClasse(classeId: number, userId: number): Promise<void> {
  await client.post(`/classes/${classeId}/remove-servant`, { user_id: userId })
}

export async function assignMemberToClasse(classeId: number, userId: number): Promise<User> {
  const { data } = await client.post<{ data: User }>(`/classes/${classeId}/assign-member`, { user_id: userId })
  return data.data
}

export async function reorderClasses(orderedIds: number[]): Promise<void> {
  await client.post('/classes/reorder', { ordered_ids: orderedIds })
}
