import type { ApiResponse, DailySpiritualRecord } from '@/types'
import client from './client'

export async function getSpiritualRecords(params?: {
  member_id?: number
  date_from?: string
  date_to?: string
}): Promise<{ data: DailySpiritualRecord[] }> {
  const { data } = await client.get<{ data: DailySpiritualRecord[] }>('/spiritual-records', { params })
  return data
}

export async function getSpiritualRecordByDate(
  activityDate: string,
  params?: { member_id?: number },
): Promise<DailySpiritualRecord | null> {
  try {
    const { data } = await client.get<{ data: DailySpiritualRecord }>(
      `/spiritual-records/${activityDate}`,
      { params },
    )
    return data.data
  } catch {
    return null
  }
}

export async function saveSpiritualRecord(payload: {
  activity_date: string
  attended_mass?: boolean
  confessed?: boolean
  received_communion?: boolean
}): Promise<DailySpiritualRecord> {
  const { data } = await client.post<ApiResponse<DailySpiritualRecord>>(
    '/spiritual-records',
    payload,
  )
  return data.data
}

export async function deleteSpiritualRecord(activityDate: string): Promise<void> {
  await client.delete(`/spiritual-records/${activityDate}`)
}
