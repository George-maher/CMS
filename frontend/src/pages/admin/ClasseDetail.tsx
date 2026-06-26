import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ArrowLeft, UserPlus, Users, UserCheck, BookOpen } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { Classe, User } from '@/types'
import { getClasseDetail, assignServantToClasse, removeServantFromClasse, assignMemberToClasse } from '@/api/classes'
import { listUsers } from '@/api/users'

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
  const [detail, setDetail] = useState<ClassDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [showAssignServant, setShowAssignServant] = useState(false)
  const [showAssignMember, setShowAssignMember] = useState(false)
  const [availableServants, setAvailableServants] = useState<User[]>([])
  const [availableMembers, setAvailableMembers] = useState<User[]>([])
  const [assigning, setAssigning] = useState(false)

  const fetchDetail = async () => {
    if (!id) return
    setLoading(true)
    try {
      const res = await getClasseDetail(Number(id))
      setDetail(res)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchDetail() }, [id])

  const openAssignServant = async () => {
    try {
      const users = await listUsers({ role: 'servant', per_page: 100 })
      setAvailableServants(users.data)
      setShowAssignServant(true)
    } catch {}
  }

  const openAssignMember = async () => {
    try {
      const users = await listUsers({ role: 'member', per_page: 100 })
      setAvailableMembers(users.data.filter((u) => u.class_id !== Number(id)))
      setShowAssignMember(true)
    } catch {}
  }

  const handleAssignServant = async (userId: number) => {
    setAssigning(true)
    try {
      await assignServantToClasse(Number(id), userId)
      setShowAssignServant(false)
      fetchDetail()
      toast.success(t('structure.assignServant'))
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setAssigning(false)
    }
  }

  const handleRemoveServant = async (userId: number) => {
    if (!window.confirm(t('common.delete'))) return
    try {
      await removeServantFromClasse(Number(id), userId)
      fetchDetail()
      toast.success(t('common.delete'))
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const handleAssignMember = async (userId: number) => {
    setAssigning(true)
    try {
      await assignMemberToClasse(Number(id), userId)
      setShowAssignMember(false)
      fetchDetail()
      toast.success(t('structure.assignMember'))
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setAssigning(false)
    }
  }

  if (loading) return <LoadingSpinner className="py-20" />
  if (!detail) return <p className="py-12 text-center text-muted">{t('structure.noClasses')}</p>

  const servantColumns: Column<User>[] = [
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: t('auth.email') },
    { key: 'assigned_members_count', header: t('dashboard.myMembers') },
    {
      key: 'actions', header: t('common.actions'),
      render: (u) => (
        <button onClick={() => handleRemoveServant(u.id)} className="text-xs text-danger hover:underline">
          {t('common.remove')}
        </button>
      ),
    },
  ]

  const memberColumns: Column<User>[] = [
    { key: 'name', header: t('auth.name'), render: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: t('auth.email') },
    { key: 'member_id', header: t('users.memberIdLabel'), render: (u) => u.member_id ?? '-' },
    { key: 'servant', header: t('structure.servants'), render: (u) => u.servant?.name ?? '-' },
  ]

  return (
    <div className="space-y-6">
      <button onClick={() => navigate(`/admin/stages/${detail.class.stage_id}`)} className="btn-icon btn-ghost">
        <ArrowLeft className="h-4 w-4" /> {t('common.back')}
      </button>

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

      {/* Servants */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="font-semibold flex items-center gap-2">
            <UserCheck className="h-4 w-4" />
            {t('structure.servants')} ({detail.servants.length})
          </h3>
          <button onClick={openAssignServant} className="btn-primary btn-sm gap-1.5">
            <UserPlus className="h-3.5 w-3.5" /> {t('structure.assignServant')}
          </button>
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
          <button onClick={openAssignMember} className="btn-primary btn-sm gap-1.5">
            <UserPlus className="h-3.5 w-3.5" /> {t('structure.assignMember')}
          </button>
        </div>
        {detail.members.length > 0 ? (
          <DataTable columns={memberColumns} data={detail.members} isLoading={false} />
        ) : (
          <p className="py-8 text-center text-sm text-muted">{t('structure.noMembersAssigned')}</p>
        )}
      </div>

      <Modal isOpen={showAssignServant} onClose={() => setShowAssignServant(false)} title={t('structure.assignServant')} size="md">
        <div className="space-y-2 max-h-80 overflow-y-auto">
          {availableServants.length === 0 && <p className="py-4 text-center text-sm text-muted">{t('structure.allServantsAssigned')}</p>}
          {availableServants.map((s) => (
            <button
              key={s.id}
              onClick={() => handleAssignServant(s.id)}
              disabled={assigning}
              className="w-full rounded-lg border border-border p-3 text-left hover:bg-surface-secondary transition-colors disabled:opacity-50"
            >
              <p className="font-medium text-sm">{s.name}</p>
              <p className="text-xs text-secondary">{s.email}</p>
            </button>
          ))}
        </div>
      </Modal>

      <Modal isOpen={showAssignMember} onClose={() => setShowAssignMember(false)} title={t('structure.assignMember')} size="md">
        <div className="space-y-2 max-h-80 overflow-y-auto">
          {availableMembers.length === 0 && <p className="py-4 text-center text-sm text-muted">{t('structure.allMembersAssigned')}</p>}
          {availableMembers.map((m) => (
            <button
              key={m.id}
              onClick={() => handleAssignMember(m.id)}
              disabled={assigning}
              className="w-full rounded-lg border border-border p-3 text-left hover:bg-surface-secondary transition-colors disabled:opacity-50"
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
