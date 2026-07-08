import { useEffect, useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Building2, Clock, User, Mail, Search, X, Shield, Phone, MapPin, FileText } from 'lucide-react'
import LoadingSpinner from '@/components/common/LoadingSpinner'
import Modal from '@/components/common/Modal'
import Badge from '@/components/common/Badge'
import DataTable from '@/components/common/DataTable'
import type { Column } from '@/components/common/DataTable'
import type { PaginationMeta } from '@/types'
import { getDeletedChurches, getDeletedChurchDetail } from '@/api/churches'
import type { DeletedChurchListItem, DeletedChurchDetail } from '@/api/churches'

export default function DeletedChurchesHistory() {
  const { t } = useTranslation()
  const [churches, setChurches] = useState<DeletedChurchListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [churchName, setChurchName] = useState('')
  const [priestName, setPriestName] = useState('')
  const [deletedFrom, setDeletedFrom] = useState('')
  const [deletedTo, setDeletedTo] = useState('')
  const [detailModal, setDetailModal] = useState(false)
  const [detail, setDetail] = useState<DeletedChurchDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const fetchChurches = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number | undefined> = {
        page,
        per_page: 15,
        search: search || undefined,
        church_name: churchName || undefined,
        priest_name: priestName || undefined,
        deleted_from: deletedFrom || undefined,
        deleted_to: deletedTo || undefined,
      }
      const res = await getDeletedChurches(params)
      setChurches(res.data)
      setMeta(res.meta)
    } catch {
      setChurches([])
    } finally {
      setLoading(false)
    }
  }, [search, churchName, priestName, deletedFrom, deletedTo])

  useEffect(() => { fetchChurches() }, [fetchChurches])

  const clearFilters = () => {
    setSearch('')
    setChurchName('')
    setPriestName('')
    setDeletedFrom('')
    setDeletedTo('')
  }

  const hasFilters = search || churchName || priestName || deletedFrom || deletedTo

  const openDetail = async (church: DeletedChurchListItem) => {
    setDetailLoading(true)
    setDetailModal(true)
    try {
      const res = await getDeletedChurchDetail(church.id)
      setDetail(res.data)
    } catch {
      setDetail(null)
    } finally {
      setDetailLoading(false)
    }
  }

  const closeDetail = () => {
    setDetailModal(false)
    setDetail(null)
  }

  const columns: Column<DeletedChurchListItem>[] = [
    {
      key: 'name',
      header: t('deletedChurches.churchName'),
      render: (c) => (
        <span className="font-medium">{c.name}</span>
      ),
    },
    {
      key: 'priest_name',
      header: t('deletedChurches.priestName'),
      render: (c) => c.priest_name || '-',
    },
    {
      key: 'contact_email',
      header: t('deletedChurches.email'),
      render: (c) => c.contact_email || '-',
    },
    {
      key: 'slug',
      header: t('deletedChurches.slug'),
      render: (c) => (
        <code className="text-xs bg-surface-tertiary/50 px-1.5 py-0.5 rounded">{c.slug}</code>
      ),
    },
    {
      key: 'created_at',
      header: t('deletedChurches.createdDate'),
      render: (c) => new Date(c.created_at).toLocaleDateString(),
    },
    {
      key: 'deleted_at',
      header: t('deletedChurches.deletedDate'),
      render: (c) => (
        <span className="text-danger">{new Date(c.deleted_at).toLocaleDateString()}</span>
      ),
    },
    {
      key: 'deleted_by',
      header: t('deletedChurches.deletedBy'),
      render: (c) => c.deleted_by?.name || '-',
    },
    {
      key: 'status',
      header: t('common.status'),
      render: () => <Badge variant="danger">{t('deletedChurches.deleted')}</Badge>,
    },
  ]

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('deletedChurches.title')}</h1>
          <p className="text-sm text-secondary mt-1">{t('deletedChurches.description')}</p>
        </div>
        <div className="flex items-center gap-2 text-sm text-muted">
          <Clock className="h-4 w-4" />
          <span>{t('deletedChurches.totalDeleted', { count: meta.total })}</span>
        </div>
      </div>

      {/* Filters */}
      <div className="card">
        <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end sm:flex-wrap">
          <div className="flex-1 min-w-0 sm:max-w-xs">
            <label className="text-xs font-medium text-muted mb-1 block">{t('common.search')}</label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('deletedChurches.searchPlaceholder')}
                className="input-field w-full pl-9"
              />
              {search && (
                <button onClick={() => setSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-secondary">
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>
          <div className="w-full sm:w-44">
            <label className="text-xs font-medium text-muted mb-1 block">{t('deletedChurches.churchName')}</label>
            <input
              type="text"
              value={churchName}
              onChange={(e) => setChurchName(e.target.value)}
              placeholder={t('deletedChurches.churchNamePlaceholder')}
              className="input-field w-full"
            />
          </div>
          <div className="w-full sm:w-44">
            <label className="text-xs font-medium text-muted mb-1 block">{t('deletedChurches.priestName')}</label>
            <input
              type="text"
              value={priestName}
              onChange={(e) => setPriestName(e.target.value)}
              placeholder={t('deletedChurches.priestNamePlaceholder')}
              className="input-field w-full"
            />
          </div>
          <div className="w-full sm:w-40">
            <label className="text-xs font-medium text-muted mb-1 block">{t('deletedChurches.deletedFrom')}</label>
            <input
              type="date"
              value={deletedFrom}
              onChange={(e) => setDeletedFrom(e.target.value)}
              className="input-field w-full"
            />
          </div>
          <div className="w-full sm:w-40">
            <label className="text-xs font-medium text-muted mb-1 block">{t('deletedChurches.deletedTo')}</label>
            <input
              type="date"
              value={deletedTo}
              onChange={(e) => setDeletedTo(e.target.value)}
              className="input-field w-full"
            />
          </div>
          {hasFilters && (
            <button onClick={clearFilters} className="btn-ghost btn-sm text-danger">
              <X className="h-4 w-4" />
              {t('common.clear')}
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <DataTable
        columns={columns}
        data={churches}
        meta={meta}
        isLoading={loading}
        onPageChange={(p) => fetchChurches(p)}
        onRowClick={openDetail}
        emptyMessage={t('deletedChurches.noDeletedChurches')}
      />

      {/* Detail Modal */}
      <Modal
        isOpen={detailModal}
        onClose={closeDetail}
        title={detail?.name || t('deletedChurches.churchDetails')}
        size="lg"
      >
        {detailLoading ? (
          <LoadingSpinner className="py-10" />
        ) : detail ? (
          <div className="space-y-6">
            {/* Status Banner */}
            <div className="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger-light/50 p-4">
              <Building2 className="h-6 w-6 text-danger shrink-0" />
              <div>
                <p className="font-semibold text-danger">{t('deletedChurches.deletedStatus')}</p>
                <p className="text-sm text-danger-dark">
                  {t('deletedChurches.deletedOn')} {new Date(detail.deleted_at).toLocaleString()}
                  {detail.deleted_by && ` ${t('deletedChurches.by')} ${detail.deleted_by.name}`}
                </p>
              </div>
            </div>

            {/* Church Information */}
            <div className="rounded-lg border border-border p-4 space-y-3">
              <h3 className="flex items-center gap-2 font-semibold text-gold-500">
                <Building2 className="h-5 w-5" />
                {t('deletedChurches.churchInfo')}
              </h3>
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.churchName')}</p>
                  <p className="font-medium">{detail.name}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.slug')}</p>
                  <code className="text-sm bg-surface-tertiary/50 px-1.5 py-0.5 rounded">{detail.slug}</code>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.serviceName')}</p>
                  <p>{detail.service_name || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.description')}</p>
                  <p className="text-sm">{detail.description || '-'}</p>
                </div>
              </div>
            </div>

            {/* Contact Information */}
            <div className="rounded-lg border border-border p-4 space-y-3">
              <h3 className="flex items-center gap-2 font-semibold text-gold-500">
                <User className="h-5 w-5" />
                {t('deletedChurches.contactInfo')}
              </h3>
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex items-center gap-2">
                  <User className="h-4 w-4 text-muted shrink-0" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.priestName')}</p>
                    <p>{detail.priest_name || '-'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Shield className="h-4 w-4 text-muted shrink-0" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.mainServant')}</p>
                    <p>{detail.main_servant_name || '-'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Phone className="h-4 w-4 text-muted shrink-0" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.priestPhone')}</p>
                    <p>{detail.priest_phone || '-'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Phone className="h-4 w-4 text-muted shrink-0" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.churchPhone')}</p>
                    <p>{detail.phone || '-'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2 sm:col-span-2">
                  <Mail className="h-4 w-4 text-muted shrink-0" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.email')}</p>
                    <p>{detail.contact_email || '-'}</p>
                  </div>
                </div>
                <div className="flex items-start gap-2 sm:col-span-2">
                  <MapPin className="h-4 w-4 text-muted shrink-0 mt-0.5" />
                  <div>
                    <p className="text-xs text-muted">{t('deletedChurches.address')}</p>
                    <p>{detail.address || '-'}</p>
                  </div>
                </div>
              </div>
            </div>

            {/* Deletion Information */}
            <div className="rounded-lg border border-border p-4 space-y-3">
              <h3 className="flex items-center gap-2 font-semibold text-danger">
                <Clock className="h-5 w-5" />
                {t('deletedChurches.deletionInfo')}
              </h3>
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.deletedAt')}</p>
                  <p className="text-danger font-medium">{new Date(detail.deleted_at).toLocaleString()}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.deletedBy')}</p>
                  <p>{detail.deleted_by?.name || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.deletionType')}</p>
                  <Badge variant="danger">{detail.deletion_type || 'soft'}</Badge>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.recoverableUntil')}</p>
                  <p>{detail.recoverable_until ? new Date(detail.recoverable_until).toLocaleDateString() : '-'}</p>
                </div>
              </div>
            </div>

            {/* Timestamps */}
            <div className="rounded-lg border border-border p-4 space-y-3">
              <h3 className="flex items-center gap-2 font-semibold text-muted">
                <FileText className="h-5 w-5" />
                {t('deletedChurches.timestamps')}
              </h3>
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.createdAt')}</p>
                  <p>{new Date(detail.created_at).toLocaleString()}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.updatedAt')}</p>
                  <p>{detail.updated_at ? new Date(detail.updated_at).toLocaleString() : '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('deletedChurches.memberCount')}</p>
                  <p>{detail.member_count}</p>
                </div>
                <div>
                  <p className="text-xs text-muted">{t('common.status')}</p>
                  <Badge variant="danger">{t('deletedChurches.deleted')}</Badge>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="py-10 text-center text-muted">{t('common.failedToLoad')}</div>
        )}
      </Modal>
    </div>
  )
}
