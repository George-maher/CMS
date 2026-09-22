import { useEffect, useState, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Plus, Layers, Pencil, Trash2, Search, BookOpen } from 'lucide-react'
import Modal from '@/components/common/Modal'
import type { Stage } from '@/types'
import { listStructureClasses } from '@/api/structure'
import { listStages, createStage, updateStage, deleteStage, bulkCreateStages } from '@/api/stages'
import { logCatch } from '@/lib/debug'

const BULK_MIN = 1
const BULK_MAX = 50

export default function StructureManagement() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [stages, setStages] = useState<Stage[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const hasLoadedRef = useRef(false)

  const [showWizard, setShowWizard] = useState(false)
  const [stageCount, setStageCount] = useState(3)
  const [creating, setCreating] = useState(false)

  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<Stage | null>(null)
  const [form, setForm] = useState({ name: '' })
  const [mode, setMode] = useState<'single' | 'multiple'>('single')
  const [bulkCount, setBulkCount] = useState(3)
  const [saving, setSaving] = useState(false)

  const fetch = useCallback(async (q?: string, showSpinner = false) => {
    if (showSpinner) setLoading(true)
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
      hasLoadedRef.current = true
    }
  }, [])

  useEffect(() => {
    const timer = setTimeout(() => fetch(search || undefined, !hasLoadedRef.current), 300)
    return () => clearTimeout(timer)
  }, [search, fetch])

  const openCreate = () => {
    setEditing(null)
    setForm({ name: '' })
    setMode('single')
    setBulkCount(3)
    setShowModal(true)
  }
  const openEdit = (e: React.MouseEvent, item: Stage) => {
    e.stopPropagation()
    setEditing(item)
    setForm({ name: item.name })
    setMode('single')
    setShowModal(true)
  }

  const handleSave = async () => {
    if (saving) return
    if (editing) {
      if (!form.name.trim()) return toast.error(t('common.failedToSave'))
      setSaving(true)
      try {
        await updateStage(editing.id, form)
        toast.success(t('structure.stageUpdated'))
        setShowModal(false)
        fetch()
      } catch (e) {
        logCatch('StructureManagement.save', e)
        toast.error(t('common.failedToSave'))
      } finally {
        setSaving(false)
      }
      return
    }
    if (mode === 'single') {
      if (!form.name.trim()) return toast.error(t('common.failedToSave'))
      setSaving(true)
      try {
        await createStage(form)
        toast.success(t('structure.stageCreated'))
        setShowModal(false)
        fetch()
      } catch (e) {
        logCatch('StructureManagement.save', e)
        toast.error(t('common.failedToSave'))
      } finally {
        setSaving(false)
      }
      return
    }
    if (!Number.isInteger(bulkCount) || bulkCount < BULK_MIN || bulkCount > BULK_MAX) {
      return toast.error(t('structure.invalidCount'))
    }
    setSaving(true)
    try {
      await bulkCreateStages(bulkCount)
      toast.success(t('structure.stagesCreated'))
      setShowModal(false)
      fetch()
    } catch (e) {
      logCatch('StructureManagement.bulkSave', e)
      toast.error(t('common.failedToSave'))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (e: React.MouseEvent, id: number) => {
    e.stopPropagation()
    if (!window.confirm(t('structure.deleteStageConfirm'))) return
    try {
      await deleteStage(id)
      toast.success(t('structure.stageDeleted'))
      fetch()
    } catch (e) {
      logCatch('StructureManagement.delete', e)
      toast.error(t('common.failedToSave'))
    }
  }

  const handleBulkCreate = async () => {
    if (creating) return
    if (!Number.isInteger(stageCount) || stageCount < BULK_MIN || stageCount > BULK_MAX) {
      return toast.error(t('structure.invalidCount'))
    }
    setCreating(true)
    try {
      await bulkCreateStages(stageCount)
      toast.success(t('structure.stagesCreated'))
      setShowWizard(false)
      fetch()
    } catch {
      toast.error(t('common.failedToSave'))
    } finally {
      setCreating(false)
    }
  }

  const openStage = (id: number) => navigate(`/admin/stages/${id}`)
  const previewCount = Math.min(Math.max(bulkCount || 0, 0), 10)

  return (
    <div className="space-y-6">
      {/* Header — ALWAYS visible: never conditional on stages.length, search, or loading */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{t('structure.title')}</h1>
          <p className="text-sm text-secondary mt-1">{t('structure.subtitle')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {stages.length === 0 && !loading && (
            <button onClick={() => setShowWizard(true)} className="btn-secondary btn-md">
              <Layers className="h-4 w-4" /> {t('structure.setupWizard')}
            </button>
          )}
          <button onClick={openCreate} aria-label={t('structure.addStage')} className="btn-primary btn-md shrink-0">
            <Plus className="h-4 w-4" /> {t('structure.addStage')}
          </button>
        </div>
      </div>

      {/* Setup Wizard */}
      {stages.length === 0 && !loading && !showWizard && (
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
      <Modal isOpen={showWizard} onClose={() => !creating && setShowWizard(false)} title={t('structure.setupWizard')}>
        <div className="space-y-4">
          <p className="text-sm text-secondary">{t('structure.setupWizardDesc')}</p>
          <label htmlFor="wizard-stage-count" className="block text-sm font-medium">{t('structure.stageCount')}</label>
          <input
            id="wizard-stage-count"
            type="number"
            min={BULK_MIN}
            max={BULK_MAX}
            value={stageCount}
            onChange={(e) => setStageCount(parseInt(e.target.value) || 1)}
            className="input-field"
            placeholder={t('structure.stageCountPlaceholder')}
            disabled={creating}
          />
          <button onClick={handleBulkCreate} disabled={creating} className="btn-primary btn-md w-full disabled:opacity-60">
            {creating ? t('common.saving') : t('structure.createStages')}
          </button>
        </div>
      </Modal>

      {/* Search — visible whenever there is data or a query, independent of loading */}
      {(stages.length > 0 || search) && (
        <div className="relative max-w-md">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="input-field pl-10"
            placeholder={t('structure.searchStages')}
            aria-label={t('structure.searchStages')}
          />
        </div>
      )}

      {loading && stages.length === 0 ? (
        <div className="flex items-center justify-center py-20" role="status" aria-label={t('common.loading')}>
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-600 border-t-transparent" />
        </div>
      ) : stages.length > 0 ? (
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
                  <button onClick={(e) => openEdit(e, stage)} className="btn-sm btn-ghost gap-1.5" title={t('common.edit')} aria-label={t('common.edit')}>
                    <Pencil className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={(e) => handleDelete(e, stage.id)} className="btn-sm btn-ghost gap-1.5 text-danger hover:bg-red-50 dark:hover:bg-red-900/20" title={t('common.delete')} aria-label={t('common.delete')}>
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

      {/* Create / Edit Modal — single + bulk choice */}
      <Modal
        isOpen={showModal}
        onClose={() => !saving && setShowModal(false)}
        title={editing ? t('structure.editStage') : t('structure.createStage')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowModal(false)} disabled={saving} className="flex-1 btn-secondary btn-md disabled:opacity-60">{t('common.cancel')}</button>
            <button onClick={handleSave} disabled={saving} className="flex-1 btn-primary btn-md disabled:opacity-60">
              {saving ? t('common.creating') : editing ? t('common.update') : mode === 'single' ? t('common.create') : t('structure.createStages')}
            </button>
          </div>
        }
      >
        <div className="space-y-4">
          {!editing && (
            <div className="flex gap-2" role="tablist" aria-label={t('structure.createStage')}>
              <button
                type="button"
                role="tab"
                aria-selected={mode === 'single'}
                onClick={() => setMode('single')}
                className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${mode === 'single' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30' : 'border-border hover:bg-surface-secondary'}`}
              >
                {t('structure.createSingle')}
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={mode === 'multiple'}
                onClick={() => setMode('multiple')}
                className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${mode === 'multiple' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30' : 'border-border hover:bg-surface-secondary'}`}
              >
                {t('structure.createMultiple')}
              </button>
            </div>
          )}
          {editing || mode === 'single' ? (
            <div>
              <label htmlFor="stage-name" className="mb-1 block text-sm font-medium">{t('structure.stageName')}</label>
              <input
                id="stage-name"
                placeholder={t('structure.stageNamePlaceholder')}
                value={form.name}
                onChange={(e) => setForm({ name: e.target.value })}
                className="input-field"
                disabled={saving}
              />
            </div>
          ) : (
            <div className="space-y-3">
              <div>
                <label htmlFor="bulk-stage-count" className="mb-1 block text-sm font-medium">{t('structure.stageCount')}</label>
                <input
                  id="bulk-stage-count"
                  type="number"
                  min={BULK_MIN}
                  max={BULK_MAX}
                  value={bulkCount}
                  onChange={(e) => setBulkCount(parseInt(e.target.value) || 0)}
                  className="input-field"
                  placeholder={t('structure.stageCountPlaceholder')}
                  disabled={saving}
                />
              </div>
              {bulkCount >= BULK_MIN && (
                <div className="rounded-lg border border-border bg-surface-secondary p-3">
                  <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('structure.bulkPreview')}</p>
                  <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto text-sm">
                    {Array.from({ length: previewCount }, (_, i) => (
                      <li key={i} className="truncate">• {t('structure.stage')} {i + 1}</li>
                    ))}
                    {bulkCount > previewCount && <li className="text-muted">… ({bulkCount})</li>}
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>
      </Modal>
    </div>
  )
}
