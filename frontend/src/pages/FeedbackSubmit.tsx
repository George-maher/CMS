import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { Send, CheckCircle, Eye, EyeOff, MessageSquare, Reply, MessageCircle, Phone, Shield, ShieldCheck, User } from 'lucide-react'
import Badge from '@/components/common/Badge'
import Modal from '@/components/common/Modal'
import { useAuth } from '@/contexts/AuthContext'
import { submitFeedback, getMyFeedback, markFeedbackSeen, getFeedback } from '@/api/feedback'
import { getMyClassServants } from '@/api/structure'
import type { ClassContact } from '@/api/structure'
import type { Feedback } from '@/types'

export default function FeedbackSubmit() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const isMember = user?.role === 'member'
  const [searchParams, setSearchParams] = useSearchParams()
  const [message, setMessage] = useState('')
  const [category, setCategory] = useState('')
  const [isAnonymous, setIsAnonymous] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [myFeedback, setMyFeedback] = useState<Feedback[]>([])
  const [historyLoading, setHistoryLoading] = useState(true)
  const [servants, setServants] = useState<ClassContact[]>([])
  const [servantsLoading, setServantsLoading] = useState(true)
  const [tab, setTab] = useState<'submit' | 'history' | 'servants'>('submit')
  const [selectedFeedback, setSelectedFeedback] = useState<Feedback | null>(null)

  const feedbackIdParam = searchParams.get('feedbackId')

  const openFeedbackById = useCallback(async (id: number) => {
    try {
      const fb = await getFeedback(id)
      setSelectedFeedback(fb)
      setTab('history')
    } catch { /* ignore */ }
  }, [])

  useEffect(() => {
    getMyFeedback({ per_page: 20 }).then((res) => {
      setMyFeedback(res.data)
      if (feedbackIdParam) {
        const found = res.data.find((f) => f.id === Number(feedbackIdParam))
        if (found) {
          setSelectedFeedback(found)
          setTab('history')
        } else {
          openFeedbackById(Number(feedbackIdParam))
        }
        setSearchParams({}, { replace: true })
      }
    }).catch(() => setMyFeedback([])).finally(() => setHistoryLoading(false))
    if (isMember) {
      getMyClassServants()
        .then(setServants)
        .catch(() => setServants([]))
        .finally(() => setServantsLoading(false))
    }
  }, [submitted, feedbackIdParam, openFeedbackById, setSearchParams, isMember])

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (message.length < 10) { setError(t('feedback.minLengthError')); return }
    setLoading(true); setError('')
    try {
      await submitFeedback({ message, ...(category ? { category } : {}), is_anonymous: isAnonymous })
      setSubmitted(true); setMessage(''); setCategory(''); setIsAnonymous(false)
      toast.success(t('feedback.submittedSuccess'))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || t('common.saving')
      setError(msg)
    } finally { setLoading(false) }
  }

  const handleViewFeedback = async (fb: Feedback) => {
    setSelectedFeedback(fb)
    if (fb.has_new_reply) {
      try {
        const updated = await markFeedbackSeen(fb.id)
        setSelectedFeedback(updated)
        setMyFeedback((prev) => prev.map((f) => (f.id === updated.id ? updated : f)))
      } catch { /* ignore */ }
    }
  }

  const newReplyCount = myFeedback.filter((fb) => fb.has_new_reply).length

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <div className="flex gap-2 border-b border-border overflow-x-auto">
        <button onClick={() => setTab('submit')}
          className={`shrink-0 px-4 py-2.5 text-sm font-medium transition-colors ${tab === 'submit' ? 'border-b-2 border-primary-500 text-primary-500' : 'text-secondary'}`}>
          {t('feedback.submitFeedback')}
        </button>
        <button onClick={() => setTab('history')}
          className={`shrink-0 px-4 py-2.5 text-sm font-medium transition-colors ${tab === 'history' ? 'border-b-2 border-primary-500 text-primary-500' : 'text-secondary'}`}>
          {t('feedback.feedback')} ({myFeedback.length})
          {newReplyCount > 0 && (
            <span className="ml-1.5 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-[11px] font-bold text-white">
              {newReplyCount}
            </span>
          )}
        </button>
        {isMember && (
          <button onClick={() => setTab('servants')}
            className={`shrink-0 px-4 py-2.5 text-sm font-medium transition-colors ${tab === 'servants' ? 'border-b-2 border-primary-500 text-primary-500' : 'text-secondary'}`}>
            {t('feedback.servantContacts')} ({servants.length})
          </button>
        )}
      </div>

      {tab === 'submit' && (
        <>
          {submitted ? (
            <div className="card p-5 py-8 text-center">
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                <CheckCircle className="h-8 w-8 text-green-600 dark:text-green-400" />
              </div>
              <h2 className="text-lg font-semibold">{t('feedback.thankYou')}</h2>
              <p className="mt-2 text-sm text-secondary">{t('feedback.thankYouMessage')}</p>
              <button onClick={() => setSubmitted(false)}
                className="btn-primary btn-md">
                {t('feedback.submitAnother')}
              </button>
            </div>
          ) : (
            <div className="card p-5">
              <h2 className="text-lg font-semibold">{t('feedback.submitFeedback')}</h2>
              <p className="mt-1 text-sm text-secondary">{t('feedback.anonymousNote')}</p>
              <form onSubmit={handleSubmit} className="mt-4 space-y-4">
                <div>
                  <label className="label">{t('feedback.category')}</label>
                  <select value={category} onChange={(e) => setCategory(e.target.value)}
                    className="input-field">
                    <option value="">{t('common.filter')}</option>
                    <option value="complaint">{t('feedback.complaint')}</option>
                    <option value="suggestion">{t('feedback.suggestion')}</option>
                    <option value="other">{t('feedback.other')}</option>
                  </select>
                </div>
                <div className="flex items-center gap-3">
                  <label className="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" checked={isAnonymous} onChange={(e) => setIsAnonymous(e.target.checked)} className="peer sr-only" />
                    <div className="h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-primary-500 peer-checked:after:translate-x-full"></div>
                  </label>
                  <span className="text-sm text-secondary">{t('feedback.submitAnonymously')}</span>
                  {isAnonymous ? <EyeOff className="h-4 w-4 text-muted" /> : <Eye className="h-4 w-4 text-muted" />}
                </div>
                <div>
                  <label className="label">{t('feedback.message')}</label>
                  <textarea value={message} onChange={(e) => setMessage(e.target.value)} placeholder={t('feedback.shareThoughts')} required minLength={10} rows={5}
                    className="input-field" />
                </div>
                {error && <div className="form-error">{error}</div>}
                <button type="submit" disabled={loading}
                  className="btn-primary btn-md w-full">
                  {loading ? null : <Send className="h-4 w-4" />}
                  {loading ? t('common.saving') : t('common.submit')}
                </button>
              </form>
            </div>
          )}
        </>
      )}

      {tab === 'history' && (
        <div className="card p-5">
          <h2 className="text-lg font-semibold mb-4">{t('feedback.feedback')}</h2>
          {historyLoading ? (
            <div className="flex justify-center py-8"><div className="h-8 w-8 animate-spin rounded-full border-4 border-primary-200 border-t-primary-600" /></div>
          ) : myFeedback.length === 0 ? (
            <p className="text-sm text-muted text-center py-8">{t('common.noDataYet')}</p>
          ) : (
            <div className="space-y-3">
              {myFeedback.map((fb) => (
                <div key={fb.id}
                  onClick={() => handleViewFeedback(fb)}
                  className="rounded-lg border border-border p-4 cursor-pointer hover:bg-surface-secondary transition-colors">
                  <div className="flex items-center gap-2 mb-2">
                    {fb.category && <Badge variant="info">{fb.category_label}</Badge>}
                    <span className={`text-xs px-2 py-0.5 rounded-full ${fb.is_resolved ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400'}`}>
                      {fb.is_resolved ? t('feedback.resolved') : t('feedback.unresolved')}
                    </span>
                    {fb.has_new_reply && (
                      <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium">
                        {t('feedback.newReply')}
                      </span>
                    )}
                    <span className="text-xs text-muted ml-auto">{new Date(fb.created_at).toLocaleDateString()}</span>
                  </div>
                  <p className="text-sm whitespace-pre-wrap">{fb.message}</p>
                  {(fb.replies && fb.replies.length > 0) && (
                    <p className="mt-2 flex items-center gap-1 text-xs text-muted">
                      <MessageSquare className="h-3 w-3" />
                      {fb.replies.length} {fb.replies.length === 1 ? t('feedback.reply') : t('feedback.replies')}
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {tab === 'servants' && (
        <div className="card p-5">
          <h2 className="text-lg font-semibold mb-1">{t('feedback.servantContacts')}</h2>
          <p className="text-sm text-secondary mb-4">{t('feedback.servantContactsDesc')}</p>
          {servantsLoading ? (
            <div className="flex justify-center py-8"><div className="h-8 w-8 animate-spin rounded-full border-4 border-primary-200 border-t-primary-600" /></div>
          ) : servants.length === 0 ? (
            <p className="text-sm text-muted text-center py-8">{t('common.noDataYet')}</p>
          ) : (
            <div className="space-y-3">
              {servants.map((contact) => {
                const roleIcon = contact.type === 'admin' ? <ShieldCheck className="h-4 w-4 text-red-500" />
                  : contact.type === 'assistant_admin' ? <Shield className="h-4 w-4 text-orange-500" />
                  : <User className="h-4 w-4 text-primary-500" />

                const roleBadgeClass = contact.type === 'admin' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                  : contact.type === 'assistant_admin' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'
                  : 'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300'

                return (
                  <div key={contact.id} className="flex items-center justify-between rounded-lg border border-border p-4">
                    <div className="flex items-center gap-3 min-w-0">
                      {contact.avatar ? (
                        <img src={contact.avatar} alt="" className="h-10 w-10 shrink-0 rounded-full object-cover" />
                      ) : (
                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold ${roleBadgeClass}`}>
                          {contact.name.charAt(0).toUpperCase()}
                        </div>
                      )}
                      <div className="min-w-0">
                        <p className="font-medium truncate flex items-center gap-1.5">
                          {contact.name}
                          {roleIcon}
                        </p>
                        <span className={`inline-block mt-0.5 text-[11px] font-medium px-1.5 py-0.5 rounded-full ${roleBadgeClass}`}>
                          {contact.role_label}
                        </span>
                        {contact.phone && (
                          <p className="text-sm text-secondary truncate">{contact.phone}</p>
                        )}
                      </div>
                    </div>
                    {contact.phone && (
                      <div className="flex items-center gap-2 shrink-0">
                        <a
                          href={`tel:${contact.phone}`}
                          className="btn-icon btn-ghost rounded-lg p-2 hover:bg-primary-100 hover:text-primary-600"
                          title={t('absentMembers.call')}
                        >
                          <Phone className="h-4 w-4" />
                        </a>
                        <a
                          href={`https://wa.me/${contact.phone.replace(/[^0-9]/g, '')}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="btn-icon btn-ghost rounded-lg p-2 hover:bg-emerald-100 hover:text-emerald-600"
                          title={t('common.whatsapp')}
                        >
                          <MessageCircle className="h-4 w-4" />
                        </a>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      )}

      <Modal isOpen={!!selectedFeedback} onClose={() => setSelectedFeedback(null)} title={t('feedback.feedback')} size="lg">
        {selectedFeedback && (
          <div className="space-y-4">
            <div className="flex items-center gap-2 text-sm text-secondary">
              {selectedFeedback.category && <Badge variant="info">{selectedFeedback.category_label}</Badge>}
              <Badge variant={selectedFeedback.is_resolved ? 'success' : 'warning'}>
                {selectedFeedback.is_resolved ? t('feedback.resolved') : t('feedback.pending')}
              </Badge>
              <span className="ml-auto">{new Date(selectedFeedback.created_at).toLocaleString()}</span>
            </div>
            <p className="whitespace-pre-wrap text-sm">{selectedFeedback.message}</p>

            {selectedFeedback.replies && selectedFeedback.replies.length > 0 && (
              <div className="space-y-2 border-t pt-4">
                <p className="text-xs font-medium text-secondary">{t('feedback.replies')}</p>
                {selectedFeedback.replies.map((reply) => (
                  <div key={reply.id} className="rounded-lg bg-surface-secondary p-3">
                    <div className="flex items-center gap-2 text-xs text-secondary mb-1">
                      <Reply className="h-3 w-3" />
                      <span className="font-medium">{reply.user.name}</span>
                      <span>{new Date(reply.created_at).toLocaleString()}</span>
                    </div>
                    <p className="text-sm whitespace-pre-wrap">{reply.message}</p>
                  </div>
                ))}
              </div>
            )}

            {(!selectedFeedback.replies || selectedFeedback.replies.length === 0) && (
              <p className="text-sm text-muted text-center py-4">{t('feedback.noRepliesYet')}</p>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}
