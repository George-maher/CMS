import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ArrowLeft, UserPlus, Users, UserCheck, BookOpen, ShieldCheck, ArrowRightLeft, RefreshCw } from 'lucide-react'
import DataTable from '@/components/common/DataTable'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { Classe, User } from '@/types'
import {
  getClasseDetail,
  assignServantToClasse,
  removeServantFromClasse,
  assignMemberToClasse,
  assignClasseSecretary,
  removeClasseSecretary,
  listEligibleSecretaries,
  moveServantBetweenClasses,
} from '@/api/classes'
import { getStageClasses } from '@/api/stages'
import { listUsers } from '@/api/users'
import { useAuth } from '@/hooks/useAuth'
import { canManageStructure, structureBasePath } from '@/lib/roles'
import { logCatch } from '@/lib/debug'

interface ClassDetail {
  class: Classe
  member_count: number
  servant_count: number
  members: User[]
  servants: User[]
}

export default function ClasseDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { user } = useAuth()

  const basePath = structureBasePath(user?.role)
  const canManage = canManageStructure(user?.role)

  const [detail, setDetail] = useState<ClassDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [showAssignServant, setShowAssignServant] = useState(false)
  const [showAssignMember, setShowAssignMember] = useState(false)
  const [availableServants, setAvailableServants] = useState<User[]>([])
  const [availableMembers, setAvailableMembers] = useState<User[]>([])
  const [assigning, setAssigning] = useState(false)

  const [showSecretary, setShowSecretary] = useState(false)
  const [secretaryCandidates, setSecretaryCandidates] = useState<User[]>([])
  const [secretarySearch, setSecretarySearch] = useState('')
  const [secretarySaving, setSecretarySaving] = useState(false)
  const [secretaryError, setSecretaryError] = useState(false)

  const [movingServant, setMovingServant] = useState<User | null>(null)
  const [stageClasses, setStageClasses] = useState<Classe[]>([])
  const [targetClassId, setTargetClassId] = useState<number | ''>('')
  const [moving, setMoving] = useState(false)

  const fetchDetail = useCallback(async () => {
    if (!id) return
    try {
      const res = await getClasseDetail(Number(id))
      setDetail(res)
      setLoadError(false)
    } catch (e) {
      logCatch('ClasseDetail.fetchDetail', e)
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    setLoading(true)
    fetchDetail()
  }, [fetchDetail])

  const openAssignSecretary = async () => {
    setSecretarySearch('')
    setSecretaryError(false)
    setShowSecretary(true)
    setSecretaryCandidates([])
    try {
      const users = await listEligibleSecretaries(Number(id))
      setSecretaryCandidates(users)
    } catch (e) {
      logCatch('ClasseDetail.openAssignSecretary', e)
      setSecretaryError(true)
    }
  }

  const handleAssignSecretary = async (userId: number) => {
    if (secretarySaving) return
    setSecretarySaving(true)
    try {
      await assignClasseSecretary(Number(id), userId)
      setShowSecretary(false)
      await fetchDetail()
      toast.success(t('structure.secretaryAssigned'))
    } catch (e) {
      logCatch('ClasseDetail.assignSecretary', e)
      toast.error(t('structure.secretaryAssignmentFailed'))
    } finally {
      setSecretarySaving(false)
    }
  }

  const handleRemoveSecretary = async () => {
    if (!window.confirm(t('structure.removeSecretary'))) return
    try {
      await removeClasseSecretary(Number(id))
      await fetchDetail()
      toast.success(t('structure.secretaryRemoved'))
    } catch (e) {
      logCatch('ClasseDetail.removeSecretary', e)
      toast.error(t('structure.secretaryAssignmentFailed'))
    }
  }

  const openAssignServant = async () => {
    try {
      const users = await listUsers({
        role: 'servant',
        stage_id: detail?.class.stage_id ?? '',
        per_page: 100,
      })
      const assignedIds = new Set(detail?.servants.map((s) => s.id) ?? [])
      setAvailableServants(users.data.filter((u) => !assignedIds.has(u.id)))
      setShowAssignServant(true)
    } catch (e) {
      logCatch('ClasseDetail.openAssignServant', e)
      toast.error(t('structure.loadFailed'))
    }
  }

  const openAssignMember = async () => {
    try {
      const users = await listUsers({ role: 'member', per_page: 100 })
      setAvailableMembers(users.data.filter((u) => u.class_id !== Number(id)))
      setShowAssignMember(true)
    } catch (e) {
      logCatch('ClasseDetail.openAssignMember', e)
      toast.error(t('structure.loadFailed'))
    }
  }

  const handleAssignServant = async (userId: number) => {
    setAssigning(true)
    try {
      await assignServantToClasse(Number(id), userId)
      setShowAssignServant(false)
      await fetchDetail()
      toast.success(t('structure.assignServant'))
    } catch (e) {
      logCatch('ClasseDetail.assignServant', e)
      toast.error(t('common.failedToSave'))
    } finally {
      setAssigning(false)
    }
  }

  const handleRemoveServant = async (userId: number) => {
    if (!window.confirm(t('common.remove'))) return
    try {
      await removeServantFromClasse(Number(id), userId)
      await fetchDetail()
      toast.success(t('common.remove'))
    } catch (e) {
      logCatch('ClasseDetail.removeServant', e)
      toast.error(t('common.failedToSave'))
    }
  }

  const openMoveServant = async (servant: User) => {
    setMovingServant(servant)
    setTargetClassId('')
    setStageClasses([])
    try {
      const classes = await getStageClasses(detail?.class.stage_id ?? 0)
      setStageClasses(classes.filter((c) => c.id !== Number(id)))
    } catch (e) {
      logCatch('ClasseDetail.openMoveServant', e)
      toast.error(t('structure.loadFailed'))
    }
  }

  const handleMoveServant = async () => {
    if (!movingServant || !targetClassId || moving) return
    setMoving(true)
    try {
      await moveServantBetweenClasses(Number(targetClassId), movingServant.id, Number(id))
      setMovingServant(null)
      await fetchDetail()
      toast.success(t('structure.servantMoved'))
    } catch (e) {
      logCatch('ClasseDetail.moveServant', e)
      toast.error(t('structure.moveServantFailed'))
    } finally {
      setMoving(false)
    }
  }

  const handleAssignMember = async (userId: number) => {
    setAssigning(true)
    try {
      await assignMemberToClasse(Number(id), userId)
      setShowAssignMember(false)
      await fetchDetail()
      toast.success(t('structure.assignMember'))
    } catch (e) {
      logCatch('ClasseDetail.assignMember', e)
      toast.error(t('common.failedToSave'))
    } finally {
      setAssigning(false)
    }
  }

  const filteredSecretaryCandidates = useMemo(() => {
    const query = secretarySearch.trim().toLowerCase()
    if (!query) return secretaryCandidates
    return secretaryCandidates.filter(
      (u) => u.name.toLowerCase().includes(query) || u.email.toLowerCase().includes(query),
    )
  }, [secretaryCandidates, secretarySearch])

  if (loading) return <LoadingSpinner className="py-20" />

  if (loadError || !detail) {
    return (
      <div className="space-y-4 py-12 text-center">
        <p className="text-sm text-muted">{t('structure.loadFailed')}</p>
        <button
          onClick={() => {
            setLoading(true)
            fetchDetail()
          }}
          className="btn-secondary btn-md mx-auto gap-2"
        >
          <RefreshCw className="h-4 w-4" /> {t('common.retry')}
        </button>
      </div>
    )
  }

  const secretary = detail.class.secretary

  // A servant (including a class secretary) cannot open the stage screen, so
  // their back link returns to the servant dashboard instead of a structure
  // route they are not allowed to visit.
  const backTarget =
    user?.role === 'servant'
      ? '/servant'
      : `${basePath}/stages/${detail.class.stage_id}`

  const servantColumns: Column<User>[] = [
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: t('auth.email') },
    { key: 'assigned_members_count', header: t('dashboard.myMembers') },
    ...(canManage
      ? [{
          key: 'actions',
          header: t('common.actions'),
          render: (u: User) => (
            <div className="flex items-center gap-3">
              <button onClick={() => openMoveServant(u)} className="text-xs text-primary-600 hover:underline">
                {t('structure.moveServant')}
              </button>
              <button onClick={() => handleRemoveServant(u.id)} className="text-xs text-danger hover:underline">
                {t('common.remove')}
              </button>
            </div>
          ),
        }]
      : []),
  ]

  const memberColumns: Column<User>[] = [
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: t('auth.email') },
    { key: 'member_id', header: t('users.memberIdLabel'), render: (u) => u.member_id ?? '-' },
    { key: 'servant', header: t('structure.servants'), render: (u) => u.servant?.name ?? '-' },
  ]

  return (
    <div className="space-y-6">
      <button onClick={() => navigate(backTarget)} className="btn-icon btn-ghost">
        <ArrowLeft className="h-4 w-4 rtl-flip" /> {t('common.back')}
      </button>

      {!canManage && (
        <div className="rounded-lg border border-border bg-surface-secondary px-4 py-3 text-sm text-secondary">
          {t('structure.readOnlyNotice')}
        </div>
      )}

      <div className="card p-6 space-y-4">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
              <BookOpen className="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
            </div>
            <div>
              <h1 className="text-2xl font-bold">{detail.class.name}</h1>
              {detail.class.stage && (
                <p className="text-sm text-secondary">{detail.class.stage.name}</p>
              )}
              {detail.class.description && (
                <p className="mt-1 text-sm text-secondary">{detail.class.description}</p>
              )}
            </div>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <div className="rounded-lg bg-primary-50 dark:bg-primary-900/20 px-4 py-3 text-center">
            <p className="text-2xl font-bold text-primary-700 dark:text-primary-400">{detail.member_count}</p>
            <p className="text-xs text-secondary">{t('structure.members')}</p>
          </div>
          <div className="rounded-lg bg-warning-light px-4 py-3 text-center">
            <p className="text-2xl font-bold text-warning-dark">{detail.servant_count}</p>
            <p className="text-xs text-secondary">{t('structure.servants')}</p>
          </div>
          <div className="rounded-lg bg-success-light px-4 py-3 text-center">
            <p className="text-2xl font-bold text-success-dark">{detail.members.length}</p>
            <p className="text-xs text-secondary">{t('structure.members')} {t('common.total')}</p>
          </div>
        </div>
      </div>

      {/* Class secretary */}
      <div className="card p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3 min-w-0">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gold-100 dark:bg-gold-900/30">
              <ShieldCheck className="h-5 w-5 text-gold-500 dark:text-gold-400" />
            </div>
            <div className="min-w-0">
              <p className="text-xs uppercase tracking-wide text-muted">{t('structure.classSecretary')}</p>
              {secretary ? (
                <p className="font-semibold truncate">
                  {secretary.name}
                  <span className="text-xs font-normal text-muted"> ({secretary.email})</span>
                </p>
              ) : (
                <p className="text-sm text-secondary">{t('structure.noClassSecretary')}</p>
              )}
            </div>
          </div>
          {canManage && (
            <div className="flex shrink-0 items-center gap-2">
              <button onClick={openAssignSecretary} className="btn-sm btn-ghost gap-1.5">
                <UserCheck className="h-3.5 w-3.5" />
                {t(secretary ? 'structure.changeSecretary' : 'structure.assignSecretary')}
              </button>
              {secretary && (
                <button onClick={handleRemoveSecretary} className="btn-sm btn-ghost gap-1.5 text-danger">
                  {t('structure.removeSecretary')}
                </button>
              )}
            </div>
          )}
        </div>
        {canManage && (
          <p className="mt-3 text-xs text-muted">{t('structure.classSecretaryHint')}</p>
        )}
      </div>

      {/* Servants */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="font-semibold flex items-center gap-2">
            <UserCheck className="h-4 w-4" />
            {t('structure.servants')} ({detail.servants.length})
          </h3>
          {canManage && (
            <button onClick={openAssignServant} className="btn-primary btn-sm gap-1.5">
              <UserPlus className="h-3.5 w-3.5" /> {t('structure.assignServant')}
            </button>
          )}
        </div>
        {detail.servants.length > 0 ? (
          <DataTable columns={servantColumns} data={detail.servants} isLoading={false} />
        ) : (
          <p className="py-8 text-center text-sm text-muted">{t('structure.noServantsAssigned')}</p>
        )}
      </div>

      {/* Members */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="font-semibold flex items-center gap-2">
            <Users className="h-4 w-4" />
            {t('structure.members')} ({detail.members.length})
          </h3>
          {canManage && (
            <button onClick={openAssignMember} className="btn-primary btn-sm gap-1.5">
              <UserPlus className="h-3.5 w-3.5" /> {t('structure.assignMember')}
            </button>
          )}
        </div>
        {detail.members.length > 0 ? (
          <DataTable columns={memberColumns} data={detail.members} isLoading={false} />
        ) : (
          <p className="py-8 text-center text-sm text-muted">{t('structure.noMembersAssigned')}</p>
        )}
      </div>

      {/* Assign / replace secretary */}
      <Modal
        isOpen={showSecretary}
        onClose={() => !secretarySaving && setShowSecretary(false)}
        title={t('structure.assignSecretary')}
        size="md"
      >
        <div className="space-y-3">
          <p className="text-sm text-secondary">{t('structure.classSecretaryHint')}</p>
          <input
            placeholder={t('structure.searchSecretaryCandidates')}
            value={secretarySearch}
            onChange={(e) => setSecretarySearch(e.target.value)}
            className="input-field"
            disabled={secretarySaving}
          />
          <div className="max-h-80 space-y-2 overflow-y-auto">
            {secretaryError && (
              <p className="py-4 text-center text-sm text-danger">{t('structure.loadFailed')}</p>
            )}
            {!secretaryError && filteredSecretaryCandidates.length === 0 && (
              <p className="py-4 text-center text-sm text-muted">{t('structure.noEligibleSecretaries')}</p>
            )}
            {filteredSecretaryCandidates.map((candidate) => (
              <button
                key={candidate.id}
                onClick={() => handleAssignSecretary(candidate.id)}
                disabled={secretarySaving}
                className="w-full rounded-lg border border-border p-3 text-start transition-colors hover:bg-surface-secondary disabled:opacity-50"
              >
                <span className="flex items-center justify-between gap-2">
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">{candidate.name}</span>
                    <span className="block truncate text-xs text-secondary">{candidate.email}</span>
                  </span>
                  {detail.class.class_secretary_id === candidate.id && (
                    <span className="shrink-0 rounded-full bg-success-light px-2 py-0.5 text-xs text-success-dark">
                      {t('structure.classSecretary')}
                    </span>
                  )}
                </span>
              </button>
            ))}
          </div>
        </div>
      </Modal>

      {/* Assign servant */}
      <Modal isOpen={showAssignServant} onClose={() => setShowAssignServant(false)} title={t('structure.assignServant')} size="md">
        <div className="space-y-2 max-h-80 overflow-y-auto">
          {availableServants.length === 0 && (
            <p className="py-4 text-center text-sm text-muted">{t('structure.noServantsInStage')}</p>
          )}
          {availableServants.map((s) => (
            <button
              key={s.id}
              onClick={() => handleAssignServant(s.id)}
              disabled={assigning}
              className="w-full rounded-lg border border-border p-3 text-start hover:bg-surface-secondary transition-colors disabled:opacity-50"
            >
              <p className="font-medium text-sm">{s.name}</p>
              <p className="text-xs text-secondary">{s.email}</p>
            </button>
          ))}
        </div>
      </Modal>

      {/* Move servant */}
      <Modal
        isOpen={movingServant !== null}
        onClose={() => !moving && setMovingServant(null)}
        title={t('structure.moveServantTo')}
        size="md"
      >
        <div className="space-y-4">
          <p className="text-sm text-secondary">{movingServant?.name}</p>
          <div>
            <label htmlFor="move-target-class" className="mb-1 block text-sm font-medium">
              {t('structure.selectTargetClass')}
            </label>
            <select
              id="move-target-class"
              value={targetClassId}
              onChange={(e) => setTargetClassId(e.target.value ? Number(e.target.value) : '')}
              className="input-field"
              disabled={moving}
            >
              <option value="">{t('structure.selectStage')}</option>
              {stageClasses.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <button
            onClick={handleMoveServant}
            disabled={moving || !targetClassId}
            className="btn-primary btn-md w-full disabled:opacity-60"
          >
            <ArrowRightLeft className="h-4 w-4" /> {t('structure.moveServant')}
          </button>
        </div>
      </Modal>

      {/* Assign member */}
      <Modal isOpen={showAssignMember} onClose={() => setShowAssignMember(false)} title={t('structure.assignMember')} size="md">
        <div className="space-y-2 max-h-80 overflow-y-auto">
          {availableMembers.length === 0 && <p className="py-4 text-center text-sm text-muted">{t('structure.allMembersAssigned')}</p>}
          {availableMembers.map((m) => (
            <button
              key={m.id}
              onClick={() => handleAssignMember(m.id)}
              disabled={assigning}
              className="w-full rounded-lg border border-border p-3 text-start hover:bg-surface-secondary transition-colors disabled:opacity-50"
            >
              <p className="font-medium text-sm">{m.name}</p>
              <p className="text-xs text-secondary">{m.email}</p>
            </button>
          ))}
        </div>
      </Modal>
    </div>
  )
}
