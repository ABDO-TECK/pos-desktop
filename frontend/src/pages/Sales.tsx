import { useState, useEffect, useRef } from 'react'

import { useNavigate } from 'react-router-dom'
import { Eye, X, Printer, Trash2, ShoppingCart } from 'lucide-react'
import toast from 'react-hot-toast'
import { getSales, getSale, deleteSale } from '../api/endpoints'
import { formatCurrency, formatDate, formatNumber } from '../utils/formatters'
import { browserPrint, buildReceiptHTML } from '../utils/receiptBuilder'
import useSettingsStore from '../store/settingsStore'
import useAuthStore from '../store/authStore'
import useCartStore from '../store/cartStore'
import useQZPrinter from '../hooks/useQZPrinter'
import { QZStatusBar, QZPrinterPicker, QZPrintButton } from '../components/QZPrinterUI'
import Pagination from '../components/Pagination'
import { useConfirmStore } from '../store/confirmStore'
import TotalRow from '../components/common/TotalRow'
import { extractApiError } from '../utils/apiError'
import SaleDetailModal from './sales/SaleDetailModal'

const METHOD_LABELS: Record<string, string> = {
  cash:          'نقدي',
  card:          'بطاقة',
  vodafone_cash: 'فودافون كاش',
  instapay:      'انستاباي',
  other_wallet:  'محفظة أخرى',
  credit:        'آجل',
}

interface SalesFilters {
  date: string
  month: string
  year: string
  search: string
}

export default function Sales() {
  const [sales, setSales]       = useState<Sale[]>([])
  const [loading, setLoading]   = useState(false)
  const [selected, setSelected] = useState<Sale | null>(null)
  const [detailLoading, setDL]  = useState(false)
  const [deleting, setDeleting]  = useState(false)
  const [filters, setFilters]   = useState<SalesFilters>({ date: '', month: '', year: '', search: '' })
  const [currentPage, setCurrentPage] = useState(1)
  const [totalPages, setTotalPages]   = useState(1)
  const searchTimer             = useRef<ReturnType<typeof setTimeout> | null>(null)
  const currentYear             = new Date().getFullYear()
  const yearOptions             = Array.from({ length: 6 }, (_, i) => currentYear - 3 + i)
  const settings                = useSettingsStore()
  const navigate                = useNavigate()
  const user                    = useAuthStore((s) => s.user)
  const mergeInvoiceLines       = useCartStore((s) => s.mergeInvoiceLines)
  const { confirm }             = useConfirmStore()
  const isAdmin                 = user?.role === 'admin'
  const qz = useQZPrinter()

  const load = async (f: SalesFilters = filters, p = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { page: p, limit: 15 }
      if (f.date)   params.date   = f.date
      if (f.month)  params.month  = f.month
      if (f.year)   params.year   = f.year
      if (f.search) params.search = f.search
      const res = await getSales(params)
      setSales(res.data.data ?? [])
      
      const pg = res.data.pagination
      if (pg) {
        setTotalPages(pg.pages || 1)
        setCurrentPage(pg.page || 1)
      } else {
        setTotalPages(1)
        setCurrentPage(1)
      }
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل المبيعات')) } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load(filters, 1)
    return () => {
      if (searchTimer.current) clearTimeout(searchTimer.current)
    }
  }, [])

  const handleFilter = (key: keyof SalesFilters, val: string) => {
    const next = { ...filters, [key]: val }
    setFilters(next)
    setCurrentPage(1)
    if (searchTimer.current) clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(next, 1), 400)
  }

  const clearFilters = () => {
    const cleared = { date: '', month: '', year: '', search: '' }
    setFilters(cleared)
    setCurrentPage(1)
    load(cleared, 1)
  }

  const openDetail = async (id: number) => {
    setDL(true)
    try {
      const res = await getSale(id)
      setSelected(res.data.data)
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل تفاصيل الفاتورة')) } finally {
      setDL(false)
    }
  }

  const handleReturnToCart = () => {
    if (!selected?.items?.length) {
      toast.error('لا توجد أصناف في الفاتورة')
      return
    }
    const items = selected.items
    mergeInvoiceLines(
      items,
      selected.id,
      selected.customer_id,
      Number(selected.amount_paid) || 0,
      selected.payment_method,
      Number(selected.shipping_cost) || 0,
    )
    toast.success('تمت إضافة أصناف الفاتورة إلى السلة — انتقل إلى نقطة البيع')
    setSelected(null)
    navigate('/')
  }

  const handleDeleteInvoice = async () => {
    if (!selected?.id) return
    if (!(await confirm('سيتم حذف الفاتورة نهائياً من السجل وإرجاع كميات المنتجات إلى المخزون. هل أنت متأكد؟'))) return
    setDeleting(true)
    try {
      await deleteSale(selected.id)
      toast.success('تم حذف الفاتورة')
      setSelected(null)
      load(filters, currentPage)
    } catch (err) {
      toast.error(extractApiError(err, 'فشل حذف الفاتورة'))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>سجل المبيعات</h1>

      {/* Filters */}
      <div className="card" style={{ padding: '1rem' }}>
        <div className="filter-bar">
          <div className="form-group" style={{ flex: '1 1 220px' }}>
            <label style={labelSt}>بحث</label>
            <input
              type="text"
              className="input"
              placeholder="رقم فاتورة أو اسم منتج…"
              value={filters.search}
              onChange={e => handleFilter('search', e.target.value)}
            />
          </div>
          <div className="form-group">
            <label style={labelSt}>تاريخ محدد</label>
            <input type="date" className="input" value={filters.date} onChange={e => handleFilter('date', e.target.value)} />
          </div>
          <button onClick={clearFilters} className="btn btn-ghost" style={{ alignSelf: 'flex-end', height: '42px', padding: '0 1.5rem' }}>مسح الفلاتر</button>
        </div>
      </div>

      {/* Table */}
      <div className="card">
        {loading ? (
          <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : sales.length === 0 ? (
          <div className="empty-state">لا توجد مبيعات</div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th># الفاتورة</th>
                  <th className="hide-mobile">الكاشير</th>
                  <th>الإجمالي</th>
                  <th className="hide-mobile">طريقة الدفع</th>
                  <th className="hide-mobile">التاريخ</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {sales.map(s => (
                  <tr key={s.id}>
                    <td>
                      <span style={{ color: 'var(--text-muted)' }}>#{formatNumber(s.id)}</span>
                      <div className="show-mobile" style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: 400, marginTop: '0.2rem' }}>
                        {formatDate(s.created_at)}
                        {s.cashier_name ? ` · ${s.cashier_name}` : ''}
                      </div>
                    </td>
                    <td className="hide-mobile">{s.cashier_name ?? '—'}</td>
                    <td style={{ fontWeight: 700, color: 'var(--primary-d)' }}>
                      {formatCurrency(s.total)}
                      <div className="show-mobile" style={{ marginTop: '0.2rem' }}>
                        <span className="badge badge-blue" style={{ fontSize: '0.65rem', padding: '0.1rem 0.3rem' }}>
                          {METHOD_LABELS[s.payment_method] ?? s.payment_method}
                        </span>
                      </div>
                    </td>
                    <td className="hide-mobile">
                      <span className="badge badge-blue">
                        {METHOD_LABELS[s.payment_method] ?? s.payment_method}
                      </span>
                    </td>
                    <td className="hide-mobile" style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatDate(s.created_at)}</td>
                    <td>
                      <button
                        className="btn btn-ghost btn-sm"
                        onClick={() => openDetail(s.id)}
                        style={{ gap: '0.3rem' }}
                      >
                        <Eye size={14}/> عرض
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination 
              current={currentPage} 
              total={totalPages} 
              onPage={(page: number) => load(filters, page)}
            />
          </div>
        )}
      </div>

      {/* Detail Modal */}
      {(selected || detailLoading) && (
        <SaleDetailModal
          selected={selected}
          detailLoading={detailLoading}
          deleting={deleting}
          isAdmin={isAdmin}
          settings={settings}
          qz={qz}
          onClose={() => setSelected(null)}
          onReturnToCart={handleReturnToCart}
          onDelete={handleDeleteInvoice}
        />
      )}

      {qz.showPrinterPicker && (
        <QZPrinterPicker
          printers={qz.printers}
          selectedPrinter={qz.selectedPrinter}
          onSelect={(name: string) => { qz.handlePrinterSelect(name); toast.success(`تم اختيار الطابعة: ${name}`) }}
          onClose={() => qz.setShowPrinterPicker(false)}
        />
      )}
    </div>
  )
}

const labelSt = {
  display: 'block',
  fontSize: '0.8rem',
  fontWeight: 600,
  color: 'var(--text-muted)',
  marginBottom: '0.3rem',
}
