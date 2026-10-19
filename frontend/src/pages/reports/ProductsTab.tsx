import { useState, useEffect } from 'react'
import { getTopProducts } from '../../api/endpoints'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import { DEFAULT_PAGE_SIZE } from '../../api/constants'

export default function ProductsTab() {
  const [topProducts, setTopProducts] = useState<any[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let ignore = false
    getTopProducts({ limit: DEFAULT_PAGE_SIZE })
      .then((r) => {
        if (!ignore) {
          setTopProducts((r.data.data as any[]) ?? [])
          setLoading(false)
        }
      })
      .catch(() => {
        if (!ignore) {
          setLoading(false)
        }
      })
    return () => {
      ignore = true
    }
  }, [])

  return (
    <div className="card">
      {loading && <div style={{ padding: '1rem', textAlign: 'center' }}><span className="spinner" /> جاري التحميل...</div>}
      {!loading && (
        <div className="table-wrapper">
          <table>
            <thead><tr><th>#</th><th>المنتج</th><th>الوحدات المباعة</th><th>الإيرادات</th><th>الأرباح</th></tr></thead>
            <tbody>
              {topProducts.length === 0 ? (
                <tr><td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا بيانات</td></tr>
              ) : topProducts.map((p, i) => (
                <tr key={p.id}>
                  <td style={{ color: 'var(--text-muted)', fontWeight: 600 }}>#{formatNumber(i + 1)}</td>
                  <td style={{ fontWeight: 600 }}>{p.name}</td>
                  <td>
                    <span className="badge badge-blue">
                      {parseFloat(p.total_sold) % 1 !== 0 ? `${parseFloat(p.total_sold).toFixed(3)} كجم` : formatNumber(p.total_sold)}
                    </span>
                  </td>
                  <td style={{ color: 'var(--primary)', fontWeight: 700 }}>{formatCurrency(p.total_revenue)}</td>
                  <td style={{ color: 'var(--secondary)', fontWeight: 600 }}>{formatCurrency(p.total_profit)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
