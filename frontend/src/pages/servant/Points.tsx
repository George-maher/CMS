import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import DataTable from '@/components/common/DataTable'
import StatCard from '@/components/common/StatCard'
import type { Column } from '@/components/common/DataTable'
import type { Point } from '@/types'
import { getBalance, getPointsHistory } from '@/api/points'

export default function ServantPoints() {
  const { t } = useTranslation()

  const columns: Column<Point>[] = [
    { key: 'type_label', header: t('points.type') },
    { key: 'points', header: t('points.points') },
    { key: 'created_at', header: t('points.date'), render: (p) => new Date(p.created_at).toLocaleDateString() },
    { key: 'description', header: t('points.description') },
  ]
  const [points, setPoints] = useState<Point[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
  const [balance, setBalance] = useState(0)
  const [loading, setLoading] = useState(true)

  const fetch = async (page = 1) => {
    setLoading(true)
    try {
      const [b, res] = await Promise.all([getBalance(), getPointsHistory({ page, per_page: 15 })])
      setBalance(b); setPoints(res.data); setMeta(res.meta)
    } finally { setLoading(false) }
  }

  useEffect(() => { fetch() }, [])

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard title={t('dashboard.myPoints')} value={balance} color="primary" />
      </div>
      <DataTable columns={columns} data={points} meta={meta} isLoading={loading} onPageChange={fetch} />
    </div>
  )
}
