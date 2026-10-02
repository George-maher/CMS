/**
 * ScanQR must consume the API layer's `__offline_queued` sentinel.
 *
 * Phase 1C finding A-2. The request interceptor in `src/api/client.ts`
 * rejects offline-writable requests with `{ __offline_queued: true }` after
 * taking ownership of the write (stored in IndexedDB for replay). The
 * confirmAttendance catch block did not know about that sentinel, so it
 * treated the capture as a failure:
 *
 *   - a red "Failed to record attendance." banner plus a failure toast, and
 *   - the pending-member state left intact,
 *
 * which invites the servant to press Confirm / re-scan — queueing a SECOND
 * copy of the same attendance write. The queued item would later replay
 * against the backend's duplicate guard (422), but the servant saw a failure
 * for a write that was in fact safely captured, and the duplicate attempt
 * burns a scan and a network round-trip for nothing.
 *
 * These tests render the REAL component (ThemeProvider included — useTheme
 * throws without it) and drive the real lookup → confirm flow, mocking only
 * the API modules and the toast/i18n layers. The queued state is asserted as
 * a THIRD outcome: distinct from both the green success and the red failure,
 * with the flow reset so the same member cannot be confirmed twice.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

const { toastMock, api } = vi.hoisted(() => ({
  toastMock: Object.assign(vi.fn(), {
    success: vi.fn(),
    error: vi.fn(),
    loading: vi.fn(),
    dismiss: vi.fn(),
  }),
  api: {
    lookupByToken: vi.fn(),
    lookupByMemberId: vi.fn(),
    recordAttendance: vi.fn(),
    recordAttendanceByMemberId: vi.fn(),
    getTodayAttendance: vi.fn(),
    listEvents: vi.fn(),
    getActiveContexts: vi.fn(),
    getMembers: vi.fn(),
    listAllClasses: vi.fn(),
  },
}))

vi.mock('react-hot-toast', () => ({ default: toastMock }))
vi.mock('react-i18next', () => ({
  // The real `src/i18n/index.ts` (pulled in transitively by `@/lib/dates`)
  // registers this plugin during i18next bootstrap — it must exist on the
  // mock or the module graph fails to load. A no-op init is enough: the
  // component under test uses the mocked useTranslation below.
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    // Key-echo translation: assertions read the raw key, which keeps them
    // independent of the shipped EN/AR wording (parity is checked by
    // `npm run check:i18n` separately).
    t: (key: string, fallback?: unknown) => (typeof fallback === 'string' ? fallback : key),
    i18n: { language: 'en', changeLanguage: vi.fn() },
  }),
}))
vi.mock('@/api/attendance', () => ({
  lookupByToken: api.lookupByToken,
  lookupByMemberId: api.lookupByMemberId,
  recordAttendance: api.recordAttendance,
  recordAttendanceByMemberId: api.recordAttendanceByMemberId,
  getTodayAttendance: api.getTodayAttendance,
}))
vi.mock('@/api/events', () => ({ listEvents: api.listEvents }))
vi.mock('@/api/attendanceContexts', () => ({ getActiveContexts: api.getActiveContexts }))
vi.mock('@/api/users', () => ({ getMembers: api.getMembers }))
vi.mock('@/api/structure', () => ({ listAllClasses: api.listAllClasses }))

const { ThemeProvider } = await import('@/contexts/ThemeContext')
const ServantScanQR = (await import('@/pages/servant/ScanQR')).default

const MEMBER = { id: 11, name: 'Mary', member_id: 'MBR-1', classe: { id: 1, name: 'Class A' } }

function mount() {
  return render(
    <ThemeProvider>
      <ServantScanQR />
    </ThemeProvider>,
  )
}

/** Drive the real UI: type a token, submit the lookup, press Confirm. */
async function lookupAndConfirm() {
  mount()

  const input = await screen.findByPlaceholderText('attendance.tokenPlaceholder')
  fireEvent.change(input, { target: { value: 'QR-TOKEN-1' } })

  const form = input.closest('form')
  if (!form) throw new Error('the lookup form was not found')

  await act(async () => {
    fireEvent.submit(form)
  })

  const confirmButton = await screen.findByRole('button', { name: 'attendance.confirmAttendance' })
  await act(async () => {
    fireEvent.click(confirmButton)
  })
}

describe('ScanQR offline-queued confirmation (A-2)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.listEvents.mockResolvedValue({ data: [] })
    api.getActiveContexts.mockResolvedValue([{ id: 3, name: 'Sunday School' }])
    api.listAllClasses.mockResolvedValue([])
    api.getTodayAttendance.mockResolvedValue({ data: [] })
    api.getMembers.mockResolvedValue([])
    api.lookupByToken.mockResolvedValue({
      member: MEMBER,
      attendance_context_id: 3,
      attendance_context: { id: 3, name: 'Sunday School' },
    })
  })

  afterEach(() => {
    cleanup()
  })

  it('reports a queued write as queued, not as a failure', async () => {
    // The exact rejection shape the request interceptor produces.
    api.recordAttendance.mockRejectedValue({
      __offline_queued: true,
      message: 'Request queued for sync',
    })

    await lookupAndConfirm()

    // The third state: the amber "queued" banner, not the red failure.
    expect(await screen.findByText('attendance.queuedOffline')).toBeTruthy()
    expect(screen.queryByText('attendance.failedToRecord')).toBeNull()
    expect(toastMock.error).not.toHaveBeenCalled()
    expect(toastMock).toHaveBeenCalledWith('attendance.queuedOffline', expect.anything())
  })

  it('resets the confirmation flow so the same member cannot be confirmed twice', async () => {
    api.recordAttendance.mockRejectedValue({ __offline_queued: true })

    await lookupAndConfirm()
    await screen.findByText('attendance.queuedOffline')

    // The pending card (and its Confirm button) is gone — a second press is
    // impossible without a fresh lookup, which is what stops the duplicate
    // queue entry the old failure path invited. Both state changes happen in
    // the same handler, so once the banner is visible the card is settled.
    expect(screen.queryByRole('button', { name: 'attendance.confirmAttendance' })).toBeNull()
    expect(screen.queryByText(MEMBER.name)).toBeNull()

    // The record request was made exactly once.
    expect(api.recordAttendance).toHaveBeenCalledTimes(1)

    // The token input is cleared for the next scan.
    const input = screen.getByPlaceholderText('attendance.tokenPlaceholder') as HTMLInputElement
    expect(input.value).toBe('')
  })

  it('skips the today-list refresh for a write that is not recorded yet', async () => {
    api.recordAttendance.mockRejectedValue({ __offline_queued: true })

    await lookupAndConfirm()
    await screen.findByText('attendance.queuedOffline')

    // The write has not reached the server, so there is nothing to refresh;
    // the read would also fail while the device is offline.
    expect(api.getTodayAttendance).not.toHaveBeenCalled()
  })

  it('still reports a genuine server rejection as a failure', async () => {
    api.recordAttendance.mockRejectedValue({
      response: { data: { message: 'Already recorded today.' } },
    })

    await lookupAndConfirm()

    expect(await screen.findByText('Already recorded today.')).toBeTruthy()
    expect(screen.queryByText('attendance.queuedOffline')).toBeNull()
    expect(toastMock.error).toHaveBeenCalledWith('Already recorded today.')
  })
})
