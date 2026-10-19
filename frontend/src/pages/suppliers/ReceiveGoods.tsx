import { useState, useEffect, type Dispatch, type SetStateAction } from 'react'
import { Trash2, ShoppingCart, Check, Package } from 'lucide-react'
import BarcodeInput from '../../components/pos/BarcodeInput'
import useProductStore from '../../store/productStore'
import toast from 'react-hot-toast'
import { createBulkPurchase, getPurchaseInvoice, getSupplierOption, searchSuppliers } from '../../api/endpoints'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import { extractApiError } from '../../utils/apiError'
import ReceiveGoodsProductCard from './components/ReceiveGoodsProductCard'
import ReceiveGoodsCartLine from './components/ReceiveGoodsCartLine'
import ReceiveConfirmModal from './components/ReceiveConfirmModal'
import PurchaseReceiptModal from './components/PurchaseReceiptModal'
import styles from '../Suppliers.module.css'
import SearchableEntitySelect from '../../components/SearchableEntitySelect'

type ReceivingProduct = Product & { scanned_as_box?: boolean }

interface ReceiveCartLine {
  product: ReceivingProduct
  quantity: number
  cost: number
}

interface DeliveryData {
  driver_name?: string
  discount?: number
  shipping_cost?: number
  delivery_date?: string
  delivery_notes?: string
}

const normalizeMoney = (value: unknown): number => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.round(Math.max(0, parsed) * 100) / 100 : 0
}

interface ReceiveGoodsProps {
  cart: ReceiveCartLine[]
  setCart: Dispatch<SetStateAction<ReceiveCartLine[]>>
  supplierId: string
  setSupplierId: Dispatch<SetStateAction<string>>
  invoiceId: number | null
  setInvoiceId: Dispatch<SetStateAction<number | null>>
}

/* ──────────────────────────── Receive Goods (POS-like) ── */
export default function ReceiveGoods({ cart, setCart, supplierId, setSupplierId, invoiceId, setInvoiceId }: ReceiveGoodsProps) {
  const [selectedSupplier, setSelectedSupplier] = useState<Supplier | null>(null)
  const [allProducts, setAllProducts] = useState<Product[]>([])
  const [search, setSearch]           = useState('')
  const [loading, setLoading]         = useState(false)
  const [confirming, setConfirming]   = useState(false)
  const [mobileTab, setMobileTab]     = useState('products')
  const [paymentType, setPaymentType] = useState<'cash' | 'credit'>('cash')
  const [deposit, setDeposit]         = useState(0)

  // ── Modals states ──────────────────────────────────────────────────
  const [showConfirmModal, setShowConfirmModal] = useState(false)
  const [invoiceToPrint, setInvoiceToPrint]     = useState<PurchaseInvoice | null>(null)

  const q = search.trim().toLowerCase()
  const products = q
    ? allProducts.filter((p) => {
        const nm = (p.name || '').toLowerCase().includes(q)
        const bc = (p.barcode || '').toLowerCase().includes(q)
        const ex = (p.additional_barcodes || []).some((b) => String(b).toLowerCase().includes(q))
        return nm || bc || ex
      })
    : allProducts

  useEffect(() => {
    let fetchInFlight = false
    const fetchAll = (isInitial = false) => {
      if (fetchInFlight) return
      fetchInFlight = true
      if (isInitial) setLoading(true)
      useProductStore.getState().fetchProducts({}, true)
        .then((list) => {
          setAllProducts(list)
          useProductStore.getState().setProducts(list)
        })
        .catch(() => {
          if (isInitial) toast.error('فشل تحميل المنتجات')
        })
        .finally(() => {
          fetchInFlight = false
          if (isInitial) setLoading(false)
        })
    }
    
    fetchAll(true)
    const intervalId = setInterval(() => fetchAll(false), 30000)
    return () => clearInterval(intervalId)
  }, [])

  /* ── Cart helpers ── */
  const addToCart = (product: ReceivingProduct) => {
    let targetProduct: ReceivingProduct = product
    if (product.sizes && product.sizes.length > 0) {
      targetProduct = {
        ...product.sizes[0],
        unit_type: product.unit_type,
        sell_by_weight: product.sell_by_weight
      }
    }

    const unitsPerBox = Math.max(1, Number.parseInt(String(targetProduct.units_per_box)) || 1)
    const qtyToAdd = targetProduct.scanned_as_box ? unitsPerBox : 1

    setCart((prev) => {
      const existing = prev.find((c) => c.product.id === targetProduct.id)
      if (existing) {
        return prev.map((c) =>
          c.product.id === targetProduct.id ? { ...c, quantity: c.quantity + qtyToAdd } : c
        )
      }
      return [
        ...prev,
        {
          product: targetProduct,
          quantity: qtyToAdd,
          cost:
            Number(targetProduct.cost) > 0
              ? Number(targetProduct.cost)
              : Number(targetProduct.price) || 0,
        },
      ]
    })
    toast.success(targetProduct.name, { duration: 700 })
  }

  const switchCartLineProduct = (oldProductId: number, newProduct: Product, parentProduct?: Product) => {
    setCart((prev) => {
      const targetProduct = {
        ...newProduct,
        unit_type: parentProduct?.unit_type,
        sell_by_weight: parentProduct?.sell_by_weight
      }
      const existingIndex = prev.findIndex((c) => c.product.id === targetProduct.id && c.product.id !== oldProductId)
      const oldLine = prev.find((c) => c.product.id === oldProductId)
      if (!oldLine) return prev

      if (existingIndex >= 0) {
        return prev
          .map((c, idx) =>
            idx === existingIndex
              ? { ...c, quantity: c.quantity + oldLine.quantity }
              : c
          )
          .filter((c) => c.product.id !== oldProductId)
      } else {
        const cost = Number(targetProduct.cost) > 0
          ? Number(targetProduct.cost)
          : Number(targetProduct.price) || 0
        return prev.map((c) =>
          c.product.id === oldProductId
            ? { ...c, product: targetProduct, cost }
            : c
        )
      }
    })
  }

  const updateLineQuantity = (productId: number, qty: string | number) => {
    const q = Math.max(0.001, Number.parseFloat(String(qty)) || 0.001)
    setCart((prev) =>
      prev.map((c) => (c.product.id === productId ? { ...c, quantity: q } : c))
    )
  }

  const updateLineCost = (productId: number, raw: string | number) => {
    const v = Number.parseFloat(String(raw))
    const cost = Number.isFinite(v) && v >= 0 ? v : 0
    setCart((prev) =>
      prev.map((c) => (c.product.id === productId ? { ...c, cost } : c))
    )
  }

  const removeFromCart = (id: number) => setCart((previous) => previous.filter((line) => line.product.id !== id))

  const cartTotal = cart.reduce((s, c) => s + c.cost * c.quantity, 0)
  const cartCount = cart.reduce((s, c) => s + c.quantity, 0)

  const handleConfirm = async (deliveryData: DeliveryData = {}) => {
    if (!supplierId) { toast.error('يرجى اختيار مورد'); return }
    if (cart.length === 0) { toast.error('السلة فارغة'); return }
    setConfirming(true)
    try {
      const discount = normalizeMoney(deliveryData.discount)
      const shippingCost = normalizeMoney(deliveryData.shipping_cost)
      const invoiceTotal = Math.max(0, cartTotal - discount + shippingCost)
      const amountDue = paymentType === 'credit' ? Math.max(0, invoiceTotal - deposit) : 0
      const res = await createBulkPurchase({
        replace_invoice_id: invoiceId,
        supplier_id: Number.parseInt(supplierId, 10),
        items: cart.map(c => ({ product_id: c.product.id, quantity: c.quantity, cost: c.cost, update_cost: true })),
        payment_type: paymentType,
        deposit: paymentType === 'credit' ? deposit : 0,
        ...deliveryData
      })

      const createdInvoiceId = res.data.data?.invoice_id ?? res.data.data?.id

      toast.success(
        invoiceId ? 'تم تحديث الفاتورة والمخزون'
        : paymentType === 'credit'
          ? `تم تسجيل الشراء الآجل 📋 — المتبقي ${formatCurrency(amountDue)}`
          : 'تم تسجيل الشراء وتحديث المخزون'
      )
      
      setCart([])
      if(setInvoiceId) setInvoiceId(null)
      setPaymentType('cash')
      setDeposit(0)
      setMobileTab('products')
      setShowConfirmModal(false)

      // Fetch invoice for printing
      if (createdInvoiceId) {
        getPurchaseInvoice(createdInvoiceId)
          .then((invRes) => {
            setInvoiceToPrint(invRes.data.data)
          })
          .catch(() => {
            toast.error('فشل جلب تفاصيل الفاتورة للطباعة')
          })
      }

      // ── تحديث فوري للمنتجات (تجاوز الكاش) ──────────────────
      useProductStore.getState().fetchProducts({}, true)
        .then((list) => {
          if (list.length > 0) {
            setAllProducts(list)
            useProductStore.getState().setProducts(list)
            useProductStore.getState().invalidateCache()
          }
        })
        .catch(() => { /* silent */ })
    } catch (err) {
      toast.error(extractApiError(err, 'فشل تسجيل الشراء'))
    } finally {
      setConfirming(false)
    }
  }

  // F12 Shortcut to open confirm goods modal
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F12') {
        e.preventDefault()
        if (cart.length > 0 && supplierId && !confirming && !showConfirmModal) {
          setShowConfirmModal(true)
        } else if (!supplierId && cart.length > 0) {
          toast.error('يرجى اختيار مورد لإتمام العملية')
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [cart, supplierId, confirming, showConfirmModal])

  /* ── Panels ── */
  const ProductsPanel = (
    <div className={`card ${styles.productsPanel}`}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexShrink: 0 }}>
        <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
        <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
      </div>
      <div className="product-grid">
        {loading ? (
          <div style={{ gridColumn: '1/-1', padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : products.map(p => (
          <ReceiveGoodsProductCard key={p.id} product={p} onAdd={() => { addToCart(p); setMobileTab('cart') }} />
        ))}
      </div>
    </div>
  )

  const CartPanel = (
    <div className={`card ${styles.cartPanel}`}>
      {/* Supplier select */}
      <div style={{ flexShrink: 0, borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem', marginBottom: '0.5rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontWeight: 700 }}>
            <ShoppingCart size={18} />
            السلة
            {cartCount > 0 && <span className="badge badge-green">{formatNumber(cartCount)}</span>}
          </div>
          {cart.length > 0 && (
            <button
              className="btn btn-ghost btn-sm"
              onClick={() => { 
                setCart([]); 
                if(setInvoiceId) setInvoiceId(null); 
                toast('تم مسح السلة');
                setTimeout(() => document.getElementById('main-barcode-input')?.focus(), 10);
              }}
              style={{ color: 'var(--danger)' }}
            >
              <Trash2 size={14} /> مسح الكل
            </button>
          )}
        </div>
        <label style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.3rem' }}>المورد</label>
        <SearchableEntitySelect<Supplier>
          value={supplierId}
          onChange={(value, supplier) => {
            setSupplierId(value)
            setSelectedSupplier(supplier)
          }}
          searchOptions={searchSuppliers}
          loadOption={getSupplierOption}
          onOptionResolved={setSelectedSupplier}
          searchPlaceholder="ابحث عن مورد بالاسم أو الهاتف..."
          emptyLabel="اختر مورد…"
          loadingLabel="جارٍ التحميل..."
        />
      </div>

      {/* Items — scrollable, bounded by parent height */}
      <div style={{ flex: 1, minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
        {cart.length === 0 ? (
          <div className="empty-state" style={{ padding: '2rem' }}>
            <ShoppingCart size={28} style={{ opacity: 0.3 }} />
            <span style={{ fontSize: '0.85rem' }}>اضغط على منتج لإضافته</span>
          </div>
        ) : (
          cart.map((c) => (
            <ReceiveGoodsCartLine
              key={c.product.id}
              line={c}
              onUpdateQty={updateLineQuantity}
              onUpdateCost={updateLineCost}
              onRemove={() => removeFromCart(c.product.id)}
              onSwitchProduct={switchCartLineProduct}
              allProducts={allProducts}
            />
          ))
        )}
      </div>

      {/* Total + credit purchase + confirm */}
      <div style={{ flexShrink: 0, borderTop: '1px solid var(--border)', paddingTop: '0.75rem', marginTop: '0.5rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {cart.length > 0 && (
          <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 700, fontSize: '1rem', paddingBottom: '0.25rem' }}>
            <span>الإجمالي</span>
            <span style={{ color: 'var(--secondary)' }}>{formatCurrency(cartTotal)}</span>
          </div>
        )}



        <button
          onClick={() => {
            if (!supplierId) {
              toast.error('يرجى اختيار مورد لإتمام العملية')
              return
            }
            setShowConfirmModal(true)
          }}
          disabled={confirming || cart.length === 0}
          className="btn btn-primary btn-lg"
          style={{
            justifyContent: 'center', width: '100%',
            ...(paymentType === 'credit' ? { background: 'var(--danger)', borderColor: 'var(--danger)' } : {})
          }}
        >
          {confirming ? <span className="spinner" /> : <Check size={18} />}
          {invoiceId ? 'مراجعة وتحديث الفاتورة' : paymentType === 'credit' ? 'تأكيد استلام آجل' : 'تأكيد الاستلام'}{cart.length > 0 ? ` — ${formatCurrency(cartTotal)}` : ''}
          <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>F12</kbd>
        </button>
      </div>
    </div>
  )

  return (
    <>
      {/* Barcode input — flexShrink:0 so it never grows */}
      <div className="card" style={{ padding: '0.75rem', flexShrink: 0 }}>
        <BarcodeInput
          onFilterChange={setSearch}
          allowOutOfStock
          onAddProduct={(product: Product) => { addToCart(product); setMobileTab('cart') }}
        />
      </div>

      {/* Desktop layout — flex:1 + minHeight:0 so it fills exactly what's left */}
      <div className={styles.desktop}>
        {ProductsPanel}
        {CartPanel}
      </div>

      {/* Mobile layout */}
      <div className={styles.mobile}>
        <div className={styles.mobileContent}>
          {mobileTab === 'products' ? ProductsPanel : CartPanel}
        </div>
        <div className="pos-tab-bar">
          <button className={`pos-tab ${mobileTab === 'products' ? 'active' : ''}`} onClick={() => setMobileTab('products')}>
            <Package size={20} />
            <span>المنتجات</span>
          </button>
          <button className={`pos-tab ${mobileTab === 'cart' ? 'active' : ''}`} onClick={() => setMobileTab('cart')}>
            <ShoppingCart size={20} />
            <span>السلة</span>
            {cartCount > 0 && <span className="tab-badge">{formatNumber(cartCount)}</span>}
          </button>
        </div>
      </div>

      {showConfirmModal && (
        <ReceiveConfirmModal
          supplier={selectedSupplier ?? undefined}
          cart={cart}
          cartTotal={cartTotal}
          cartCount={cartCount}
          paymentType={paymentType}
          setPaymentType={setPaymentType}
          deposit={deposit}
          setDeposit={setDeposit}
          onClose={() => setShowConfirmModal(false)}
          onConfirm={handleConfirm}
          loading={confirming}
        />
      )}

      {invoiceToPrint && (
        <PurchaseReceiptModal
          invoice={invoiceToPrint}
          onClose={() => setInvoiceToPrint(null)}
        />
      )}
    </>
  )
}
