import { useState, useMemo, useEffect } from 'react'
import type { ComponentType } from 'react'
import { Pencil, Trash2, Search, X, SlidersHorizontal, AlertTriangle, Warehouse, Camera, Printer, Scale, Droplet, Layers, Box, Coins, Ban } from 'lucide-react'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import Pagination from '../../components/Pagination'
import toast from 'react-hot-toast'
import PrintLabelsModal from '../../components/products/PrintLabelsModal'
import { extractApiError } from '../../utils/apiError'
import IconBadge from '../../components/common/IconBadge'

const STOCK_FILTERS = [
  { id: 'all', label: 'الكل' },
  { id: 'available', label: 'متوفر' },
  { id: 'low', label: 'مخزون منخفض' },
  { id: 'out', label: 'نفد المخزون' },
]

const SORT_OPTIONS = [
  { id: 'name_asc', label: 'الاسم (أ ← ي)' },
  { id: 'name_desc', label: 'الاسم (ي ← أ)' },
  { id: 'newest', label: 'الأحدث إضافة' },
  { id: 'oldest', label: 'الأقدم إضافة' },
  { id: 'updated_desc', label: 'آخر تعديل' },
  { id: 'qty_desc', label: 'الكمية (الأعلى أولاً)' },
  { id: 'qty_asc', label: 'الكمية (الأدنى أولاً)' },
  { id: 'price_desc', label: 'السعر (الأعلى أولاً)' },
  { id: 'price_asc', label: 'السعر (الأدنى أولاً)' },
]

function StatCard({ icon, label, value, color }: { icon: React.ReactNode, label: React.ReactNode, value: React.ReactNode, color?: string }) {
  return (
    <div className="stat-card">
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
        {icon}
        <span className="stat-label">{label}</span>
      </div>
      <div className="stat-value" style={{ color: color || 'var(--text)' }}>{value}</div>
    </div>
  )
}

interface ProductsTabProps {
  allProducts: Product[]
  loadingProducts: boolean
  categories: Category[]
  lowStock: Product[]
  onEditProduct: (product: Product) => void
  onDeleteProduct: (id: number, name: string) => void
}

export default function ProductsTab({
  allProducts,
  loadingProducts,
  categories,
  lowStock,
  onEditProduct,
  onDeleteProduct,
}: ProductsTabProps) {
  const [search, setSearch]             = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [stockFilter, setStockFilter]   = useState('all')
  const [sortKey, setSortKey]           = useState('name_asc')
  const [searchCameraOpen, setSearchCameraOpen] = useState(false)
  const [SearchScannerLazy, setSearchScannerLazy] = useState<ComponentType<{
    onResult: (text: string) => void
    onClose: () => void
  }> | null>(null)
  const [selectedProductIds, setSelectedProductIds] = useState<number[]>([])
  const [printModalOpen, setPrintModalOpen] = useState(false)
  
  const [currentPage, setCurrentPage] = useState(1)

  const q = search.trim().toLowerCase()

  // Reset page when filters change
  useEffect(() => {
    setCurrentPage(1)
  }, [q, categoryFilter, stockFilter, sortKey])

  const displayProducts = useMemo(() => {
    let list = allProducts

    if (categoryFilter) {
      list = list.filter((p) => String(p.category_id ?? '') === categoryFilter)
    }

    if (q) {
      list = list.filter((p) => {
        const extra = (p.additional_barcodes || []).some((b) => String(b).toLowerCase().includes(q))
        return (
          p.name.toLowerCase().includes(q) ||
          (p.barcode && p.barcode.toLowerCase().includes(q)) ||
          extra
        )
      })
    }

    switch (stockFilter) {
      case 'low':
        list = list.filter((p) => Number(p.quantity) <= Number(p.low_stock_threshold ?? 5))
        break
      case 'out':
        list = list.filter((p) => Number(p.quantity) <= 0)
        break
      case 'available':
        list = list.filter((p) => Number(p.quantity) > 0)
        break
      default:
        break
    }

    const sorted = [...list]
    const nameCmp = (a: Product, b: Product) => String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' })

    switch (sortKey) {
      case 'name_desc':
        sorted.sort((a, b) => nameCmp(b, a))
        break
      case 'newest':
        sorted.sort((a, b) => Number(b.id) - Number(a.id))
        break
      case 'oldest':
        sorted.sort((a, b) => Number(a.id) - Number(b.id))
        break
      case 'updated_desc':
        sorted.sort((a, b) => {
          const ta = new Date(a.updated_at || 0).getTime()
          const tb = new Date(b.updated_at || 0).getTime()
          if (tb !== ta) return tb - ta
          return Number(b.id) - Number(a.id)
        })
        break
      case 'qty_desc':
        sorted.sort((a, b) => Number(b.quantity) - Number(a.quantity) || nameCmp(a, b))
        break
      case 'qty_asc':
        sorted.sort((a, b) => Number(a.quantity) - Number(b.quantity) || nameCmp(a, b))
        break
      case 'price_desc':
        sorted.sort((a, b) => Number(b.price) - Number(a.price) || nameCmp(a, b))
        break
      case 'price_asc':
        sorted.sort((a, b) => Number(a.price) - Number(b.price) || nameCmp(a, b))
        break
      case 'name_asc':
      default:
        sorted.sort(nameCmp)
        break
    }

    return sorted
  }, [allProducts, q, categoryFilter, stockFilter, sortKey])

  // ── Computed stats ──
  const totalUnits = allProducts.reduce((s, p) => s + Number(p.quantity), 0)
  const stockValue = allProducts.reduce((s, p) => s + Number(p.quantity) * Number(p.cost), 0)
  const outOfStock = allProducts.filter((p) => p.quantity <= 0).length

  const filtersActive =
    stockFilter !== 'all' ||
    sortKey !== 'name_asc' ||
    Boolean(categoryFilter) ||
    Boolean(search.trim())

  const totalPages = Math.ceil(displayProducts.length / 15) || 1
  const paginatedProducts = displayProducts.slice((currentPage - 1) * 15, currentPage * 15)

  const clearProductFilters = () => {
    setStockFilter('all')
    setSortKey('name_asc')
    setCategoryFilter('')
    setSearch('')
  }

  const toggleProductSelection = (id: number) => {
    setSelectedProductIds(prev =>
      prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
    )
  }

  const toggleAllSelection = (e: any) => {
    if (e.target.checked) {
      const newIds = new Set([...selectedProductIds, ...paginatedProducts.map((p: Product) => p.id)])
      setSelectedProductIds(Array.from(newIds))
    } else {
      const visibleIds = paginatedProducts.map((p: Product) => p.id)
      setSelectedProductIds(selectedProductIds.filter(id => !visibleIds.includes(id)))
    }
  }

  const selectedProductsData = useMemo(() => {
    return allProducts
      .filter((p: Product) => selectedProductIds.includes(p.id))
      .map((p: Product) => ({ name: p.name, barcode: p.barcode, price: Number(p.price) }))
  }, [allProducts, selectedProductIds])

  return (
    <>
      {/* Inventory widgets */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '0.75rem' }}>
        <StatCard icon={<IconBadge icon={Warehouse} color="secondary" shape="circle" size={16} badgeSize={32} />}  label="إجمالي المنتجات"  value={formatNumber(allProducts.length)} />
        <StatCard icon={<IconBadge icon={Box} color="primary" shape="circle" size={16} badgeSize={32} />}      label="إجمالي الوحدات"   value={formatNumber(totalUnits)} />
        <StatCard icon={<IconBadge icon={Coins} color="warning" shape="circle" size={16} badgeSize={32} />}      label="قيمة المخزون"    value={formatCurrency(stockValue)} />
        <StatCard
          icon={<IconBadge icon={AlertTriangle} color={lowStock.length > 0 ? 'warning' : 'muted'} shape="circle" size={16} badgeSize={32} />}
          label="مخزون منخفض"
          value={formatNumber(lowStock.length)}
          color={lowStock.length > 0 ? 'var(--warning)' : undefined}
        />
        <StatCard
          icon={<IconBadge icon={Ban} color={outOfStock > 0 ? 'danger' : 'muted'} shape="circle" size={16} badgeSize={32} />}
          label="نفد المخزون"
          value={formatNumber(outOfStock)}
          color={outOfStock > 0 ? 'var(--danger)' : undefined}
        />
      </div>

      {/* Search + filters */}
      <div className="card" style={{ padding: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
            <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
            <input
              className="input" style={{ paddingRight: '2.5rem' }}
              placeholder="بحث بالاسم أو الباركود..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          {selectedProductIds.length > 0 && (
            <button
              type="button"
              className="btn btn-primary"
              style={{ flexShrink: 0 }}
              onClick={() => setPrintModalOpen(true)}
            >
              <Printer size={16} /> طباعة ({selectedProductIds.length})
            </button>
          )}
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            style={{ flexShrink: 0, padding: '0.5rem' }}
            title="مسح الباركود بالكاميرا"
            aria-label="مسح الباركود بالكاميرا"
            onClick={async () => {
              try {
                if (!SearchScannerLazy) {
                  const m = await import('../../components/BarcodeCameraScanner')
                  setSearchScannerLazy(() => m.default)
                }
                setSearchCameraOpen(true)
              } catch (err) { toast.error(extractApiError(err, 'تعذر تحميل ماسح الباركود')) }
            }}
          >
            <Camera size={18} />
          </button>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.82rem', fontWeight: 600, color: 'var(--text-muted)' }}>
            <SlidersHorizontal size={16} />
            المخزون
          </span>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem', flex: 1 }}>
            {STOCK_FILTERS.map((f) => (
              <button
                key={f.id}
                type="button"
                onClick={() => setStockFilter(f.id)}
                className={`btn btn-sm ${stockFilter === f.id ? 'btn-primary' : 'btn-ghost'}`}
                style={{ fontSize: '0.78rem' }}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'flex-end' }}>
          <div style={{ flex: '1 1 160px', minWidth: 0 }}>
            <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.3rem' }}>الفئة</label>
            <select
              className="input"
              style={{ width: '100%' }}
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
            >
              <option value="">كل الفئات</option>
              {categories.map((c) => (
                <option key={c.id} value={String(c.id)}>{c.name}</option>
              ))}
            </select>
          </div>
          <div style={{ flex: '1 1 200px', minWidth: 0 }}>
            <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.3rem' }}>الترتيب</label>
            <select
              className="input"
              style={{ width: '100%' }}
              value={sortKey}
              onChange={(e) => setSortKey(e.target.value)}
            >
              {SORT_OPTIONS.map((o) => (
                <option key={o.id} value={o.id}>{o.label}</option>
              ))}
            </select>
          </div>
          {filtersActive && (
            <button type="button" className="btn btn-ghost btn-sm" onClick={clearProductFilters} style={{ alignSelf: 'center' }}>
              <X size={14} /> مسح التصفية
            </button>
          )}
        </div>

        <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>
          معروض {formatNumber(displayProducts.length)} من {formatNumber(allProducts.length)} منتج
          {filtersActive && ' — تم تطبيق تصفية أو ترتيب'}
        </div>
      </div>

      {/* Table */}
      <div className="card">
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th style={{ width: '40px', textAlign: 'center' }}>
                  <input
                    type="checkbox"
                    checked={paginatedProducts.length > 0 && paginatedProducts.every((p: Product) => selectedProductIds.includes(p.id))}
                    onChange={toggleAllSelection}
                  />
                </th>
                <th>المنتج</th>
                <th className="hide-mobile">الباركود</th>
                <th>السعر</th>
                <th className="hide-mobile">التكلفة</th>
                <th>الكمية</th>
                <th className="hide-mobile">الفئة</th>
                <th>الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              {loadingProducts ? (
                <tr><td colSpan={8} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
              ) : paginatedProducts.length === 0 ? (
                <tr><td colSpan={8} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد منتجات</td></tr>
              ) : paginatedProducts.map((p) => (
                <tr key={p.id} className={selectedProductIds.includes(p.id) ? 'selected-row' : ''}>
                  <td style={{ textAlign: 'center' }}>
                    <input
                      type="checkbox"
                      checked={selectedProductIds.includes(p.id)}
                      onChange={() => toggleProductSelection(p.id)}
                    />
                  </td>
                  <td style={{ fontWeight: 600 }}>
                    {p.name}
                    {(p.unit_type === 'weight' || (p.sell_by_weight === 1 && !p.unit_type)) && (
                      <span className="badge badge-green" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem', marginRight: '0.3rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                        <IconBadge icon={Scale} color="primary" shape="rounded" size={10} badgeSize={16} style={{ marginRight: '-0.1rem' }} />
                        وزن
                      </span>
                    )}
                    {p.unit_type === 'liter' && (
                      <span className="badge badge-green" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem', marginRight: '0.3rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                        <IconBadge icon={Droplet} color="primary" shape="rounded" size={10} badgeSize={16} style={{ marginRight: '-0.1rem' }} />
                        لتر
                      </span>
                    )}
                    {(p.sizes || []).length > 0 && (
                      <span className="badge badge-blue" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem', marginRight: '0.3rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }}>
                        <IconBadge icon={Layers} color="secondary" shape="rounded" size={10} badgeSize={16} style={{ marginRight: '-0.1rem' }} />
                        {formatNumber((p.sizes || []).length)} مقاسات
                      </span>
                    )}
                    <div className="show-mobile" style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: 400 }}>
                      {p.barcode}
                      {(p.additional_barcodes || []).length > 0 && ` (+${formatNumber((p.additional_barcodes || []).length)})`}
                      {p.category_name ? ` · ${p.category_name}` : ''}
                    </div>
                  </td>
                  <td className="hide-mobile">
                    <code style={{ fontSize: '0.8rem' }}>{p.barcode}</code>
                    {(p.additional_barcodes || []).length > 0 && (
                      <div style={{ fontSize: '0.72rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                        +{formatNumber((p.additional_barcodes || []).length)} باركود إضافي
                      </div>
                    )}
                  </td>
                  <td>{formatCurrency(p.price)}</td>
                  <td className="hide-mobile">{formatCurrency(p.cost)}</td>
                  <td>
                    <span className={`badge ${p.quantity <= 0 ? 'badge-red' : p.quantity <= (p.low_stock_threshold ?? 5) ? 'badge-yellow' : 'badge-green'}`}>
                      {p.unit_type === 'liter' ? `${Number(p.quantity).toFixed(2)} لتر` : (p.unit_type === 'weight' || (p.sell_by_weight === 1 && !p.unit_type)) ? `${Number(p.quantity).toFixed(1)} كجم` : formatNumber(p.quantity)}
                    </span>
                    {(p.units_per_box ?? 1) > 1 && (!p.unit_type || p.unit_type === 'piece') && (
                      <div style={{ marginTop: '0.3rem' }}>
                        <span className="badge badge-blue" style={{ fontSize: '0.65rem', padding: '0.15rem 0.4rem', display: 'inline-flex', alignItems: 'center', gap: '0.2rem' }} title="عدد القطع في الصندوق">
                          <IconBadge icon={Box} color="secondary" shape="rounded" size={10} badgeSize={16} style={{ marginRight: '-0.15rem' }} />
                          {formatNumber(p.units_per_box)}
                        </span>
                      </div>
                    )}
                  </td>
                  <td className="hide-mobile">
                    {p.category_name
                      ? <span className="badge badge-blue">{p.category_name}</span>
                      : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: '0.4rem' }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => onEditProduct(p)}><Pencil size={13} /></button>
                      <button className="btn btn-danger btn-sm" onClick={() => onDeleteProduct(p.id, p.name)}><Trash2 size={13} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Pagination 
        current={currentPage} 
        total={totalPages} 
        onPage={setCurrentPage} 
      />

      {/* Print Labels Modal */}
      {printModalOpen && (
        <PrintLabelsModal products={selectedProductsData} onClose={() => setPrintModalOpen(false)} />
      )}

      {/* Search Barcode Camera Scanner */}
      {SearchScannerLazy && searchCameraOpen && (
        <SearchScannerLazy
          onResult={(text) => {
            setSearch(text)
            setSearchCameraOpen(false)
            toast.success('تمت قراءة الباركود')
          }}
          onClose={() => setSearchCameraOpen(false)}
        />
      )}
    </>
  )
}
