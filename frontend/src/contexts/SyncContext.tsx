/* eslint-disable react-refresh/only-export-components */
import { createContext, useContext, useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { trySyncAll, queueOfflineRequest, onSyncEvent } from '@/lib/sync'
import { getSyncQueueLength } from '@/lib/db'
import { useOffline } from './OfflineContext'

interface SyncContextType {
  isSyncing: boolean
  pendingCount: number
  lastSyncResult: { synced: number; failed: number } | null
  triggerSync: () => Promise<void>
  queueRequest: (operation: 'create' | 'update' | 'delete', endpoint: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body: unknown) => Promise<number>
  refreshPendingCount: () => Promise<void>
}

const SyncContext = createContext<SyncContextType | undefined>(undefined)

export function SyncProvider({ children }: { children: ReactNode }) {
  const [isSyncing, setIsSyncing] = useState(false)
  const [pendingCount, setPendingCount] = useState(0)
  const [lastSyncResult, setLastSyncResult] = useState<{ synced: number; failed: number } | null>(null)
  const { isOnline, wasOffline, resetWasOffline } = useOffline()
  const syncingRef = useRef(false)

  const refreshPendingCount = useCallback(async () => {
    const count = await getSyncQueueLength()
    setPendingCount(count)
  }, [])

  const triggerSync = useCallback(async () => {
    if (syncingRef.current || !isOnline) return
    syncingRef.current = true
    setIsSyncing(true)
    try {
      const result = await trySyncAll()
      setLastSyncResult(result)
      await refreshPendingCount()
    } finally {
      setIsSyncing(false)
      syncingRef.current = false
    }
  }, [isOnline, refreshPendingCount])

  const queueRequest = useCallback(async (operation: 'create' | 'update' | 'delete', endpoint: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body: unknown) => {
    const token = localStorage.getItem('auth_token') || ''
    const id = await queueOfflineRequest(operation, endpoint, method, body, token)
    await refreshPendingCount()
    return id
  }, [refreshPendingCount])

  useEffect(() => {
    let active = true
    getSyncQueueLength().then((count) => {
      if (active) setPendingCount(count)
    })
    return () => { active = false }
  }, [])

  useEffect(() => {
    const unsub = onSyncEvent((event) => {
      if (event.type === 'item-complete' || event.type === 'item-failed') {
        refreshPendingCount()
      }
    })
    return () => { unsub() }
  }, [refreshPendingCount])

  useEffect(() => {
    if (wasOffline && isOnline) {
      resetWasOffline()
      triggerSync()
    }
  }, [wasOffline, isOnline, resetWasOffline, triggerSync])

  return (
    <SyncContext.Provider
      value={{
        isSyncing,
        pendingCount,
        lastSyncResult,
        triggerSync,
        queueRequest,
        refreshPendingCount,
      }}
    >
      {children}
    </SyncContext.Provider>
  )
}

export function useSync() {
  const ctx = useContext(SyncContext)
  if (!ctx) throw new Error('useSync must be used within SyncProvider')
  return ctx
}
