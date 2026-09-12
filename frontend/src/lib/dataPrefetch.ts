import { getDashboardStats } from '@/api/dashboard'
import {
  getAttendanceHistory,
  getAttendanceStats,
  getFilteredAttendances,
  getTodayAttendance,
} from '@/api/attendance'
import { getMembers, listUsers } from '@/api/users'
import { listStages } from '@/api/stages'
import { getMyClasses, listStructureClasses } from '@/api/structure'
import { getMyAssignedEvents, listEvents } from '@/api/events'
import { listPasswordResetRequests } from '@/api/passwordResetRequests'
import { listProfileUpdateRequests } from '@/api/profileUpdateRequests'
import { listQRInvites } from '@/api/qr'
import { logCatch } from '@/lib/debug'
import type { UserRole } from '@/types'

let preheated = false

const RUN_DELAYS = [0, 200, 400, 600, 800, 1000, 1200, 1400, 1600, 1800, 2000, 2200, 2400]

/**
 * Preheat the in-memory request cache for the pages a user is most likely to
 * open. Requests are staggered so they never arrive as a single burst.
 *
 * Everything here mirrors the exact params each page uses on its first mount
 * (per_page=15, status filters, etc.), so the prewarmed cache key collides
 * with the key the page will generate — turning the user's first visit into a
 * cache HIT instead of a network round-trip.
 *
 * All failures are swallowed: preheating is best-effort and must never block,
 * crash, or rate-limit the app (API limiter allows 300 req/min/user).
 */
export function preheatData(role: UserRole | string): void {
  if (preheated) return
  preheated = true

  if (typeof navigator !== 'undefined' && !navigator.onLine) return

  const tasks: Array<() => Promise<unknown>> = []

  if (role === 'admin' || role === 'assistant_admin') {
    tasks.push(() => getDashboardStats())
    tasks.push(() => getTodayAttendance())
    tasks.push(() => getFilteredAttendances({ per_page: 15 }))
    tasks.push(() => listUsers({ page: 1, per_page: 15 }))
    tasks.push(() => listStages())
    tasks.push(() => listStructureClasses())
    tasks.push(() => listEvents({ page: 1, per_page: 15, upcoming: false }))
    tasks.push(() => listQRInvites({ page: 1, per_page: 15 }))
    tasks.push(() => listPasswordResetRequests({ page: 1, per_page: 15 }))
    tasks.push(() => listPasswordResetRequests({ page: 1, per_page: 15, status: 'pending' }))
    tasks.push(() => listPasswordResetRequests({ page: 1, per_page: 15, status: 'approved' }))
    tasks.push(() => listPasswordResetRequests({ page: 1, per_page: 15, status: 'rejected' }))
    tasks.push(() => listPasswordResetRequests({ page: 1, per_page: 15, status: 'completed' }))
    tasks.push(() => listProfileUpdateRequests({ page: 1, per_page: 15 }))
    tasks.push(() => listProfileUpdateRequests({ page: 1, per_page: 15, status: 'pending' }))
    tasks.push(() => listProfileUpdateRequests({ page: 1, per_page: 15, status: 'approved' }))
    tasks.push(() => listProfileUpdateRequests({ page: 1, per_page: 15, status: 'rejected' }))
  }

  if (role === 'servant') {
    tasks.push(() => getDashboardStats())
    tasks.push(() => getMembers())
    tasks.push(() => getTodayAttendance())
    tasks.push(() => getMyClasses())
    tasks.push(() => getFilteredAttendances({ per_page: 15 }))
    tasks.push(() => listEvents({ upcoming: true, per_page: 3 }))
    tasks.push(() => getMyAssignedEvents({ page: 1, per_page: 15 }))
    tasks.push(() => listQRInvites({ page: 1, per_page: 15 }))
  }

  if (role === 'member') {
    tasks.push(() => getAttendanceStats())
    tasks.push(() => getAttendanceHistory(undefined, { page: 1, per_page: 5 }))
    tasks.push(() => getAttendanceHistory(undefined, { page: 1, per_page: 15 }))
    tasks.push(() => listEvents({ upcoming: true, active_only: true, per_page: 3 }))
    tasks.push(() => listEvents({ per_page: 50 }))
  }

  for (let i = 0; i < tasks.length; i++) {
    const task = tasks[i] as (() => Promise<unknown>) | undefined
    if (!task) continue
    const delay = RUN_DELAYS[i] ?? 3000
    const run = () => task().catch((error) => logCatch('DataPreheat', error))
    if (delay === 0) {
      void run()
    } else {
      setTimeout(run, delay)
    }
  }
}

/** Re-enable preheating for the next app session (tests / HMR). */
export function resetPreheat(): void {
  preheated = false
}