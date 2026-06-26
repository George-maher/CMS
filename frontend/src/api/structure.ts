import type { StageWithClasses } from '@/types'
import client from './client'

export async function listStructureClasses(search?: string): Promise<StageWithClasses[]> {
  const params = search ? { search } : {}
  const { data } = await client.get<{ data: StageWithClasses[] }>('/structure/classes', { params })
  return data.data
}

export async function stagesWithClasses(): Promise<StageWithClasses[]> {
  const { data } = await client.get<StageWithClasses[]>('/structure-management/stages-with-classes')
  return data
}

export async function listAllClasses(search?: string): Promise<{ stage_id: number; stage_name: string; id: number; name: string }[]> {
  const structure = await listStructureClasses(search)
  const result: { stage_id: number; stage_name: string; id: number; name: string }[] = []
  for (const stage of structure) {
    for (const cls of stage.classes) {
      result.push({ stage_id: stage.id, stage_name: stage.name, id: cls.id, name: cls.name })
    }
  }
  return result
}

export async function getMyClasses(): Promise<{ id: number; name: string }[]> {
  const { data } = await client.get<{ data: { id: number; name: string }[] }>('/structure/my-classes')
  return data.data
}

export interface ClassContact {
  id: number
  name: string
  phone: string | null
  avatar: string | null
  role: string
  role_label: string
  type: 'servant' | 'admin' | 'assistant_admin'
}

export async function getMyClassServants(): Promise<ClassContact[]> {
  const { data } = await client.get<{ data: ClassContact[] }>('/users/my-class-servants')
  return data.data
}
