import axios from 'axios'
import { addToSyncQueue, getPendingSyncItems, markSyncCompleted, markSyncFailed, clearCompletedSyncItems, clearAllData } from './db'

const MAX_RETRIES = 5
const RETRY_BASE_DELAY = 2000

export type SyncEventCallback = (event: { type: 'start' | 'item-complete' | 'item-failed' | 'complete' | 'error'; item?: string; message?: string }) => void

const listeners: Set<SyncEventCallback> = new Set()

export function onSyncEvent(cb: SyncEventCallback) {
  listeners.add(cb)
  return () => listeners.delete(cb)
}

function emit(event: { type: 'start' | 'item-complete' | 'item-failed' | 'complete' | 'error'; item?: string; message?: string }) {
  listeners.forEach(cb => cb(event))
}

export async function queueOfflineRequest(
  operation: 'create' | 'update' | 'delete',
  endpoint: string,
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  body: unknown,
  token: string,
): Promise<number> {
  return addToSyncQueue({ operation, endpoint, method, body, token, status: 'pending', retries: 0 })
}

export async function processSyncQueue(): Promise<void> {
  const items = await getPendingSyncItems()
  if (items.length === 0) return

  emit({ type: 'start', message: `Syncing ${items.length} item(s)...` })

  for (const item of items) {
    if (item.status === 'completed') continue

    await markSyncCompleted(item.id!)
    emit({ type: 'item-complete', item: item.endpoint })
  }

  await clearCompletedSyncItems()
  emit({ type: 'complete', message: 'Sync complete' })
}

export async function trySyncAll(): Promise<{ synced: number; failed: number }> {
  const items = await getPendingSyncItems()
  if (items.length === 0) return { synced: 0, failed: 0 }

  emit({ type: 'start', message: `Syncing ${items.length} item(s)...` })

  let synced = 0
  let failed = 0

  for (const item of items) {
    if (item.status === 'completed') {
      synced++
      continue
    }

    try {
      await axios({
        method: item.method,
        url: item.endpoint.startsWith('http') ? item.endpoint : `${import.meta.env.VITE_API_URL || '/api'}/v1${item.endpoint}`,
        data: item.body,
        headers: {
          Authorization: `Bearer ${item.token}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
      await markSyncCompleted(item.id!)
      synced++
      emit({ type: 'item-complete', item: item.endpoint })
    } catch {
      const nextRetries = item.retries + 1
      if (nextRetries >= MAX_RETRIES) {
        await markSyncFailed(item.id!, nextRetries)
        failed++
        emit({ type: 'item-failed', item: item.endpoint, message: 'Max retries reached' })
      } else {
        await markSyncFailed(item.id!, nextRetries)
        failed++
        const delay = RETRY_BASE_DELAY * Math.pow(2, item.retries)
        await new Promise(resolve => setTimeout(resolve, delay))
      }
    }
  }

  await clearCompletedSyncItems()
  emit({ type: 'complete', message: `Synced ${synced}, failed ${failed}` })

  return { synced, failed }
}

export async function clearAllSyncData(): Promise<void> {
  await clearAllData()
}
