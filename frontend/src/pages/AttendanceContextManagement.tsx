import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, CheckCircle, Archive, FolderOpen } from 'lucide-react'
import toast from 'react-hot-toast'
import {
  getContextsForManagement,
  createContext,
  updateContext,
  toggleContextActive,
  deleteContext,
  type AttendanceContextFormData,
} from '@/api/attendanceContexts'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import Badge from '@/components/common/Badge'
import type { AttendanceContext, PaginationMeta } from '@/types'

export default function AttendanceContextManagement() {
  const { t } = useTranslation()

  const [contexts, setContexts] = useState<AttendanceContext[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingContext, setEditingContext] = useState<AttendanceContext | null>(null)
  const [form, setForm] = useState<AttendanceContextFormData>({ name: '', description: '', is_active: true })

  const fetchContexts = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const res = await getContextsForManagement({ page, per_page: 20 })
      setContexts(res.data)
      setMeta(res.meta)
    } catch {
      toast.error(t('common.failedToLoad'))
    } finally {
      setLoading(false)
    }
  }, [t])

  useEffect(() => { fetchContexts() }, [fetchContexts])

  const openCreate = () => {
    setEditingContext(null)
    setForm({ name: '', name_ar: '', description: '', is_active: true })
    setModalOpen(true)
  }

  const openEdit = (ctx: AttendanceContext) => {
    setEditingContext(ctx)
    setForm({ name: ctx.name, name_ar: ctx.name_ar || '', description: ctx.description || '', is_active: ctx.is_active })
    setModalOpen(true)
  }

  const handleSave = async () => {
    if (!form.name.trim()) {
      toast.error(t('context.nameRequired'))
      return
    }
    setSaving(true)
    try {
      if (editingContext) {
        await updateContext(editingContext.id, form)
        toast.success(t('context.updated'))
      } else {
        await createContext(form)
        toast.success(t('context.created'))
      }
      setModalOpen(false)
      fetchContexts(meta.current_page)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const msg = axiosErr?.response?.data?.message || t('common.failedToSave')
      toast.error(msg)
    } finally {
      setSaving(false)
    }
  }

  const handleToggleActive = async (ctx: AttendanceContext) => {
    try {
      await toggleContextActive(ctx.id)
      toast.success(ctx.is_active ? t('context.archived') : t('context.activated'))
      fetchContexts(meta.current_page)
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  const handleDelete = async (ctx: AttendanceContext) => {
    if (!window.confirm(t('context.deleteConfirm', { name: ctx.name }))) return
    try {
      await deleteContext(ctx.id)
      toast.success(t('context.deleted'))
      fetchContexts(meta.current_page)
    } catch {
      toast.error(t('common.failedToSave'))
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('context.management')}</h1>
          <p className="mt-1 text-sm text-secondary">{t('context.managementDescription')}</p>
        </div>
        <button onClick={openCreate} className="btn-primary btn-md self-start sm:self-auto">
          <Plus className="h-4 w-4" />
          {t('context.createNew')}
        </button>
      </div>

      {loading ? (
        <LoadingSpinner className="py-12" />
      ) : contexts.length === 0 ? (
        <div className="card p-12 text-center">
          <FolderOpen className="mx-auto h-12 w-12 text-secondary mb-3" />
          <p className="text-lg font-medium text-secondary">{t('context.noContexts')}</p>
          <p className="text-sm text-muted mt-1">{t('context.createFirstPrompt')}</p>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {contexts.map((ctx) => (
              <div key={ctx.id} className="card p-5 flex flex-col gap-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold uppercase tracking-wider text-secondary">{t('context.name')}</p>
                    <p className="mt-0.5 text-base font-semibold truncate">{ctx.name}</p>
                    {ctx.name_ar && <p className="text-sm text-secondary truncate">{ctx.name_ar}</p>}
                  </div>
                  <Badge variant={ctx.is_active ? 'success' : 'default'}>
                    {ctx.is_active ? t('common.active') : t('context.archived')}
                  </Badge>
                </div>

                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-secondary">{t('context.description')}</p>
                  <p className="mt-0.5 text-sm text-secondary">{ctx.description || '—'}</p>
                </div>

                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-secondary">{t('common.createdAt')}</p>
                  <p className="mt-0.5 text-sm text-secondary">{new Date(ctx.created_at).toLocaleDateString()}</p>
                </div>

                <div className="flex items-center gap-1 pt-2 border-t border-border mt-auto">
                  <button onClick={() => openEdit(ctx)}
                    className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-primary-100 hover:text-primary-600"
                    title={t('common.edit')}>
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button onClick={() => handleToggleActive(ctx)}
                    className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-warning-100 hover:text-warning-600"
                    title={ctx.is_active ? t('context.archive') : t('context.activate')}>
                    {ctx.is_active ? <Archive className="h-4 w-4" /> : <CheckCircle className="h-4 w-4" />}
                  </button>
                  <button onClick={() => handleDelete(ctx)}
                    className="btn-icon btn-ghost rounded-lg p-1.5 hover:bg-danger-100 hover:text-danger-600"
                    title={t('common.delete')}>
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>

          {meta.last_page > 1 && (
            <div className="flex items-center justify-between gap-4">
              <span className="text-sm text-secondary">
                {t('common.page')} {meta.current_page} {t('common.of')} {meta.last_page} ({meta.total} {t('common.total')})
              </span>
              <div className="flex gap-2">
                <button disabled={meta.current_page <= 1} onClick={() => fetchContexts(meta.current_page - 1)}
                  className="btn-ghost btn-sm">{t('common.prev')}</button>
                <button disabled={meta.current_page >= meta.last_page} onClick={() => fetchContexts(meta.current_page + 1)}
                  className="btn-ghost btn-sm">{t('common.next')}</button>
              </div>
            </div>
          )}
        </>
      )}

      <Modal isOpen={modalOpen} onClose={() => setModalOpen(false)} title={editingContext ? t('context.editContext') : t('context.createContext')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setModalOpen(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleSave} disabled={saving} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : (editingContext ? t('common.update') : t('common.create'))}
            </button>
          </div>
        }>
        <div className="space-y-4">
          <div>
            <label className="label">{t('context.name')} *</label>
            <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="input-field" placeholder={t('context.namePlaceholder')} required />
          </div>
          <div>
            <label className="label">{t('context.name')} (عربي)</label>
            <input type="text" value={form.name_ar || ''} onChange={(e) => setForm({ ...form, name_ar: e.target.value })}
              className="input-field" placeholder="مثال: مدارس الأحد" />
          </div>
          <div>
            <label className="label">{t('context.description')}</label>
            <textarea value={form.description || ''} onChange={(e) => setForm({ ...form, description: e.target.value })}
              className="input-field" rows={3} placeholder={t('context.descriptionPlaceholder')} />
          </div>
          <div className="flex items-center gap-3">
            <input type="checkbox" id="isActive" checked={form.is_active}
              onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
              className="h-4 w-4 rounded border-border text-primary-600 focus:ring-primary-500" />
            <label htmlFor="isActive" className="text-sm">{t('common.active')}</label>
          </div>
        </div>
      </Modal>
    </div>
  )
}
