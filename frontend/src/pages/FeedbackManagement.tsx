import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import toast from 'react-hot-toast'
import { Reply, Eye, EyeOff, Phone } from 'lucide-react'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import Modal from '@/components/common/Modal'
import { useAuth } from '@/contexts/AuthContext'
import type { Column } from '@/components/common/DataTable'
import type { Feedback } from '@/types'
import { listFeedback, resolveFeedback, replyToFeedback, getFeedback } from '@/api/feedback'

export default function FeedbackManagement() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin' || user?.role === 'assistant_admin'
  const [feedback, setFeedback] = useState<Feedback[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [unresolvedCount, setUnresolvedCount] = useState(0)
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('')
  const [selectedFeedback, setSelectedFeedback] = useState<Feedback | null>(null)
  const [replyText, setReplyText] = useState('')
  const [replying, setReplying] = useState(false)

  const fetch = async (page = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number | boolean> = { page, per_page: 15 }
      if (filter === 'unresolved') params.unresolved = true
      if (filter === 'resolved') params.is_resolved = true
      const res = await listFeedback(params)
      setFeedback(res.data); setMeta(res.meta); setUnresolvedCount(res.unresolved_count)
    } finally { setLoading(false) }
  }

  useEffect(() => { fetch() }, [filter])

  const handleResolve = async (id: number) => {
    await resolveFeedback(id); fetch(); toast.success(t('feedback.markedResolved'))
  }

  const handleViewFeedback = async (id: number) => {
    try {
      const fb = await getFeedback(id)
      setSelectedFeedback(fb)
    } catch {
      toast.error(t('common.saving'))
    }
  }

  const handleReply = async () => {
    if (!selectedFeedback || !replyText.trim()) return
    setReplying(true)
    try {
      await replyToFeedback(selectedFeedback.id, replyText.trim())
      toast.success(t('feedback.repliedSuccess'))
      setReplyText('')
      const updated = await getFeedback(selectedFeedback.id)
      setSelectedFeedback(updated)
      fetch()
    } catch {
      toast.error(t('common.saving'))
    } finally { setReplying(false) }
  }

  const columns: Column<Feedback>[] = [
    { key: 'created_at', header: t('feedback.date'), render: (f) => new Date(f.created_at).toLocaleDateString() },
    { key: 'category', header: t('feedback.category'), render: (f) => f.category ? <Badge variant="info">{f.category_label}</Badge> : <span className="text-muted">-</span> },
    { key: 'user', header: t('feedback.fromMember'), render: (f) => {
      if (f.is_anonymous && !f.user) {
        return <span className="flex items-center gap-1 text-sm text-muted"><EyeOff className="h-3 w-3" />{t('feedback.anonymous')}</span>
      }
      if (f.user) return f.user.name
      return <span className="text-muted">{t('feedback.anonymous')}</span>
    }},
    { key: 'message', header: t('feedback.message'), render: (f) => <span className="line-clamp-2">{f.message}</span> },
    { key: 'is_resolved', header: t('feedback.status'), render: (f) => <Badge variant={f.is_resolved ? 'success' : 'warning'}>{f.is_resolved ? t('feedback.resolved') : t('feedback.pending')}</Badge> },
  ]

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3 flex-wrap">
          <p className="text-sm text-secondary">{meta.total} {t('feedback.submissions')}</p>
          {unresolvedCount > 0 && (
            <span className="rounded-full bg-yellow-100 dark:bg-yellow-900/30 px-2.5 py-0.5 text-xs font-medium text-yellow-700 dark:text-yellow-400">{unresolvedCount} {t('feedback.unresolvedCount')}</span>
          )}
        </div>
        <select value={filter} onChange={(e) => setFilter(e.target.value)} className="input-field w-full sm:w-auto">
          <option value="">{t('common.all')}</option>
          <option value="unresolved">{t('feedback.unresolved')}</option>
          <option value="resolved">{t('feedback.resolved')}</option>
        </select>
      </div>

      <DataTable columns={[...columns, { key: 'actions', header: '', render: (f) => (
        <div className="flex gap-2">
          <button onClick={() => handleViewFeedback(f.id)} className="btn-ghost btn-sm"><Eye className="h-4 w-4" /></button>
          {!f.is_resolved && <button onClick={() => handleResolve(f.id)} className="btn-ghost btn-sm">{t('feedback.markResolved')}</button>}
        </div>
      )}]}
        data={feedback} meta={meta} isLoading={loading} onPageChange={fetch} emptyMessage={t('common.noDataYet')} />

      <Modal isOpen={!!selectedFeedback} onClose={() => { setSelectedFeedback(null); setReplyText('') }} title={t('feedback.feedback')} size="lg">
        {selectedFeedback && (
          <div className="space-y-4">
            <div className="flex items-center gap-2 text-sm text-secondary flex-wrap">
              {selectedFeedback.is_anonymous && !selectedFeedback.user ? (
                <span className="flex items-center gap-1"><EyeOff className="h-3 w-3" /> {t('feedback.anonymous')}</span>
              ) : selectedFeedback.user ? (
                <span className="font-medium text-foreground">{selectedFeedback.user.name}</span>
              ) : null}
              {selectedFeedback.category && <Badge variant="info">{selectedFeedback.category_label}</Badge>}
              <Badge variant={selectedFeedback.is_resolved ? 'success' : 'warning'}>
                {selectedFeedback.is_resolved ? t('feedback.resolved') : t('feedback.pending')}
              </Badge>
              <span className="ml-auto">{new Date(selectedFeedback.created_at).toLocaleString()}</span>
            </div>

            {/* Admin-only: full sender identity */}
            {isAdmin && (
              <div className="rounded-lg border border-border bg-surface-secondary p-3 text-sm">
                <div className="flex items-center gap-2 mb-1">
                  <p className="text-xs font-medium text-secondary">{t('feedback.senderInfo')}</p>
                  {selectedFeedback.is_anonymous_to_servants && (
                    <Badge variant="warning">{t('feedback.anonymousToServants')}</Badge>
                  )}
                </div>
                <p className="font-medium">{selectedFeedback.sender.name}</p>
                {selectedFeedback.sender.phone && (
                  <p className="flex items-center gap-1 text-secondary mt-0.5">
                    <Phone className="h-3 w-3" /> {selectedFeedback.sender.phone}
                  </p>
                )}
                <p className="text-secondary">
                  {[selectedFeedback.sender.class_name, selectedFeedback.sender.stage_name].filter(Boolean).join(' — ') || '-'}
                </p>
              </div>
            )}

            <p className="whitespace-pre-wrap text-sm">{selectedFeedback.message}</p>

            {selectedFeedback.replies && selectedFeedback.replies.length > 0 && (
              <div className="space-y-2 border-t pt-4">
                <p className="text-xs font-medium text-secondary">{t('feedback.replies')}</p>
                {selectedFeedback.replies.map((reply) => (
                  <div key={reply.id} className="rounded-lg bg-surface-secondary p-3">
                    <div className="flex items-center gap-2 text-xs text-secondary mb-1">
                      <span className="font-medium">{reply.user.name}</span>
                      <span>{new Date(reply.created_at).toLocaleString()}</span>
                    </div>
                    <p className="text-sm whitespace-pre-wrap">{reply.message}</p>
                  </div>
                ))}
              </div>
            )}

            <div className="border-t pt-4">
              <label className="label">{t('feedback.reply')}</label>
              <textarea value={replyText} onChange={(e) => setReplyText(e.target.value)} rows={3} className="input-field" placeholder={t('feedback.replyPlaceholder')} />
              <button onClick={handleReply} disabled={replying || !replyText.trim()} className="btn-primary btn-md mt-2">
                <Reply className="h-4 w-4" /> {replying ? t('common.saving') : t('feedback.reply')}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
