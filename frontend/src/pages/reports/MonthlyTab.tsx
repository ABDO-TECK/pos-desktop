import { useState, useEffect } from 'react'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import { getMonthlyReport } from '../../api/endpoints'
import { formatCurrency, formatNumber, formatShortDate } from '../../utils/formatters'
import { SCard, profitColor } from './components/SCard'
import { Coins, Box, TrendingUp, TrendingDown, Sparkles, FileText, RefreshCw } from 'lucide-react'
import NumericInput from '../../components/forms/NumericInput'
import useReportChartTheme from './components/useReportChartTheme'

export default function MonthlyTab() {
  const [month, setMonth] = useState(new Date().getMonth() + 1)
  const [year, setYear] = useState(new Date().getFullYear())
  const [monthly, setMonthly] = useState<any>(null)
  const [loading, setLoading] = useState(false)
  const chartTheme = useReportChartTheme()

  const loadMonthly = async () => {
    setLoading(true)
    try {
      const res = await getMonthlyReport({ month, year })
      setMonthly(res.data.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { loadMonthly() }, [month, year])

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
        <select className="input" style={{ maxWidth: '150px' }} value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
          {Array.from({ length: 12 }, (_, i) => (
            <option key={i + 1} value={i + 1}>{new Date(2000, i).toLocaleString('ar-EG', { month: 'long' })}</option>
          ))}
        </select>
        <NumericInput className="input" style={{ maxWidth: '100px' }} value={year} onChange={(e) => setYear(parseInt(e.target.value, 10) || new Date().getFullYear())} />
        <button className="btn btn-primary" onClick={loadMonthly} disabled={loading} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
          {loading ? <span className="spinner" /> : <RefreshCw size={14} />} عرض
        </button>
      </div>

      {monthly && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
            <SCard label="الإيرادات الشهرية" value={formatCurrency(monthly.total_revenue)} color="var(--primary)" icon={Coins} />
            <SCard label="إجمالي التكاليف" value={formatCurrency(monthly.total_cost ?? 0)} color="var(--warning)" icon={Box} title="تكلفة البضاعة المباعة — للشهر المختار" />
            <SCard label="ربح المبيعات" value={formatCurrency(monthly.total_profit ?? 0)} color="var(--primary-d)" icon={TrendingUp} title="الإيرادات − التكاليف — للشهر المختار" />
            <SCard label="المصروفات" value={formatCurrency(monthly.total_expenses ?? 0)} color="var(--danger)" icon={TrendingDown} />
            <SCard label="صافي الربح الفعلي" value={formatCurrency(monthly.net_profit ?? 0)} color={profitColor(monthly.net_profit)} icon={Sparkles} title="ربح المبيعات − المصروفات" />
            <SCard label="الفواتير" value={formatNumber(monthly.total_invoices)} icon={FileText} />
          </div>

          {monthly.daily_breakdown?.length > 0 && (
            <div className="card" style={{ padding: '1.25rem' }}>
              <h3 style={{ fontWeight: 700, marginBottom: '1rem', fontSize: '1rem' }}>الإيرادات اليومية</h3>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={monthly.daily_breakdown.map((d: any) => ({ ...d, label: formatShortDate(d.date) }))}
                  margin={{ top: 5, right: 10, left: 10, bottom: 20 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke={chartTheme.grid} />
                  <XAxis dataKey="label" tick={{ fontSize: 10, fill: chartTheme.axis }} angle={-30} textAnchor="end" />
                  <YAxis tick={{ fontSize: 11, fill: chartTheme.axis }} />
                  <Tooltip
                    formatter={(v) => [formatCurrency(v as number), 'الإيراد']}
                    contentStyle={{ backgroundColor: chartTheme.tooltipBackground, border: `1px solid ${chartTheme.tooltipBorder}`, color: chartTheme.tooltipText }}
                    labelStyle={{ color: chartTheme.tooltipText }}
                    itemStyle={{ color: chartTheme.tooltipText }}
                    cursor={{ fill: chartTheme.cursor }}
                  />
                  <Bar dataKey="total_revenue" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </>
      )}
    </div>
  )
}
