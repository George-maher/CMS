import type {
  CreateUserPayload,
  PaginationMeta,
  User,
} from '@/types'
import client from './client'

export async function listUsers(
  params?: Record<string, string | number>,
): Promise<{ data: User[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: User[]; meta: PaginationMeta }>('/users', { params })
  return data
}

export async function getUser(id: number): Promise<User> {
  const { data } = await client.get<{ data: User }>(`/users/${id}`)
  return data.data
}

export async function getMemberDetail(id: number): Promise<User> {
  const { data } = await client.get<{ data: User }>(`/users/member-detail/${id}`)
  return data.data
}

export async function createUser(payload: CreateUserPayload): Promise<User> {
  const { data } = await client.post<{ data: User }>('/users', payload)
  return data.data
}

export async function updateUser(id: number, payload: Partial<CreateUserPayload>): Promise<User> {
  const { data } = await client.put<{ data: User }>(`/users/${id}`, payload)
  return data.data
}

export async function deleteUser(id: number): Promise<void> {
  await client.delete(`/users/${id}`)
}

export async function getServants(adminId?: number): Promise<User[]> {
  const url = adminId ? `/users/${adminId}/servants` : '/users/servants'
  const { data } = await client.get<{ data: User[] }>(url)
  return data.data
}

export async function getMembers(servantId?: number): Promise<User[]> {
  const url = servantId ? `/users/members/${servantId}` : '/users/members'
  const { data } = await client.get<{ data: User[] }>(url)
  return data.data
}

export async function promoteToAdmin(userId: number): Promise<User> {
  const { data } = await client.post<{ data: User }>(`/users/${userId}/promote`)
  return data.data
}

export async function demoteFromAdmin(userId: number, role: 'servant' | 'member'): Promise<User> {
  const { data } = await client.post<{ data: User }>(`/users/${userId}/demote`, { role })
  return data.data
}

export async function regenerateOwnQrToken(): Promise<{ token: string }> {
  const { data } = await client.post<{ data: { token: string } }>('/users/regenerate-qr-token')
  return data.data
}

import type { ClassContact } from './structure'

export async function getMyClassServants(): Promise<ClassContact[]> {
  const { data } = await client.get<{ data: ClassContact[] }>('/users/my-class-servants')
  return data.data
}
