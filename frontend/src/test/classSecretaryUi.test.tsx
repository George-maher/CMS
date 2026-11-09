/**
 * Stage Administration / class management UI.
 *
 * These tests render the real screens with the API layer mocked. They cover
 * the behaviour that is easy to break without noticing:
 *
 *  - the class secretary card reflects the server's assignment;
 *  - assign / replace / remove call the right endpoint with the right id;
 *  - moving a servant sends both class ids;
 *  - a servant (including a class secretary) sees the class read-only and is
 *    offered no management controls — the backend is the real boundary, but a
 *    stray button would still be a bug;
 *  - a failed load shows a retry instead of a blank screen;
 *  - every structure/dashboard key used here exists in both EN and AR.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import en from '@/i18n/en.json'
import ar from '@/i18n/ar.json'

const { toastMock, api, auth, nav } = vi.hoisted(() => ({
  toastMock: Object.assign(vi.fn(), {
    success: vi.fn(),
    error: vi.fn(),
  }),
  api: {
    getClasseDetail: vi.fn(),
    assignServantToClasse: vi.fn(),
    removeServantFromClasse: vi.fn(),
    assignMemberToClasse: vi.fn(),
    assignClasseSecretary: vi.fn(),
    removeClasseSecretary: vi.fn(),
    listEligibleSecretaries: vi.fn(),
    moveServantBetweenClasses: vi.fn(),
    bulkCreateClasses: vi.fn(),
    createClasse: vi.fn(),
    updateClasse: vi.fn(),
    deleteClasse: vi.fn(),
    getStage: vi.fn(),
    getStageClasses: vi.fn(),
    listUsers: vi.fn(),
    promoteToStageAdmin: vi.fn(),
    demoteFromAdmin: vi.fn(),
  },
  auth: { role: 'stage_admin' as string },
  nav: { navigate: vi.fn() },
}))

vi.mock('react-hot-toast', () => ({ default: toastMock }))
vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    // Key-echo translation: assertions read the raw key, so they do not depend
    // on the shipped wording. EN/AR parity is asserted separately below.
    t: (key: string, fallback?: unknown) => (typeof fallback === 'string' ? fallback : key),
    i18n: { language: 'en', changeLanguage: vi.fn() },
  }),
}))
vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: '5' }),
  useNavigate: () => nav.navigate,
}))
vi.mock('@/hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Actor', role: auth.role, stage_id: 2, church_id: 1 },
  }),
}))
vi.mock('@/api/classes', () => ({
  getClasseDetail: api.getClasseDetail,
  assignServantToClasse: api.assignServantToClasse,
  removeServantFromClasse: api.removeServantFromClasse,
  assignMemberToClasse: api.assignMemberToClasse,
  assignClasseSecretary: api.assignClasseSecretary,
  removeClasseSecretary: api.removeClasseSecretary,
  listEligibleSecretaries: api.listEligibleSecretaries,
  moveServantBetweenClasses: api.moveServantBetweenClasses,
  bulkCreateClasses: api.bulkCreateClasses,
  createClasse: api.createClasse,
  updateClasse: api.updateClasse,
  deleteClasse: api.deleteClasse,
}))
vi.mock('@/api/stages', () => ({
  getStage: api.getStage,
  getStageClasses: api.getStageClasses,
}))
vi.mock('@/api/users', () => ({
  listUsers: api.listUsers,
  promoteToStageAdmin: api.promoteToStageAdmin,
  demoteFromAdmin: api.demoteFromAdmin,
}))

const ClasseDetail = (await import('@/pages/admin/ClasseDetail')).default
const StageDetail = (await import('@/pages/admin/StageDetail')).default
// Initialise the real i18n singleton: ThemeContext calls
// `i18n.changeLanguage()` and the bare instance is otherwise uninitialised.
await import('@/i18n')
const { ThemeProvider } = await import('@/contexts/ThemeContext')

const renderWithTheme = (ui: Parameters<typeof render>[0]) =>
  render(<ThemeProvider>{ui}</ThemeProvider>)

const SECRETARY = { id: 9, name: 'Servant One', email: 'servant1@example.com' }
const SERVANT = { id: 10, name: 'Servant Two', email: 'servant2@example.com' }

const classe = {
  id: 5,
  stage_id: 2,
  class_secretary_id: null as number | null,
  name: 'S1',
  description: null,
  display_order: 1,
  member_count: 3,
  servant_count: 1,
  secretary: null as typeof SECRETARY | null,
  stage: { id: 2, name: 'Secondary' },
  created_at: '2026-01-01T00:00:00Z',
}

function detail(overrides: Partial<typeof classe> = {}, servants = [SERVANT]) {
  const merged = { ...classe, ...overrides }
  return {
    class: merged,
    member_count: merged.member_count,
    servant_count: servants.length,
    members: [],
    servants,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  auth.role = 'stage_admin'
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  api.getStageClasses.mockResolvedValue([])
  api.listUsers.mockResolvedValue({ data: [] })
  api.listEligibleSecretaries.mockResolvedValue([SECRETARY])
  api.assignClasseSecretary.mockResolvedValue(SECRETARY)
  api.removeClasseSecretary.mockResolvedValue(undefined)
  api.moveServantBetweenClasses.mockResolvedValue(SERVANT)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('ClasseDetail — class secretary', () => {
  it('shows the assigned secretary returned by the API', async () => {
    api.getClasseDetail.mockResolvedValue(
      detail({ class_secretary_id: SECRETARY.id, secretary: SECRETARY }),
    )

    renderWithTheme(<ClasseDetail />)

    expect(await screen.findByText('Servant One')).toBeTruthy()
    expect(screen.getByText('structure.classSecretary')).toBeTruthy()
  })

  it('shows the empty state when no secretary is assigned', async () => {
    api.getClasseDetail.mockResolvedValue(detail())

    renderWithTheme(<ClasseDetail />)

    expect(await screen.findByText('structure.noClassSecretary')).toBeTruthy()
  })

  it('assigns a secretary from the eligible list', async () => {
    api.getClasseDetail.mockResolvedValue(detail())

    renderWithTheme(<ClasseDetail />)

    const assignButton = await screen.findByRole('button', { name: 'structure.assignSecretary' })
    await act(async () => {
      fireEvent.click(assignButton)
    })

    // The candidate list is fetched from the dedicated endpoint, not from the
    // general user directory, so it can never offer an ineligible servant.
    await waitFor(() => expect(api.listEligibleSecretaries).toHaveBeenCalledWith(5))

    const candidate = await screen.findByRole('button', { name: /Servant One/ })
    await act(async () => {
      fireEvent.click(candidate)
    })

    expect(api.assignClasseSecretary).toHaveBeenCalledWith(5, SECRETARY.id)
  })

  it('removes the secretary only after confirmation', async () => {
    api.getClasseDetail.mockResolvedValue(
      detail({ class_secretary_id: SECRETARY.id, secretary: SECRETARY }),
    )

    renderWithTheme(<ClasseDetail />)

    const removeButton = await screen.findByRole('button', { name: 'structure.removeSecretary' })
    await act(async () => {
      fireEvent.click(removeButton)
    })

    expect(window.confirm).toHaveBeenCalled()
    expect(api.removeClasseSecretary).toHaveBeenCalledWith(5)
  })
})

describe('ClasseDetail — servants', () => {
  it('moves a servant to another class in the same stage', async () => {
    api.getClasseDetail.mockResolvedValue(detail())
    api.getStageClasses.mockResolvedValue([
      { ...classe, id: 6, name: 'S2' },
    ])

    renderWithTheme(<ClasseDetail />)

    const openMoveButtons = await screen.findAllByRole('button', { name: 'structure.moveServant' })
    await act(async () => {
      fireEvent.click(openMoveButtons[0]!)
    })

    await waitFor(() => expect(api.getStageClasses).toHaveBeenCalledWith(2))

    const select = await screen.findByLabelText('structure.selectTargetClass')
    await act(async () => {
      fireEvent.change(select, { target: { value: '6' } })
    })

    // Both the row action and the modal submit use the same label; the modal
    // submit is the last one rendered.
    const moveButtons = screen.getAllByRole('button', { name: 'structure.moveServant' })
    await act(async () => {
      fireEvent.click(moveButtons[moveButtons.length - 1]!)
    })

    expect(api.moveServantBetweenClasses).toHaveBeenCalledWith(6, SERVANT.id, 5)
  })
})

describe('ClasseDetail — authorization presentation', () => {
  it('renders a servant class read-only with no management controls', async () => {
    auth.role = 'servant'
    api.getClasseDetail.mockResolvedValue(
      detail({ class_secretary_id: SECRETARY.id, secretary: SECRETARY }),
    )

    renderWithTheme(<ClasseDetail />)

    expect(await screen.findByText('structure.readOnlyNotice')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'structure.assignSecretary' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'structure.changeSecretary' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'structure.removeSecretary' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'structure.assignServant' })).toBeNull()
  })

  it('shows a retry when the class cannot be loaded, then recovers', async () => {
    api.getClasseDetail.mockRejectedValueOnce(new Error('network'))
    api.getClasseDetail.mockResolvedValueOnce(detail())

    renderWithTheme(<ClasseDetail />)

    expect(await screen.findByText('structure.loadFailed')).toBeTruthy()

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /common.retry/ }))
    })

    expect(await screen.findByText('S1')).toBeTruthy()
    expect(api.getClasseDetail).toHaveBeenCalledTimes(2)
  })
})

describe('StageDetail — classes and secretary', () => {
  const stage = { id: 2, name: 'Secondary', display_order: 1, classes_count: 1, created_at: '2026-01-01' }

  it('lists classes with their secretary and links role-aware', async () => {
    auth.role = 'stage_admin'
    api.getStage.mockResolvedValue(stage)
    api.getStageClasses.mockResolvedValue([
      { ...classe, class_secretary_id: SECRETARY.id, secretary: SECRETARY },
    ])

    renderWithTheme(<StageDetail />)

    expect(await screen.findByText('Servant One')).toBeTruthy()

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'structure.backToStages' }))
    })
    expect(nav.navigate).toHaveBeenCalledWith('/stage/structure')

    await act(async () => {
      fireEvent.click(screen.getByText('S1'))
    })
    expect(nav.navigate).toHaveBeenCalledWith('/stage/classes/5')
  })

  it('uses the assistant-admin base path for an assistant admin', async () => {
    auth.role = 'assistant_admin'
    api.getStage.mockResolvedValue(stage)
    api.getStageClasses.mockResolvedValue([classe])

    renderWithTheme(<StageDetail />)

    const backButton = await screen.findByRole('button', { name: 'structure.backToStages' })
    await act(async () => {
      fireEvent.click(backButton)
    })
    expect(nav.navigate).toHaveBeenCalledWith('/assistant-admin/structure')
  })
})

describe('EN/AR translation parity for the new structure keys', () => {
  const flatten = (o: Record<string, unknown>, prefix = '', out: Record<string, unknown> = {}) => {
    for (const key of Object.keys(o)) {
      const value = o[key]
      const next = prefix ? `${prefix}.${key}` : key
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        flatten(value as Record<string, unknown>, next, out)
      } else {
        out[next] = value
      }
    }
    return out
  }

  it('has every EN key in AR and vice versa', () => {
    const enKeys = Object.keys(flatten(en as Record<string, unknown>)).sort()
    const arKeys = Object.keys(flatten(ar as Record<string, unknown>)).sort()
    expect(arKeys).toEqual(enKeys)
  })

  it('includes the class-secretary keys in both languages', () => {
    const required = [
      'structure.classSecretary',
      'structure.noClassSecretary',
      'structure.assignSecretary',
      'structure.changeSecretary',
      'structure.removeSecretary',
      'structure.moveServant',
      'structure.manageClasses',
      'structure.readOnlyNotice',
      'structure.loadFailed',
      'dashboard.myClasses',
    ]
    const enKeys = flatten(en as Record<string, unknown>)
    const arKeys = flatten(ar as Record<string, unknown>)
    for (const key of required) {
      expect(Object.prototype.hasOwnProperty.call(enKeys, key), `EN missing ${key}`).toBe(true)
      expect(Object.prototype.hasOwnProperty.call(arKeys, key), `AR missing ${key}`).toBe(true)
    }
  })
})
