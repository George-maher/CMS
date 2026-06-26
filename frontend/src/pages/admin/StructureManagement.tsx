import { useEffect, useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Plus, Layers, Pencil, Trash2, Search, ChevronRight, BookOpen, ArrowLeft } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import type { Stage } from '@/types'
import { listStructureClasses } from '@/api/structure'
import { listStages, createStage, updateStage, deleteStage, bulkCreateStages } from '@/api/stages'

export default function StructureManagement() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [stages, setStages] = useState<Stage[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)

  const [showWizard, setShowWizard] = useState(false)
  const [stageCount, setStageCount] = useState(3)
  const [creating, setCreating] = useState(false)

  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Stage | null>(null)
  const [form, setForm] = useState({ name: '' })

  const fetch = useCallback(async (q?: string) => {
    setLoading(true)
    try {
      if (q) {
        const structure = await listStructureClasses(q)
        setStages(structure.map(s => ({
          id: s.id,
          name: s.name,
          display_order: s.display_order,
          classes_count: s.classes_count,
          created_at: s.created_at,
        })))
      } else {
        const data = await listStages()
        setStages(data)
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetch() }, [fetch])
  useEffect(() => {
    const timer = setTimeout(() => fetch(search || undefined), 300)
    return () => clearTimeout(timer)
  }, [search, fetch])

  const openCreate = () => { setEditing(null); setForm({ name: '' }); setShowModal(true) }
  const openEdit = (e: React.MouseEvent, item: Stage) => {
    e.stopPropagation()
    setEditing(item)
    setForm({ name: item.name })
    setShowModal(true)
  }

  const handleSave = async () => {
    if (!form.name.trim()) return toast.error(t('common.failedToSave'))
    try {
      if (editing) {
        await updateStage(editing.id, form)
        toast.success(t('structure.stageUpdated'))
      } else {
        await createStage(form)
        toast.success(t('structure.stageCreated'))
      }
      setShowModal(false)
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const handleDelete = async (e: React.MouseEvent, id: number) => {
    e.stopPropagation()
    if (!window.confirm(t('structure.deleteStageConfirm'))) return
    try {
      await deleteStage(id)
      toast.success(t('structure.stageDeleted'))
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const handleBulkCreate = async () => {
    if (stageCount < 1 || stageCount > 50) return
    setCreating(true)
    try {
      await bulkCreateStages(stageCount)
      toast.success(t('structure.stageCreated'))
      setShowWizard(false)
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setCreating(false)
    }
  }

  const openStage = (id: number) => navigate(`/admin/stages/${id}`)

  if (loading && stages.length === 0) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-600 border-t-transparent" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('structure.title')}</h1>
          <p className="text-sm text-secondary mt-1">{t('structure.subtitle')}</p>
        </div>
        <div className="flex gap-2">
          {stages.length === 0 && (
            <button onClick={() => setShowWizard(true)} className="btn-primary btn-md">
              <Layers className="h-4 w-4" /> {t('structure.setupWizard')}
            </button>
          )}
          <button onClick={openCreate} className="btn-primary btn-md">
            <Plus className="h-4 w-4" /> {t('structure.createStage')}
          </button>
        </div>
      </div>

      {/* Setup Wizard */}
      {stages.length === 0 && !showWizard && (
        <div className="card p-8 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/30">
            <Layers className="h-8 w-8 text-primary-600 dark:text-primary-400" />
          </div>
          <h2 className="mt-4 text-lg font-semibold">{t('structure.setupWizard')}</h2>
          <p className="mt-1 text-sm text-secondary">{t('structure.setupWizardDesc')}</p>
          <button onClick={() => setShowWizard(true)} className="btn-primary btn-md mt-6">
            <Layers className="h-4 w-4" /> {t('structure.setupWizard')}
          </button>
        </div>
      )}

      {/* Wizard Modal */}
      <Modal isOpen={showWizard} onClose={() => setShowWizard(false)} title={t('structure.setupWizard')}>
        <div className="space-y-4">
          <p className="text-sm text-secondary">{t('structure.setupWizardDesc')}</p>
          <input
            type="number"
            min={1}
            max={50}
            value={stageCount}
            onChange={(e) => setStageCount(parseInt(e.target.value) || 1)}
            className="input-field"
            placeholder={t('structure.stageCountPlaceholder')}
          />
          <button onClick={handleBulkCreate} disabled={creating} className="btn-primary btn-md w-full">
            {creating ? t('common.saving') : t('structure.createStages')}
          </button>
        </div>
      </Modal>

      {/* Search */}
      {stages.length > 0 && (
        <div className="relative max-w-md">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="input-field pl-10"
            placeholder={t('structure.searchStages')}
          />
        </div>
      )}

      {/* Stage Cards */}
      {stages.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {stages.map((stage) => (
            <div key={stage.id} className="card hover:shadow-sm transition-shadow">
              <div className="h-1.5 bg-primary-500 rounded-t-xl" />
              <div className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/30">
                      <Layers className="h-5 w-5 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div className="min-w-0">
                      <h3 className="font-semibold truncate">{stage.name}</h3>
                      <p className="text-sm text-secondary">
                        {stage.classes_count} {t('structure.classesCount')}
                      </p>
                    </div>
                  </div>
                </div>
                <div className="mt-4 flex gap-2 pt-3 border-t border-border">
                  <button onClick={() => openStage(stage.id)} className="btn-sm btn-ghost gap-1.5 flex-1">
                    <BookOpen className="h-3.5 w-3.5" /> {t('structure.openStage')}
                  </button>
                  <button onClick={(e) => openEdit(e, stage)} className="btn-sm btn-ghost gap-1.5" title={t('common.edit')}>
                    <Pencil className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={(e) => handleDelete(e, stage.id)} className="btn-sm btn-ghost gap-1.5 text-danger hover:bg-red-50 dark:hover:bg-red-900/20" title={t('common.delete')}>
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : !loading && !showWizard && (
        <p className="py-12 text-center text-muted">{t('structure.noStages')}</p>
      )}

      {/* Create / Edit Modal */}
      <Modal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        title={editing ? t('structure.editStage') : t('structure.createStage')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowModal(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleSave} className="flex-1 btn-primary btn-md">{editing ? t('common.update') : t('common.create')}</button>
          </div>
        }
      >
        <div className="space-y-4">
          <input
            placeholder={t('structure.stageNamePlaceholder')}
            value={form.name}
            onChange={(e) => setForm({ name: e.target.value })}
            className="input-field"
          />
        </div>
      </Modal>
    </div>
  )
}
