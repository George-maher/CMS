/**
 * Test environment setup.
 *
 * The offline write queue lives in IndexedDB (`idb`), which Node does not
 * provide. `fake-indexeddb` supplies a spec-compliant in-memory
 * implementation so the real `src/lib/db.ts` and `src/lib/sync.ts` can be
 * tested as written, rather than being replaced by a hand-written mock that
 * could agree with the code while disagreeing with the browser.
 */
import 'fake-indexeddb/auto'
import { afterEach, beforeEach } from 'vitest'
import { clearAllData } from '@/lib/db'

/**
 * Reset persisted state between tests.
 *
 * Unlike localStorage, IndexedDB is not cleared by clearing storage, and
 * fake-indexeddb keeps it for the lifetime of the process. Without this, a
 * queued write from one test is still present in the next, and any assertion
 * about queue length passes or fails for the wrong reason.
 *
 * The object STORES are emptied rather than the database being deleted.
 * `indexedDB.deleteDatabase()` blocks indefinitely while any connection is
 * still open, and `src/lib/db.ts` holds a cached connection for the lifetime
 * of the module — so deleting would hang rather than reset. Emptying the
 * stores achieves the same isolation without fighting the connection.
 */
beforeEach(async () => {
  localStorage.clear()
  sessionStorage.clear()
  await clearAllData()
})

afterEach(() => {
  localStorage.clear()
  sessionStorage.clear()
})
