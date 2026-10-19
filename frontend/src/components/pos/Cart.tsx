import { useState } from 'react'
import { Trash2, Plus, Minus, ShoppingCart, Package } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useProductStore from '../../store/productStore'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import NumericInput from '../forms/NumericInput'

export default function Cart() {
  const { items, removeItem, updateQuantity, updatePrice, rebillingInvoiceId } = useCartStore()

  if (items.length === 0) {
    return (
      <div className="empty-state" style={{ height: '100%' }}>
        <ShoppingCart size={48} color="var(--border)" />
        <p>السلة فارغة</p>
        <small>امسح باركود منتج للبدء</small>
      </div>
    )
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
      {rebillingInvoiceId != null && rebillingInvoiceId > 0 && (
        <div
          style={{
            fontSize: '0.78rem',
            fontWeight: 600,
            color: '#92400e',
            background: '#fef9c3',
            border: '1px solid #fbbf24',
            borderRadius: 'var(--radius)',
            padding: '0.45rem 0.6rem',
            textAlign: 'center',
          }}
        >
          تعديل الفاتورة #{formatNumber(rebillingInvoiceId)} — عند الدفع يُحفظ على نفس رقم الفاتورة
        </div>
      )}
      {items.map((item) => (
        <CartItem
          key={item.id}
          item={item}
          onRemove={() => removeItem(item.id)}
          onUpdateQty={(qty) => updateQuantity(item.id, qty)}
          onUpdatePrice={(price) => updatePrice(item.id, Number(price))}
        />
      ))}
    </div>
  )
}

// ── CartItem ────────────────────────────────────────────────────────────────

interface CartItemProps {
  item: any;
  onRemove: () => void;
  onUpdateQty: (qty: number) => void;
  onUpdatePrice: (price: string) => void;
}

function CartItem({ item, onRemove, onUpdateQty, onUpdatePrice }: CartItemProps) {
  const switchItemProduct = useCartStore(s => s.switchItemProduct)
  const products          = useProductStore(s => s.products)

  const unitsPerBox = Math.max(1, parseInt(item.units_per_box) || 1)
  const unitType    = item.unit_type ?? (parseInt(item.sell_by_weight) === 1 ? 'weight' : 'piece')
  const isByWeight  = unitType === 'weight'
  const isByLiter   = unitType === 'liter'
  const hasBox      = unitsPerBox > 1 && unitType === 'piece'

  // unitMode: 'piece' | 'box' | 'kg' | 'liter'
  const defaultMode = isByWeight ? 'kg' : (isByLiter ? 'liter' : (item.scanned_as_box && hasBox ? 'box' : 'piece'))
  const [unitMode, setUnitMode] = useState(defaultMode)

  // عدد الصناديق المعروض (يُحسب من الكمية الكلية)
  const boxCount   = unitMode === 'box' ? Math.max(1, Math.round(item.quantity / unitsPerBox)) : null
  const displayQty = unitMode === 'box' ? boxCount : item.quantity

  const handleUnitModeChange = (mode: string) => {
    if (mode === unitMode) return
    setUnitMode(mode)
    if (mode === 'kg' || mode === 'liter') {
      onUpdateQty(1)
    } else if (mode === 'piece') {
      onUpdateQty(1)
    } else {
      onUpdateQty(unitsPerBox)
    }
  }

  const handleDecrement = () => {
    if (unitMode === 'box') {
      const newBoxes = Math.max(1, (boxCount ?? 0) - 1)
      onUpdateQty(newBoxes * unitsPerBox)
    } else if (unitMode === 'kg' || unitMode === 'liter') {
      onUpdateQty(Math.max(0.001, parseFloat((item.quantity - 0.25).toFixed(3))))
    } else {
      onUpdateQty(item.quantity - 1)
    }
  }

  const handleIncrement = () => {
    if (unitMode === 'box') {
      onUpdateQty(((boxCount ?? 0) + 1) * unitsPerBox)
    } else if (unitMode === 'kg' || unitMode === 'liter') {
      onUpdateQty(parseFloat((item.quantity + 0.25).toFixed(3)))
    } else {
      onUpdateQty(item.quantity + 1)
    }
  }

  const handleInputChange = (raw: string) => {
    if (unitMode === 'kg' || unitMode === 'liter') {
      const val = parseFloat(raw) || 0.001
      onUpdateQty(Math.max(0.001, val))
    } else if (unitMode === 'box') {
      const val = parseInt(raw) || 1
      onUpdateQty(Math.max(1, val) * unitsPerBox)
    } else {
      const val = parseInt(raw) || 1
      onUpdateQty(Math.max(1, val))
    }
  }

  return (
    <div
      style={{
        padding: '0.6rem 0.75rem',
        background: 'var(--surface)',
        borderRadius: '0.4rem',
        border: '1px solid var(--border)',
      }}
    >
      {/* الصف الأول: اسم + سعر + زر حذف */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.5rem' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div
            style={{
              fontWeight: 600,
              fontSize: '0.88rem',
              lineHeight: 1.4,
              wordBreak: 'break-word',
              overflowWrap: 'anywhere',
            }}
          >
            {item.name}
            {isByWeight && <span style={{ fontSize: '0.65rem', color: 'var(--primary)', marginRight: '0.3rem' }}>(وزن)</span>}
            {isByLiter && <span style={{ fontSize: '0.65rem', color: 'var(--primary)', marginRight: '0.3rem' }}>(لتر)</span>}
          </div>
          <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>{item.barcode}</div>
          {(() => {
            const parentId = item.parent_product_id || products.find(p => p.id === item.id && p.sizes && p.sizes.length > 0)?.id
            const parentProduct = parentId ? products.find(p => p.id === parentId) : null
            const sizesList = parentProduct?.sizes || []
            if (sizesList.length === 0) return null
            return (
              <div style={{ marginTop: '0.25rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>المقاس:</span>
                <select
                  value={item.id}
                  onChange={(e) => {
                    const newId = Number(e.target.value)
                    const newProduct = sizesList.find((s: any) => s.id === newId)
                    if (newProduct) {
                      switchItemProduct(item.id, {
                        ...newProduct,
                        unit_type: parentProduct?.unit_type,
                        sell_by_weight: parentProduct?.sell_by_weight,
                      })
                    }
                  }}
                  style={{
                    fontSize: '0.78rem',
                    padding: '0.15rem 0.35rem',
                    border: '1px solid var(--border)',
                    borderRadius: '0.25rem',
                    background: 'var(--surface)',
                    color: 'var(--text)',
                    cursor: 'pointer',
                    fontFamily: 'inherit',
                  }}
                >
                  {sizesList.map((sz: any) => (
                    <option key={sz.id} value={sz.id}>
                      {sz.size_name} — {formatCurrency(sz.price)}
                    </option>
                  ))}
                </select>
              </div>
            )
          })()}
          <div style={{ fontSize: '0.85rem', color: 'var(--primary)', fontWeight: 700 }}>
            {formatCurrency(item.subtotal)}
          </div>
        </div>

        {/* Unit price (Editable) */}
        <div style={{ minWidth: '60px', textAlign: 'left', flexShrink: 0, marginTop: '0.1rem' }}>
          <NumericInput
            min="0"
            step="0.01"
            value={item.price}
            onChange={(e) => onUpdatePrice(e.target.value)}
            style={{
              width: '4.5rem',
              fontSize: '0.8rem',
              padding: '0.2rem 0.1rem',
              border: '1px solid var(--border)',
              borderRadius: '0.3rem',
              textAlign: 'center',
              color: 'var(--text-muted)',
              background: 'var(--surface)'
            }}
            title={isByWeight ? 'سعر الكيلو' : isByLiter ? 'سعر اللتر' : 'تعديل السعر لهذه الفاتورة فقط'}
          />
        </div>

        {/* Delete */}
        <button
          className="btn btn-icon"
          style={{ padding: '0.3rem', color: 'var(--danger)', background: 'transparent', border: 'none', flexShrink: 0, marginTop: '0.1rem' }}
          onClick={onRemove}
        >
          <Trash2 size={16} />
        </button>
      </div>

      {/* الصف الثاني: ضوابط الكمية + القائمة المنسدلة */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginTop: '0.45rem', flexWrap: 'wrap' }}>

        {/* قائمة منسدلة: قطعة / صندوق (للمنتجات العادية) أو إشارة للكيلو/اللتر */}
        {hasBox ? (
          <select
            value={unitMode}
            onChange={(e) => handleUnitModeChange(e.target.value)}
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.22rem 0.35rem',
              border: `1px solid ${unitMode === 'box' ? 'var(--secondary)' : 'var(--border)'}`,
              borderRadius: '0.3rem',
              background: unitMode === 'box' ? 'rgba(59,130,246,0.08)' : 'var(--surface)',
              color: unitMode === 'box' ? 'var(--secondary)' : 'var(--text)',
              cursor: 'pointer',
              flexShrink: 0,
              fontFamily: 'inherit',
            }}
          >
            <option value="piece">قطعة</option>
            <option value="box">صندوق ({unitsPerBox})</option>
          </select>
        ) : isByWeight ? (
          <span
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.22rem 0.35rem',
              border: '1px solid var(--primary)',
              borderRadius: '0.3rem',
              background: 'rgba(34,197,94,0.08)',
              color: 'var(--primary)',
              flexShrink: 0,
            }}
          >
            كيلو
          </span>
        ) : isByLiter ? (
          <span
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.22rem 0.35rem',
              border: '1px solid var(--primary)',
              borderRadius: '0.3rem',
              background: 'rgba(34,197,94,0.08)',
              color: 'var(--primary)',
              flexShrink: 0,
            }}
          >
            لتر
          </span>
        ) : null}

        {/* أزرار الكمية */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flex: 1 }}>
          <button
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleDecrement}
          >
            <Minus size={14} />
          </button>
          <NumericInput
            min={unitMode === 'kg' || unitMode === 'liter' ? 0.001 : 1}
            step={unitMode === 'kg' || unitMode === 'liter' ? '0.001' : '1'}
            value={displayQty}
            onChange={(e) => handleInputChange(e.target.value)}
            style={{
              width: unitMode === 'kg' || unitMode === 'liter' ? '4rem' : '3rem', textAlign: 'center', border: '1px solid var(--border)',
              borderRadius: '0.3rem', padding: '0.25rem 0.2rem', fontSize: '0.9rem',
            }}
          />
          <button
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleIncrement}
          >
            <Plus size={14} />
          </button>
        </div>

        {/* أزرار أوزان سريعة — تظهر للمنتجات الوزنية واللترية */}
        {(unitMode === 'kg' || unitMode === 'liter') && (
          <div style={{ display: 'flex', gap: '0.2rem', flexShrink: 0 }}>
            {[0.25, 0.5, 0.75, 1].map(w => (
              <button
                key={w}
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => onUpdateQty(w)}
                style={{
                  padding: '0.15rem 0.3rem', fontSize: '0.68rem', fontWeight: 700,
                  borderRadius: '0.25rem', minWidth: '28px',
                  border: item.quantity === w ? '1px solid var(--primary)' : undefined,
                  color: item.quantity === w ? 'var(--primary)' : undefined,
                }}
                title={`${w} ${unitMode === 'kg' ? 'كجم' : 'لتر'}`}
              >
                {w === 0.25 ? '0.25' : w === 0.5 ? '0.5' : w === 0.75 ? '0.75' : '1'}
              </button>
            ))}
          </div>
        )}

        {/* وسم الوحدة النشطة */}
        <span style={{
          fontSize: '0.72rem',
          color: unitMode === 'box' ? 'var(--secondary)' : (unitMode === 'kg' || unitMode === 'liter') ? 'var(--primary)' : 'var(--text-muted)',
          fontWeight: 600,
          flexShrink: 0,
          display: 'flex',
          alignItems: 'center',
          gap: '0.2rem',
        }}>
          {unitMode === 'box'
            ? <>{formatNumber(item.quantity)} قطعة</>
            : unitMode === 'kg'
            ? `${parseFloat(item.quantity).toFixed(3)} كجم`
            : unitMode === 'liter'
            ? `${parseFloat(item.quantity).toFixed(2)} لتر`
            : null
          }
        </span>
      </div>
    </div>
  )
}
