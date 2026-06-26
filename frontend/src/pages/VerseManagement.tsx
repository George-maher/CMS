import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Plus } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import Modal from '@/components/common/Modal'
import type { Column } from '@/components/common/DataTable'
import type { DailyVerse } from '@/types'
import { listVerses, createVerse, updateVerse, deleteVerse, activateVerse } from '@/api/dailyverse'

export default function VerseManagement() {
  const { t } = useTranslation()

  const columns: Column<DailyVerse>[] = [
    { key: 'verse_text', header: t('verse.verseText'), render: (v) => <span className="line-clamp-2 max-w-md font-serif italic text-sm">&ldquo;{v.verse_text}&rdquo;</span> },
    { key: 'reference', header: t('verse.reference'), render: (v) => <span className="font-semibold">{v.reference}</span> },
    { key: 'is_active', header: t('common.status'), render: (v) => <Badge variant={v.is_active ? 'success' : 'default'}>{v.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    { key: 'creator_name', header: t('events.createdBy') },
    { key: 'created_at', header: t('context.created'), render: (v) => new Date(v.created_at).toLocaleDateString() },
  ]
  const [verses, setVerses] = useState<DailyVerse[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<DailyVerse | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [form, setForm] = useState({ verse_text: '', reference: '', is_active: false })
  const [activating, setActivating] = useState<number | null>(null)

  const fetch = useCallback(async (page = 1) => {
    setLoading(true)
    try { const res = await listVerses({ page, per_page: 15 }); setVerses(res.data); setMeta(res.meta) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { fetch() }, [fetch])

  const openCreate = () => { setEditing(null); setForm({ verse_text: '', reference: '', is_active: false }); setSaveError(''); setShowModal(true) }
  const openEdit = (verse: DailyVerse) => { setEditing(verse); setForm({ verse_text: verse.verse_text, reference: verse.reference, is_active: verse.is_active }); setSaveError(''); setShowModal(true) }

  const handleSave = async () => {
    if (!form.verse_text.trim() || !form.reference.trim()) { setSaveError(t('verse.bothFieldsRequired')); return }
    setSaving(true); setSaveError('')
    try {
      if (editing) { await updateVerse(editing.id, form) } else { await createVerse(form) }
      setShowModal(false); fetch(); toast.success(editing ? t('verse.updated') : t('verse.created'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data
      setSaveError(msg?.errors ? Object.values(msg.errors).flat().join(', ') : msg?.message || t('common.loading'))
    } finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (!window.confirm(t('verse.deleteConfirm'))) return
    await deleteVerse(id); fetch(); toast.success(t('verse.deleted'))
  }

  const handleActivate = async (id: number) => {
    setActivating(id)
    try { await activateVerse(id); fetch(); toast.success(t('verse.activated')) }
    catch { setSaveError(t('verse.failedActivate')) }
    finally { setActivating(null) }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-secondary">{meta.total} {t('common.total')}</p>
        <button onClick={openCreate} className="btn-primary btn-md self-start sm:self-auto">
          <Plus className="h-4 w-4" /> {t('verse.verseManagement')}
        </button>
      </div>

      <DataTable columns={[...columns, { key: 'actions', header: '', render: (v) => (
        <div className="flex gap-2">
          {!v.is_active && <button onClick={() => handleActivate(v.id)} disabled={activating === v.id} className="btn-ghost btn-sm text-green-500 disabled:opacity-50">{activating === v.id ? t('common.loading') : t('verse.setActive')}</button>}
          <button onClick={() => openEdit(v)} className="btn-ghost btn-sm text-primary-500">{t('common.edit')}</button>
          <button onClick={() => handleDelete(v.id)} className="btn-ghost btn-sm text-red-500">{t('common.delete')}</button>
        </div>
      )}]} data={verses} meta={meta} isLoading={loading} onPageChange={fetch} />

      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title={editing ? t('verse.editVerse') : t('verse.addVerse')}
        footer={
          <div className="flex gap-3 w-full">
            <button onClick={() => setShowModal(false)} className="flex-1 btn-secondary btn-md">{t('common.cancel')}</button>
            <button onClick={handleSave} disabled={saving} className="flex-1 btn-primary btn-md">
              {saving ? t('common.saving') : (editing ? t('common.update') : t('common.create'))}
            </button>
          </div>
        }>
        <div className="space-y-3">
          <div>
            <label className="label">{t('verse.verseText')}</label>
            <textarea value={form.verse_text} onChange={(e) => setForm({ ...form, verse_text: e.target.value })} placeholder={t('verse.verseTextPlaceholder')} className="input-field" rows={3} />
            <p className="mt-1 text-xs text-muted">{t('verse.verseTextHint')}</p>
          </div>
          <div>
            <label className="label">{t('verse.reference')}</label>
            <input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} placeholder={t('verse.referencePlaceholder')} className="input-field" />
            <p className="mt-1 text-xs text-muted">{t('verse.referenceHint')}</p>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            {t('verse.setActive')}
          </label>
          {saveError && <div className="form-error">{saveError}</div>}
        </div>
      </Modal>
    </div>
  )
}