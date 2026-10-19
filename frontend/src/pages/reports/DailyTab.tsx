import { useCallback, useEffect, useState } from 'react'
import { getDailyReport } from '../../api/endpoints'
import { formatCurrency, formatNumber, formatTime } from '../../utils/formatters'
import useSettingsStore from '../../store/settingsStore'
import { SCard, profitColor } from './components/SCard'
import { Coins, Box, TrendingUp, TrendingDown, Sparkles, FileText, Tag, BarChart3, RefreshCw } from 'lucide-react'

const PAGE_SIZE = 100

interface DailyPagination {
  page: number;
  limit: number;
  total: number;
  pages: number;
  hasMore: boolean;
}

export default function DailyTab() {
  const taxEnabled = useSettingsStore((s) => s.taxEnabled)
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [daily, setDaily] = useState<DailyReport | null>(null)
  const [pagination, setPagination] = useState<DailyPagination | null>(null)
  const [loading, setLoading] = useState(false)

  const loadDaily = useCallback(async (page = 1) => {
    setLoading(true)
    try {
      const response = await getDailyReport({ date, page, limit: PAGE_SIZE })
      const metadata = response.data.pagination
      setDaily(response.data.data)
      setPagination({
        page: metadata?.page ?? page,
        limit: metadata?.limit ?? PAGE_SIZE,
        total: metadata?.total ?? response.data.data.invoices.length,
        pages: metadata?.pages ?? 1,
        hasMore: metadata?.has_more === true,
      })
    } finally {
      setLoading(false)
    }
  }, [date])

  useEffect(() => {
    void loadDaily(1)
  }, [loadDaily])

  const firstVisible = pagination && pagination.total > 0
    ? ((pagination.page - 1) * pagination.limit) + 1
    : 0
  const lastVisible = pagination
    ? Math.min(pagination.page * pagination.limit, pagination.total)
    : 0

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center' }}>
        <input
          type="date"
          className="input"
          style={{ maxWidth: '180px' }}
          value={date}
          onChange={(event) => setDate(event.target.value)}
        />
        <button
          className="btn btn-primary"
          onClick={() => void loadDaily(pagination?.page ?? 1)}
          disabled={loading}
          style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}
        >
          {loading ? <span className="spinner" /> : <RefreshCw size={14} />} عرض
        </button>
      </div>

      {daily && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
            <SCard label="الفواتير" value={formatNumber(daily.summary?.total_invoices ?? 0)} icon={FileText} />
            <SCard label="الإيرادات" value={formatCurrency(daily.summary?.total_revenue)} color="var(--primary)" icon={Coins} />
            <SCard label="إجمالي التكاليف" value={formatCurrency(daily.summary?.total_cost ?? 0)} color="var(--warning)" icon={Box} title="تكلفة البضاعة المباعة — للتاريخ المختار" />
            <SCard label="ربح المبيعات" value={formatCurrency(daily.summary?.total_profit ?? 0)} color="var(--primary-d)" icon={TrendingUp} title="الإيرادات − التكاليف — للتاريخ المختار" />
            <SCard label="المصروفات" value={formatCurrency(daily.summary?.total_expenses ?? 0)} color="var(--danger)" icon={TrendingDown} />
            <SCard label="صافي الربح الفعلي" value={formatCurrency(daily.summary?.net_profit ?? 0)} color={profitColor(daily.summary?.net_profit)} icon={Sparkles} title="ربح المبيعات − المصروفات" />
            <SCard label="الخصومات" value={formatCurrency(daily.summary?.total_discount)} icon={Tag} />
            {taxEnabled && (
              <SCard label="الضرائب" value={formatCurrency(daily.summary?.total_tax)} icon={BarChart3} />
            )}
          </div>

          <div className="card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.75rem', alignItems: 'center', padding: '0.75rem 1rem' }}>
              <strong>فواتير اليوم</strong>
              <span style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                عرض {formatNumber(firstVisible)}–{formatNumber(lastVisible)} من {formatNumber(pagination?.total ?? 0)}
              </span>
            </div>
            <div className="table-wrapper">
              <table>
                <thead><tr><th>#</th><th>الكاشير</th><th>الإجمالي</th><th>الدفع</th><th>الوقت</th></tr></thead>
                <tbody>
                  {daily.invoices.length === 0 ? (
                    <tr><td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد فواتير في هذه الصفحة</td></tr>
                  ) : daily.invoices.map((invoice) => (
                    <tr key={invoice.id}>
                      <td>#{formatNumber(invoice.id)}</td>
                      <td>{invoice.cashier_name}</td>
                      <td style={{ color: 'var(--primary)', fontWeight: 700 }}>{formatCurrency(invoice.total)}</td>
                      <td><span className={`badge ${invoice.payment_method === 'cash' ? 'badge-green' : 'badge-blue'}`}>{invoice.payment_method === 'cash' ? 'نقدي' : 'بطاقة'}</span></td>
                      <td style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>{formatTime(invoice.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {pagination && pagination.pages > 1 && (
              <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: '0.75rem', padding: '1rem' }}>
                <button
                  className="btn btn-secondary"
                  disabled={loading || pagination.page <= 1}
                  onClick={() => void loadDaily(pagination.page - 1)}
                >
                  السابق
                </button>
                <span>صفحة {formatNumber(pagination.page)} من {formatNumber(pagination.pages)}</span>
                <button
                  className="btn btn-secondary"
                  disabled={loading || !pagination.hasMore}
                  onClick={() => void loadDaily(pagination.page + 1)}
                >
                  التالي
                </button>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
