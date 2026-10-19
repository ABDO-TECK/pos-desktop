import { formatCurrency, formatNumber } from '../../../utils/formatters'

interface ReceiveGoodsProductCardProps {
  product: Product
  onAdd: () => void
}

export default function ReceiveGoodsProductCard({ product, onAdd }: ReceiveGoodsProductCardProps) {
  const isOutOfStock = product.quantity <= 0
  const isLowStock   = product.quantity <= (product.low_stock_threshold ?? 0) && product.quantity > 0
  const unitType     = product.unit_type ?? (Number(product.sell_by_weight) === 1 ? 'weight' : 'piece')
  const isByWeight   = unitType === 'weight'
  const isByLiter    = unitType === 'liter'
  const isByPiece    = unitType === 'piece'
  const upb          = Number(product.units_per_box) || 1

  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onAdd}
      style={{
        background: 'var(--surface)',
        border: `1px solid ${isLowStock ? 'var(--warning)' : isOutOfStock ? '#fca5a5' : 'var(--border)'}`,
        borderRadius: 'var(--radius)',
        padding: '0.5rem 0.6rem',
        cursor: 'pointer',
        textAlign: 'right',
        transition: 'transform .1s, box-shadow .1s',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        minHeight: '92px',
        touchAction: 'manipulation',
        fontFamily: 'inherit',
        width: '100%',
      }}
    >
      <div style={{
        fontSize: '0.78rem', fontWeight: 600, lineHeight: 1.3,
        wordBreak: 'break-word', overflowWrap: 'anywhere',
        color: 'var(--text)',
        marginBottom: '0.2rem',
      }}>
        {product.name}
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', marginTop: 'auto', gap: '0.3rem', width: '100%' }}>
        <div style={{ display: 'flex', gap: '0.2rem', alignItems: 'center', flexWrap: 'wrap' }}>
          {isByWeight && (
            <span className="badge badge-green" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>وزن</span>
          )}
          {isByLiter && (
            <span className="badge badge-green" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>لتر</span>
          )}
          {upb > 1 && isByPiece && (
            <span className="badge badge-blue" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>
              صندوق ({formatNumber(upb)})
            </span>
          )}
          {(product.sizes || []).length > 0 && (
            <span className="badge badge-blue" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>
              مقاسات ({formatNumber((product.sizes || []).length)})
            </span>
          )}
          {isOutOfStock && <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>}
          {isLowStock   && <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>}
        </div>
        <span style={{ fontSize: '0.88rem', fontWeight: 700, color: 'var(--secondary)' }}>
          {formatCurrency(Number(product.cost) > 0 ? product.cost : product.price)}
        </span>
      </div>
    </button>
  )
}
