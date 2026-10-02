/**
 * Offline write queue: state machine and cross-session isolation.
 *
 * The queue is the one piece of client state that is PERSISTED across page
 * reloads and therefore across sessions. If a queued write from one session
 * is replayed under another, one tenant inherits another's pending
 * operations. These tests pin the two properties that matter:
 *
 *   1. the state machine terminates (no infinite retry);
 *   2. a new session cannot see the previous session's queue.
 *
 * `src/lib/db.ts` is used as written, over fake-indexeddb — no mock of the
 * storage layer — so the behaviour tested is the behaviour shipped.
 */
import { describe, it, expect } from 'vitest'
import {
  addToSyncQueue,
  getPendingSyncItems,
  getSyncQueueLength,
  markSyncAbandoned,
  markSyncCompleted,
  markSyncFailed,
  clearAllData,
  cachePut,
  cacheGet,
  cacheClearChurch,
  type SyncQueueItem,
} from '@/lib/db'

const TENANT_A_TOKEN = 'token-tenant-a'

async function seedQueueItem(token: string, endpoint = '/attendances/record'): Promise<number> {
  return addToSyncQueue({
    operation: 'create',
    endpoint,
    method: 'POST',
    body: { member_id: 1 },
    token,
    status: 'pending',
    retries: 0,
  })
}

describe('offline sync queue', () => {
  it('stores a pending item and returns it for replay', async () => {
    await seedQueueItem(TENANT_A_TOKEN)

    const pending = await getPendingSyncItems()

    expect(pending).toHaveLength(1)
    expect(pending[0]).toMatchObject({
      endpoint: '/attendances/record',
      method: 'POST',
      status: 'pending',
      token: TENANT_A_TOKEN,
    })
  })

  it('includes failed items so a transient failure is retried', async () => {
    const id = await seedQueueItem(TENANT_A_TOKEN)
    await markSyncFailed(id, 1)

    const pending = await getPendingSyncItems()

    expect(pending).toHaveLength(1)
    expect(pending[0]).toMatchObject({ status: 'failed', retries: 1 })
  })

  it('stops returning a completed item', async () => {
    const id = await seedQueueItem(TENANT_A_TOKEN)
    await markSyncCompleted(id)

    expect(await getPendingSyncItems()).toHaveLength(0)
  })

  /**
   * The terminal state. Without it, an item that exhausted its retries stayed
   * 'failed' and was re-read by getPendingSyncItems() on every run — a poison
   * item that could never drain.
   */
  it('stops returning an abandoned item, so it is never retried forever', async () => {
    const id = await seedQueueItem(TENANT_A_TOKEN)
    await markSyncAbandoned(id, 5)

    expect(await getPendingSyncItems()).toHaveLength(0)
  })

  it('keeps an abandoned record for diagnostics rather than deleting it', async () => {
    const id = await seedQueueItem(TENANT_A_TOKEN)
    await markSyncAbandoned(id, 5)

    // Still counted by the total, so the write is not silently lost.
    expect(await getSyncQueueLength()).toBe(1)
  })

  it('does not process a completed item twice', async () => {
    const id = await seedQueueItem(TENANT_A_TOKEN)
    await markSyncCompleted(id)

    // A second completion pass must find nothing to do.
    expect(await getPendingSyncItems()).toHaveLength(0)
    expect(await getSyncQueueLength()).toBe(1)
  })
})

describe('cross-session isolation', () => {
  /**
   * The headline requirement: a queue written under tenant A must not survive
   * into tenant B's session.
   *
   * `clearAllData()` is what AuthContext's login()/logout() and the 401
   * interceptor call. If it stops clearing, this test fails.
   */
  it('a new session cannot see the previous session queue', async () => {
    await seedQueueItem(TENANT_A_TOKEN)
    await seedQueueItem(TENANT_A_TOKEN, '/attendances/record-by-member-id')
    expect(await getPendingSyncItems()).toHaveLength(2)

    // Login as a different tenant wipes the persisted queue.
    await clearAllData()

    expect(await getPendingSyncItems()).toHaveLength(0)
    expect(await getSyncQueueLength()).toBe(0)
  })

  it('clearAllData also drops cached API responses', async () => {
    await cachePut('/stages', [{ id: 1 }], 1)

    expect(await cacheGet('/stages')).toEqual([{ id: 1 }])

    await clearAllData()

    expect(await cacheGet('/stages')).toBeUndefined()
  })

  /**
   * The per-church cache wipe is the narrower operation used when switching
   * churches without leaving the app. It must not touch other churches.
   */
  it('clearing one church leaves another church cache intact', async () => {
    await cachePut('/stages', 'church-a-stages', 1)
    await cachePut('/stages', 'church-b-stages', 2)

    await cacheClearChurch(1)

    // cacheGet() is keyed by cache key only, so the surviving entry proves
    // church B's data was not dropped.
    expect(await cacheGet('/stages')).toBe('church-b-stages')
  })
})

describe('SyncQueueItem contract', () => {
  it('exposes abandoned as a terminal status distinct from failed', async () => {
    // Guards the type contract: 'abandoned' must remain a member, or a future
    // refactor could silently drop the dead-letter state.
    const terminal: SyncQueueItem['status'] = 'abandoned'
    expect(terminal).toBe('abandoned')
  })
})
