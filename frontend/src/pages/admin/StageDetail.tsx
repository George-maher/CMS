import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { ArrowLeft, Plus, Pencil, Trash2, Search, Users, UserCheck, BookOpen } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import type { Stage, Classe } from '@/types'
import { getStage, getStageClasses } from '@/api/stages'
import { createClasse, updateClasse, deleteClasse } from '@/api/classes'

export default function StageDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [stage, setStage] = useState<Stage | null>(null)
  const [classes, setClasses] = useState<Classe[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Classe | null>(null)
  const [form, setForm] = useState({ name: '', description: '' })

  const fetch = useCallback(async (q?: string) => {
    if (!id) return
    setLoading(true)
    try {
      const [stageData, classesData] = await Promise.all([
        getStage(Number(id)),
        getStageClasses(Number(id), q || search),
      ])
      setStage(stageData)
      setClasses(classesData)
    } finally {
      setLoading(false)
    }
  }, [id, search])

  useEffect(() => { fetch() }, [fetch])
  useEffect(() => {
    const timer = setTimeout(() => fetch(search), 300)
    return () => clearTimeout(timer)
  }, [search, fetch])

  const openCreate = () => { setEditing(null); setForm({ name: '', description: '' }); setShowModal(true) }
  const openEdit = (e: React.MouseEvent, item: Classe) => {
    e.stopPropagation()
    setEditing(item)
    setForm({ name: item.name, description: item.description ?? '' })
    setShowModal(true)
  }

  const handleSave = async () => {
    if (!form.name.trim()) return toast.error(t('common.failedToSave'))
    try {
      if (editing) {
        await updateClasse(editing.id, form)
        toast.success(t('structure.classUpdated'))
      } else {
        await createClasse({ stage_id: Number(id), ...form })
        toast.success(t('structure.classCreated'))
      }
      setShowModal(false)
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const handleDelete = async (e: React.MouseEvent, classeId: number) => {
    e.stopPropagation()
    if (!window.confirm(t('structure.deleteClassConfirm'))) return
    try {
      await deleteClasse(classeId)
      toast.success(t('structure.classDeleted'))
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const openClassDetail = (classeId: number) => {
    navigate(`/admin/classes/${classeId}`)
  }

  if (loading && !stage) {
    return <LoadingSpinner className="py-20" />
  }

  if (!stage) {
    return <p className="py-12 text-center text-muted">{t('structure.noStages')}</p>
  }

  return (
    <div className="space-y-6">
      {/* Back button + Header */}
      <div className="flex items-start gap-4">
        <button onClick={() => navigate('/admin/structure')} className="btn-icon btn-ghost mt-1">
          <ArrowLeft className="h-4 w-4" /> {t('structure.backToStages')}
        </button>
      </div>

      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/30">
            <BookOpen className="h-6 w-6 text-primary-600 dark:text-primary-400" />
          </div>
          <div>
            <h1 className="text-2xl font-bold">{stage.name}</h1>
            <p className="text-sm text-secondary">
              {classes.length} {t('structure.classesCount')}
            </p>
          </div>
        </div>
        <button onClick={openCreate} className="btn-primary btn-md shrink-0">
          <Plus className="h-4 w-4" /> {t('structure.createClass')}
        </button>
      </div>

      {/* Search */}
      {classes.length > 0 && (
        <div className="relative max-w-md">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="input-field pl-10"
            placeholder={t('structure.searchClasses')}
          />
        </div>
      )}

      {/* Class Cards */}
      {classes.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {classes.map((classe) => (
            <div
              key={classe.id}
              className="card cursor-pointer hover:shadow-sm transition-shadow"
              onClick={() => openClassDetail(classe.id)}
            >
              <div className="h-1.5 bg-success rounded-t-xl" />
              <div className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-light">
                      <BookOpen className="h-5 w-5 text-success-dark" />
                    </div>
                    <div className="min-w-0">
                      <h3 className="font-semibold truncate">{classe.name}</h3>
                      {classe.description && (
                        <p className="text-xs text-secondary truncate mt-0.5">{classe.description}</p>
                      )}
                    </div>
                  </div>
                </div>

                <div className="mt-3 flex items-center gap-4 text-sm">
                  <span className="flex items-center gap-1.5 text-info">
                    <Users className="h-4 w-4" />
                    <span>{classe.member_count} {t('structure.members')}</span>
                  </span>
                  <span className="flex items-center gap-1.5 text-warning-dark">
                    <UserCheck className="h-4 w-4" />
                    <span>{classe.servant_count} {t('structure.servants')}</span>
                  </span>
                </div>

                <div className="mt-3 flex gap-2 pt-3 border-t border-border">
                  <button onClick={(e) => { e.stopPropagation(); openClassDetail(classe.id) }} className="btn-sm btn-ghost gap-1.5 flex-1">
                    <Users className="h-3.5 w-3.5" /> {t('common.view')}
                  </button>
                  <button onClick={(e) => openEdit(e, classe)} className="btn-sm btn-ghost gap-1.5" title={t('common.edit')}>
                    <Pencil className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={(e) => handleDelete(e, classe.id)} className="btn-sm btn-ghost gap-1.5 text-danger hover:bg-red-50 dark:hover:bg-red-900/20" title={t('common.delete')}>
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="card p-8 text-center">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success-light">
            <BookOpen className="h-7 w-7 text-success-dark" />
          </div>
          <h2 className="mt-3 text-lg font-semibold">{t('structure.noClasses')}</h2>
          <p className="mt-1 text-sm text-secondary">{t('structure.createFirstClass')}</p>
          <button onClick={openCreate} className="btn-primary btn-md mt-4">
            <Plus className="h-4 w-4" /> {t('structure.createClass')}
          </button>
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        title={editing ? t('structure.editClass') : t('structure.createClass')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowModal(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleSave} className="flex-1 btn-primary btn-md">{editing ? t('common.update') : t('common.create')}</button>
          </div>
        }
      >
        <div className="space-y-4">
          <input
            placeholder={t('structure.classNamePlaceholder')}
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            className="input-field"
          />
          <textarea
            placeholder={t('structure.classDescriptionPlaceholder')}
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            className="input-field"
            rows={2}
          />
        </div>
      </Modal>
    </div>
  )
}
