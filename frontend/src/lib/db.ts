import { openDB, type IDBPDatabase } from 'idb'

const DB_NAME = 'church-manager'
const DB_VERSION = 1

export interface SyncQueueItem {
  id?: number
  operation: 'create' | 'update' | 'delete'
  endpoint: string
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body: unknown
  token: string
  status: 'pending' | 'processing' | 'failed' | 'completed'
  retries: number
  createdAt: number
  updatedAt: number
}

export interface CacheEntry {
  key: string
  data: unknown
  churchId: number | null
  timestamp: number
}

let dbPromise: Promise<IDBPDatabase> | null = null

function getDb(): Promise<IDBPDatabase> {
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, DB_VERSION, {
      upgrade(db) {
        if (!db.objectStoreNames.contains('syncQueue')) {
          const queue = db.createObjectStore('syncQueue', { keyPath: 'id', autoIncrement: true })
          queue.createIndex('status', 'status', { unique: false })
          queue.createIndex('createdAt', 'createdAt', { unique: false })
        }
        if (!db.objectStoreNames.contains('cache')) {
          const cache = db.createObjectStore('cache', { keyPath: 'key' })
          cache.createIndex('churchId', 'churchId', { unique: false })
        }
      },
    })
  }
  return dbPromise
}

export async function addToSyncQueue(item: Omit<SyncQueueItem, 'id' | 'createdAt' | 'updatedAt'>): Promise<number> {
  const db = await getDb()
  const now = Date.now()
  return db.add('syncQueue', { ...item, createdAt: now, updatedAt: now }) as Promise<number>
}

export async function getPendingSyncItems(): Promise<SyncQueueItem[]> {
  const db = await getDb()
  const index = db.transaction('syncQueue').store.index('status')
  const pending = await index.getAll('pending')
  const failed = await index.getAll('failed')
  return [...pending, ...failed]
}

export async function updateSyncItem(id: number, updates: Partial<SyncQueueItem>): Promise<void> {
  const db = await getDb()
  const tx = db.transaction('syncQueue', 'readwrite')
  const item = await tx.store.get(id)
  if (item) {
    Object.assign(item, updates, { updatedAt: Date.now() })
    await tx.store.put(item)
  }
  await tx.done
}

export async function markSyncCompleted(id: number): Promise<void> {
  await updateSyncItem(id, { status: 'completed' })
}

export async function markSyncFailed(id: number, retries: number): Promise<void> {
  await updateSyncItem(id, { status: 'failed', retries })
}

export async function clearCompletedSyncItems(): Promise<void> {
  const db = await getDb()
  const tx = db.transaction('syncQueue', 'readwrite')
  let cursor = await tx.store.index('status').openCursor('completed')
  while (cursor) {
    cursor.delete()
    cursor = await cursor.continue()
  }
  await tx.done
}

export async function getSyncQueueLength(): Promise<number> {
  const db = await getDb()
  return db.count('syncQueue')
}

export async function cachePut(key: string, data: unknown, churchId: number | null): Promise<void> {
  const db = await getDb()
  await db.put('cache', { key, data, churchId, timestamp: Date.now() })
}

export async function cacheGet<T>(key: string): Promise<T | undefined> {
  const db = await getDb()
  const entry = await db.get('cache', key)
  return entry?.data as T | undefined
}

export async function cacheDelete(key: string): Promise<void> {
  const db = await getDb()
  await db.delete('cache', key)
}

export async function cacheClearChurch(churchId: number): Promise<void> {
  const db = await getDb()
  const tx = db.transaction('cache', 'readwrite')
  let cursor = await tx.store.index('churchId').openCursor(churchId)
  while (cursor) {
    cursor.delete()
    cursor = await cursor.continue()
  }
  await tx.done
}

export async function clearAllData(): Promise<void> {
  const db = await getDb()
  await db.clear('syncQueue')
  await db.clear('cache')
}

export async function deleteDatabase(): Promise<void> {
  dbPromise = null
  indexedDB.deleteDatabase(DB_NAME)
}
