import type { AddBonusPointsPayload, ApiResponse, LeaderboardEntry, PaginationMeta, Point, StageLeaderboard } from '@/types'
import client from './client'

export async function getBalance(): Promise<number> {
  const { data } = await client.get<ApiResponse<{ balance: number }>>('/points/balance')
  return data.data.balance
}

export async function getPointsHistory(
  params?: Record<string, string | number>,
): Promise<{ data: Point[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: Point[]; meta: PaginationMeta }>('/points/history', { params })
  return data
}

export async function getLeaderboard(limit = 10): Promise<{ data: { rank: number; user_id: number; name: string; total_points: number; email?: string }[] }> {
  const { data } = await client.get('/points/leaderboard', { params: { limit } })
  return data
}

export async function getUserBalance(userId: number): Promise<number> {
  const { data } = await client.get<ApiResponse<{ balance: number }>>(`/points/user/${userId}/balance`)
  return data.data.balance
}

export async function getUserPointsHistory(
  userId: number,
  params?: Record<string, string | number>,
): Promise<{ data: Point[]; meta: PaginationMeta }> {
  const { data } = await client.get<{ data: Point[]; meta: PaginationMeta }>(`/points/user/${userId}/history`, { params })
  return data
}

export async function addBonusPoints(payload: AddBonusPointsPayload): Promise<{ point: Point; balance: number }> {
  const { data } = await client.post<ApiResponse<{ point: Point; balance: number }>>('/points/bonus', payload)
  return data.data
}

export async function getGlobalLeaderboard(): Promise<LeaderboardEntry[]> {
  const { data } = await client.get<{ data: LeaderboardEntry[] }>('/leaderboard/global')
  return data.data
}

export async function getClassLeaderboard(classId: number): Promise<{
  class: { id: number; name: string }
  stage: { id: number; name: string } | null
  leaderboard: LeaderboardEntry[]
}> {
  const { data } = await client.get<{ data: { class: { id: number; name: string }; stage: { id: number; name: string } | null; leaderboard: LeaderboardEntry[] } }>(`/leaderboard/class/${classId}`)
  return data.data
}

export async function getMyClassLeaderboard(): Promise<{
  class: { id: number; name: string } | null
  stage: { id: number; name: string } | null
  leaderboard: LeaderboardEntry[]
}> {
  const { data } = await client.get<{ data: { class: { id: number; name: string } | null; stage: { id: number; name: string } | null; leaderboard: LeaderboardEntry[] } }>('/leaderboard/my-class')
  return data.data
}

export async function getMyClassesLeaderboards(): Promise<{
  class: { id: number; name: string }
  stage: { id: number; name: string } | null
  leaderboard: LeaderboardEntry[]
}[]> {
  const { data } = await client.get<{ data: { class: { id: number; name: string }; stage: { id: number; name: string } | null; leaderboard: LeaderboardEntry[] }[] }>('/leaderboard/my-classes')
  return data.data
}

export async function getStagesLeaderboards(): Promise<StageLeaderboard[]> {
  const { data } = await client.get<{ data: StageLeaderboard[] }>('/leaderboard/stages')
  return data.data
}
