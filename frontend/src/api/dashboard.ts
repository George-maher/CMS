import client from './client'

export interface DashboardStats {
  total_members: number
  active_members: number
  total_attendances: number
  total_points: number
  total_servants: number
  total_members_managed: number
}

export async function getDashboardStats(): Promise<DashboardStats> {
  const { data } = await client.get<{ data: DashboardStats }>('/dashboard/stats')
  return data.data
}
