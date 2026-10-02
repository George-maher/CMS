/**
 * Authentication session isolation.
 *
 * The scenario this file exists for:
 *
 *   login as tenant A  →  queue an offline write  →  login as tenant B
 *   →  tenant A's queued writes must be gone.
 *
 * A shared device, an expired token that is discarded before a request is
 * made, or signing in as a different user are all ways a session can be
 * replaced without logout() running. If the persisted offline queue survives
 * that, tenant B replays tenant A's pending attendance writes.
 *
 * The API layer is mocked; the real AuthContext, the real IndexedDB queue and
 * the real request cache are used.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, render, waitFor, cleanup } from '@testing-library/react'
import { useContext } from 'react'

const authApi = {
  login: vi.fn(),
  platformLogin: vi.fn(),
  register: vi.fn(),
  logout: vi.fn(),
  getMe: vi.fn(),
}

vi.mock('@/api/auth', () => ({ ...authApi }))
// `@/api/client` is deliberately NOT mocked. Its `clearRequestCache()` is a
// thin wrapper over the real `invalidateCache()`, and that call is the
// mechanism under test — mocking it would stub out the behaviour being proved.

const { AuthProvider, AuthContext } = await import('@/contexts/AuthContext')
const { addToSyncQueue, getPendingSyncItems, getSyncQueueLength, cachePut, cacheGet } = await import(
  '@/lib/db'
)
const { setCache, getCached, invalidateCache } = await import('@/lib/requestCache')

const CHURCH_A_TOKEN = 'token-tenant-a'
const CHURCH_B_TOKEN = 'token-tenant-b'

function user(churchId: number, role = 'admin') {
  return { id: churchId * 10, name: `Admin ${churchId}`, email: `a${churchId}@test.com`, role, church_id: churchId }
}

function Probe() {
  const { login, platformLogin, logout, refreshUser, user: current, token, isLoading } =
    useContext(AuthContext)!
  return (
    <button
      data-testid="probe"
      onClick={async () => {
        const action = (globalThis as { __action?: string }).__action
        if (action === 'login') await login({ email: 'x', password: 'y' })
        if (action === 'platformLogin') await platformLogin({ email: 'x', password: 'y' })
        if (action === 'logout') await logout()
        if (action === 'refresh') await refreshUser()
      }}
    >
      {isLoading ? 'loading' : `${current?.church_id ?? 'none'}/${token ?? 'none'}`}
    </button>
  )
}

function mount() {
  return render(
    <AuthProvider>
      <Probe />
    </AuthProvider>,
  )
}

async function trigger(action: string) {
  ;(globalThis as { __action?: string }).__action = action
  const { getByTestId } = mount()
  await act(async () => {
    getByTestId('probe').click()
  })
}

describe('auth session isolation', () => {
  beforeEach(() => {
    for (const fn of Object.values(authApi)) fn.mockReset()
    authApi.login.mockResolvedValue({ token: CHURCH_A_TOKEN, user: user(1) })
    authApi.platformLogin.mockResolvedValue({ token: CHURCH_B_TOKEN, user: user(0, 'platform_admin') })
    authApi.logout.mockResolvedValue(undefined)
    authApi.getMe.mockResolvedValue(user(1))
    invalidateCache()
  })

  afterEach(() => {
    cleanup()
  })

  it('a new login wipes the previous session offline queue', async () => {
    // Tenant A is mid-session with pending offline writes.
    await trigger('login')
    await addToSyncQueue({
      operation: 'create',
      endpoint: '/attendances/record',
      method: 'POST',
      body: { member_id: 1 },
      token: CHURCH_A_TOKEN,
      status: 'pending',
      retries: 0,
    })
    expect(await getPendingSyncItems()).toHaveLength(1)

    // Now a different tenant signs in on the same device.
    authApi.login.mockResolvedValue({ token: CHURCH_B_TOKEN, user: user(2) })
    cleanup()
    await trigger('login')

    await waitFor(async () => {
      expect(await getPendingSyncItems()).toHaveLength(0)
    })
    expect(await getSyncQueueLength()).toBe(0)
  })

  it('a new login wipes cached API responses from the previous session', async () => {
    setCache(`${CHURCH_A_TOKEN}:/stages:{}`, 'tenant-a-stages')
    expect(getCached(`${CHURCH_A_TOKEN}:/stages:{}`)).toBe('tenant-a-stages')

    await trigger('login')

    await waitFor(() => {
      expect(getCached(`${CHURCH_A_TOKEN}:/stages:{}`)).toBeNull()
    })
  })

  it('a platform login also wipes the previous tenant queue', async () => {
    await trigger('login')
    await addToSyncQueue({
      operation: 'create',
      endpoint: '/attendances/record',
      method: 'POST',
      body: {},
      token: CHURCH_A_TOKEN,
      status: 'pending',
      retries: 0,
    })
    expect(await getSyncQueueLength()).toBe(1)

    cleanup()
    await trigger('platformLogin')

    await waitFor(async () => {
      expect(await getPendingSyncItems()).toHaveLength(0)
    })
  })

  it('logout clears the session, the queue and the cache', async () => {
    await trigger('login')
    await addToSyncQueue({
      operation: 'create',
      endpoint: '/attendances/record',
      method: 'POST',
      body: {},
      token: CHURCH_A_TOKEN,
      status: 'pending',
      retries: 0,
    })
    setCache(`${CHURCH_A_TOKEN}:/stages:{}`, 'cached')
    cleanup()

    await trigger('logout')

    expect(authApi.logout).toHaveBeenCalled()
    expect(localStorage.getItem('auth_token')).toBeNull()
    expect(localStorage.getItem('auth_user')).toBeNull()
    expect(localStorage.getItem('auth_validated_at')).toBeNull()

    await waitFor(async () => {
      expect(await getSyncQueueLength()).toBe(0)
    })
    expect(getCached(`${CHURCH_A_TOKEN}:/stages:{}`)).toBeNull()
  })

  it('logging in twice in a row still ends with only the newest session', async () => {
    await trigger('login')
    cleanup()

    authApi.login.mockResolvedValue({ token: CHURCH_B_TOKEN, user: user(2) })
    await trigger('login')

    expect(localStorage.getItem('auth_token')).toBe(CHURCH_B_TOKEN)
  })

  /**
   * The offline-first requirement: a flaky connection must NOT destroy the
   * session. Only an explicit 401 from the server does.
   */
  it('a network failure during revalidation keeps the session', async () => {
    localStorage.setItem('auth_token', CHURCH_A_TOKEN)
    localStorage.setItem('auth_user', JSON.stringify(user(1)))
    // Force revalidation rather than trusting the 5-minute freshness window.
    localStorage.setItem('auth_validated_at', '0')

    authApi.getMe.mockRejectedValue(Object.assign(new Error('Network Error'), { response: undefined }))

    mount()

    await waitFor(() => {
      expect(authApi.getMe).toHaveBeenCalled()
    })
    // The cached session must survive an offline revalidation attempt.
    expect(localStorage.getItem('auth_token')).toBe(CHURCH_A_TOKEN)
  })

  /**
   * An expired token is authoritative: the server said 401, so the local
   * session must be discarded rather than retried forever.
   */
  it('a 401 during revalidation clears the local session', async () => {
    localStorage.setItem('auth_token', 'expired-token')
    localStorage.setItem('auth_user', JSON.stringify(user(1)))
    localStorage.setItem('auth_validated_at', '0')

    authApi.getMe.mockRejectedValue(Object.assign(new Error('Unauthorized'), { response: { status: 401 } }))

    mount()

    await waitFor(() => {
      expect(localStorage.getItem('auth_token')).toBeNull()
    })
    expect(localStorage.getItem('auth_user')).toBeNull()
  })

  /**
   * A fresh session is trusted without a network call, which is what makes the
   * app usable offline on launch.
   */
  it('a recently validated session is trusted without calling the API', async () => {
    localStorage.setItem('auth_token', CHURCH_A_TOKEN)
    localStorage.setItem('auth_user', JSON.stringify(user(1)))
    localStorage.setItem('auth_validated_at', String(Date.now()))

    mount()

    await waitFor(() => {
      expect(authApi.getMe).not.toHaveBeenCalled()
    })
    expect(localStorage.getItem('auth_token')).toBe(CHURCH_A_TOKEN)
  })

  it('the IndexedDB cache store is cleared by a session change', async () => {
    // Belt and braces: clearAllData() also empties the `cache` object store,
    // which is separate from the in-memory request cache.
    await cachePut('/stages', 'tenant-a-stages', 1)
    expect(await cacheGet('/stages')).toBe('tenant-a-stages')

    await trigger('login')

    await waitFor(async () => {
      expect(await cacheGet('/stages')).toBeUndefined()
    })
  })
})
