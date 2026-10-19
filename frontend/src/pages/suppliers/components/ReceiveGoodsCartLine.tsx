import { useState } from 'react'
import { Plus, Minus, Trash2, Package } from 'lucide-react'
import { formatCurrency, formatNumber } from '../../../utils/formatters'
import NumericInput from '../../../components/forms/NumericInput'

export default function ReceiveGoodsCartLine({ line, onUpdateQty, onUpdateCost, onRemove, onSwitchProduct, allProducts }: any) {
  const product     = line.product
  const unitsPerBox = Math.max(1, parseInt(product.units_per_box, 10) || 1)
  const unitType    = product.unit_type ?? (parseInt(product.sell_by_weight) === 1 ? 'weight' : 'piece')
  const isByWeight  = unitType === 'weight'
  const isByLiter   = unitType === 'liter'
  const hasBox      = unitsPerBox > 1 && unitType === 'piece'
  const defaultMode = isByWeight ? 'kg' : (isByLiter ? 'liter' : (product.scanned_as_box && hasBox ? 'box' : 'piece'))
  const [unitMode, setUnitMode] = useState(defaultMode)

  const boxCount   = unitMode === 'box' ? Math.max(1, Math.round(line.quantity / unitsPerBox)) : null
  const displayQty = unitMode === 'box' ? boxCount : line.quantity

  const handleUnitModeChange = (mode: string) => {
    if (mode === unitMode) return
    setUnitMode(mode)
    if (mode === 'kg' || mode === 'liter') {
      onUpdateQty(product.id, 1)
    } else if (mode === 'piece') {
      onUpdateQty(product.id, 1)
    } else {
      onUpdateQty(product.id, unitsPerBox)
    }
  }

  const handleDecrement = () => {
    if (unitMode === 'box') {
      const newBoxes = Math.max(1, (boxCount ?? 0) - 1)
      onUpdateQty(product.id, newBoxes * unitsPerBox)
    } else if (unitMode === 'kg' || unitMode === 'liter') {
      onUpdateQty(product.id, Math.max(0.001, parseFloat((line.quantity - 0.25).toFixed(3))))
    } else {
      onUpdateQty(product.id, line.quantity - 1)
    }
  }

  const handleIncrement = () => {
    if (unitMode === 'box') {
      onUpdateQty(product.id, ((boxCount ?? 0) + 1) * unitsPerBox)
    } else if (unitMode === 'kg' || unitMode === 'liter') {
      onUpdateQty(product.id, parseFloat((line.quantity + 0.25).toFixed(3)))
    } else {
      onUpdateQty(product.id, line.quantity + 1)
    }
  }

  const handleQtyInputChange = (raw: string) => {
    if (unitMode === 'kg' || unitMode === 'liter') {
      const val = parseFloat(raw) || 0.001
      onUpdateQty(product.id, Math.max(0.001, val))
    } else if (unitMode === 'box') {
      const val = parseInt(raw, 10) || 1
      onUpdateQty(product.id, Math.max(1, val) * unitsPerBox)
    } else {
      const val = parseInt(raw, 10) || 1
      onUpdateQty(product.id, Math.max(1, val))
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
            {product.name} {product.size_name ? `(${product.size_name})` : ''}
          </div>
          <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>{product.barcode}</div>
          {(() => {
            const parentId = product.parent_product_id || allProducts?.find((p: any) => p.id === product.id && p.sizes && p.sizes.length > 0)?.id
            const parentProduct = parentId ? allProducts?.find((p: any) => p.id === parentId) : null
            const sizesList = parentProduct?.sizes || []
            if (sizesList.length === 0) return null
            return (
              <div style={{ marginTop: '0.25rem', display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>المقاس:</span>
                <select
                  value={product.id}
                  onChange={(e) => {
                    const newId = Number(e.target.value)
                    const newProduct = sizesList.find((s: any) => s.id === newId)
                    if (newProduct && onSwitchProduct) {
                      onSwitchProduct(product.id, newProduct, parentProduct)
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
                      {sz.size_name} — {formatCurrency(parseFloat(sz.cost) > 0 ? sz.cost : sz.price)}
                    </option>
                  ))}
                </select>
              </div>
            )
          })()}
          <div style={{ fontSize: '0.85rem', color: 'var(--secondary)', fontWeight: 700 }}>
            {formatCurrency(line.cost * line.quantity)}
          </div>
        </div>
        <div
          style={{
            fontSize: '0.8rem',
            color: 'var(--text-muted)',
            minWidth: '50px',
            textAlign: 'left',
            flexShrink: 0,
            marginTop: '0.1rem',
          }}
        >
          {formatCurrency(line.cost)}
        </div>
        <button
          type="button"
          className="btn btn-icon"
          style={{
            padding: '0.3rem',
            color: 'var(--danger)',
            background: 'transparent',
            border: 'none',
            flexShrink: 0,
            marginTop: '0.1rem',
          }}
          onClick={onRemove}
          title="إزالة"
        >
          <Trash2 size={16} />
        </button>
      </div>

      <div style={{ marginTop: '0.45rem' }}>
        <label style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.2rem' }}>
          التكلفة للوحدة
        </label>
        <NumericInput
          min="0"
          step="0.01"
          className="input"
          style={{ width: '100%', padding: '0.35rem 0.5rem', fontSize: '0.85rem' }}
          value={line.cost}
          onChange={(e) => onUpdateCost(product.id, e.target.value)}
        />
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginTop: '0.45rem', flexWrap: 'wrap' }}>
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

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flex: 1, minWidth: '140px' }}>
          <button
            type="button"
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
            onChange={(e) => handleQtyInputChange(e.target.value)}
            style={{
              width: unitMode === 'kg' || unitMode === 'liter' ? '4rem' : '3rem',
              textAlign: 'center',
              border: '1px solid var(--border)',
              borderRadius: '0.3rem',
              padding: '0.25rem 0.2rem',
              fontSize: '0.9rem',
            }}
          />
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleIncrement}
          >
            <Plus size={14} />
          </button>
        </div>

        {/* أزرار أوزان سريعة */}
        {(unitMode === 'kg' || unitMode === 'liter') && (
          <div style={{ display: 'flex', gap: '0.2rem', flexShrink: 0 }}>
            {[0.25, 0.5, 0.75, 1].map(w => (
              <button key={w} type="button" className="btn btn-ghost btn-sm"
                onClick={() => onUpdateQty(product.id, w)}
                style={{ padding: '0.15rem 0.3rem', fontSize: '0.68rem', fontWeight: 700, borderRadius: '0.25rem', minWidth: '28px',
                  border: line.quantity === w ? '1px solid var(--primary)' : undefined,
                  color: line.quantity === w ? 'var(--primary)' : undefined }}
                title={`${w} ${unitMode === 'kg' ? 'كجم' : 'لتر'}`}>
                {w === 0.25 ? '0.25' : w === 0.5 ? '0.5' : w === 0.75 ? '0.75' : '1'}
              </button>
            ))}
          </div>
        )}

        <span
          style={{
            fontSize: '0.72rem',
            color: unitMode === 'box' ? 'var(--secondary)' : (unitMode === 'kg' || unitMode === 'liter') ? 'var(--primary)' : 'var(--text-muted)',
            fontWeight: 600,
            flexShrink: 0,
            display: 'flex',
            alignItems: 'center',
            gap: '0.2rem',
          }}
        >
          {unitMode === 'box' ? (
            <>
              {formatNumber(line.quantity)} قطعة
            </>
          ) : unitMode === 'kg' ? `${parseFloat(line.quantity).toFixed(3)} كجم` : unitMode === 'liter' ? `${parseFloat(line.quantity).toFixed(2)} لتر` : null}
        </span>
      </div>
    </div>
  )
}
