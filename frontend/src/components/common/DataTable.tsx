import { type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { PaginationMeta } from '@/types'
import LoadingSpinner from './LoadingSpinner'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTheme } from '@/contexts/ThemeContext'

export interface Column<T> {
  key: string
  header: string
  render?: (item: T) => ReactNode
}

interface Props<T> {
  columns: Column<T>[]
  data: T[]
  meta?: PaginationMeta
  isLoading?: boolean
  onPageChange?: (page: number) => void
  onRowClick?: (item: T) => void
  emptyMessage?: string
}

export default function DataTable<T extends { id: number }>({
  columns,
  data,
  meta,
  isLoading,
  onPageChange,
  onRowClick,
  emptyMessage,
}: Props<T>) {
  const { t } = useTranslation()
  const { dir } = useTheme()

  if (isLoading) return <LoadingSpinner className="py-12" />

  const renderCell = (item: T, col: Column<T>) =>
    col.render ? col.render(item) : (item[col.key as keyof T] as ReactNode) ?? '-'

  return (
    <div className="card" style={{ borderColor: 'var(--color-border)' }}>
      {/* Desktop table view */}
      <div className="hidden sm:block overflow-x-auto">
        <table className="w-full" style={{ borderCollapse: 'separate', borderSpacing: 0, textAlign: dir === 'rtl' ? 'right' : 'left' }}>
          <thead>
            <tr className="bg-surface-tertiary/50">
              {columns.map((col) => (
                <th key={col.key} className="table-header px-4 py-3" style={{ borderBottom: '1px solid var(--color-border)' }}>
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-12 text-center text-sm text-muted">
                  {emptyMessage || t('common.noData')}
                </td>
              </tr>
            ) : (
              data.map((item, i) => (
                <tr
                  key={item.id ?? i}
                  onClick={() => onRowClick?.(item)}
                  className={`transition-colors ${
                    onRowClick ? 'cursor-pointer' : ''
                  } ${i % 2 === 1 ? 'bg-surface-tertiary/30' : ''} hover:bg-surface-secondary`}
                  style={{ borderBottom: i < data.length - 1 ? '1px solid var(--color-border)' : 'none' }}
                >
                  {columns.map((col) => (
                    <td key={col.key} className="table-cell px-4 py-3">
                      {renderCell(item, col)}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Mobile card view */}
      <div className="sm:hidden space-y-3 p-3">
        {data.length === 0 ? (
          <div className="py-12 text-center text-sm text-muted">
            {emptyMessage || t('common.noData')}
          </div>
        ) : (
          data.map((item, i) => (
            <div
              key={item.id ?? i}
              onClick={() => onRowClick?.(item)}
              className={`rounded-xl border p-4 space-y-2 ${onRowClick ? 'cursor-pointer' : ''} hover:border-gold-300/50 transition-colors`}
              style={{ borderColor: 'var(--color-border)' }}
            >
              {columns.map((col) => (
                <div key={col.key} className="flex items-start gap-2">
                  <span className="text-xs font-medium text-muted uppercase shrink-0 min-w-[80px]">{col.header}</span>
                  <span className="text-sm flex-1 break-words">{renderCell(item, col)}</span>
                </div>
              ))}
            </div>
          ))
        )}
      </div>

      {meta && meta.last_page > 1 && onPageChange && (
        <div className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" style={{ borderTop: '1px solid var(--color-border)' }}>
          <p className="text-sm text-secondary">
            {t('common.page')} {meta.current_page} {t('common.of')} {meta.last_page} ({meta.total} {t('common.total')})
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => onPageChange(meta.current_page - 1)}
              disabled={meta.current_page <= 1}
              className="btn-secondary btn-sm"
            >
              {dir === 'rtl' ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
              <span className="hidden sm:inline">{t('common.prev')}</span>
            </button>
            <button
              onClick={() => onPageChange(meta.current_page + 1)}
              disabled={meta.current_page >= meta.last_page}
              className="btn-secondary btn-sm"
            >
              <span className="hidden sm:inline">{t('common.next')}</span>
              {dir === 'rtl' ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
