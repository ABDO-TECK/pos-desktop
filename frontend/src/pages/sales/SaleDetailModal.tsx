import React from 'react'
import { X, Trash2, ShoppingCart } from 'lucide-react'
import { formatCurrency, formatDate, formatNumber } from '../../utils/formatters'
import { browserPrint, buildReceiptHTML } from '../../utils/receiptBuilder'
import { QZPrintButton } from '../../components/QZPrinterUI'
import InfoCard from '../../components/common/InfoCard'
import TotalRow from '../../components/common/TotalRow'
import toast from 'react-hot-toast'

const METHOD_LABELS: Record<string, string> = {
  cash:          'نقدي',
  card:          'بطاقة',
  vodafone_cash: 'فودافون كاش',
  instapay:      'انستاباي',
  other_wallet:  'محفظة أخرى',
  credit:        'آجل',
}

interface SaleDetailModalProps {
  selected: any
  detailLoading: boolean
  deleting: boolean
  isAdmin: boolean
  settings: any
  qz: any
  onClose: () => void
  onReturnToCart: () => void
  onDelete: () => void
}

export default function SaleDetailModal({
  selected,
  detailLoading,
  deleting,
  isAdmin,
  settings,
  qz,
  onClose,
  onReturnToCart,
  onDelete
}: SaleDetailModalProps) {
  const [hidePrices, setHidePrices] = React.useState(false)
  const [hideQuantities, setHideQuantities] = React.useState(false)

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '600px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h2 style={{ fontWeight: 700, fontSize: '1.1rem' }}>
            {selected ? `فاتورة #${formatNumber(selected.id)}` : 'جاري التحميل…'}
          </h2>
          <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
            {selected && (
              <QZPrintButton
                multiSize={true}
                qzReady={qz.qzReady}
                printing={qz.printing}
                onQZPrint={async (paperSize: string) => {
                  const inv = { ...selected, items: (selected.items ?? []).map((i: any) => ({ ...i, product_name: i.product_name ?? i.name })) }
                  const html = buildReceiptHTML(inv, parseFloat(selected.change_due) || 0, settings, paperSize, { hidePrices, hideQuantities })
                  const r = await qz.qzPrint(html)
                  if (r.ok) toast.success(`تمت الطباعة بنجاح (${paperSize})`)
                  else if (r.error) toast.error('فشل الطباعة: ' + r.error)
                }}
                onPickPrinter={() => qz.setShowPrinterPicker(true)}
                onBrowserPrint={(paperSize: string) => browserPrint(
                  { ...selected, items: (selected.items ?? []).map((i: any) => ({ ...i, product_name: i.product_name ?? i.name })) },
                  parseFloat(selected.change_due) || 0,
                  settings,
                  paperSize,
                  { hidePrices, hideQuantities }
                )}
              />
            )}
            <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18}/></button>
          </div>
        </div>

        {selected && (
          <div style={{
            display: 'flex',
            gap: '1.5rem',
            background: 'var(--bg)',
            borderRadius: 'var(--radius)',
            padding: '0.6rem 0.8rem',
            marginBottom: '1rem',
            border: '1px solid var(--border)',
            alignItems: 'center'
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHidePrices(!hidePrices)}>
              <input type="checkbox" checked={hidePrices} readOnly style={{ pointerEvents: 'none', cursor: 'pointer' }} />
              <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الأسعار في الطباعة</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHideQuantities(!hideQuantities)}>
              <input type="checkbox" checked={hideQuantities} readOnly style={{ pointerEvents: 'none', cursor: 'pointer' }} />
              <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الكميات في الطباعة</span>
            </div>
          </div>
        )}

        {detailLoading && (
          <div style={{ padding: '2rem', textAlign: 'center' }}><span className="spinner" /></div>
        )}

        {selected && (
          <>
            {/* Invoice meta */}
            <div className="resp-2col" style={{ marginBottom: '1rem' }}>
              <InfoCard label="الكاشير"      value={selected.cashier_name ?? '—'} />
              <InfoCard label="طريقة الدفع"  value={METHOD_LABELS[selected.payment_method] ?? selected.payment_method} />
              <InfoCard label="التاريخ"       value={formatDate(selected.created_at)} />
              <InfoCard label="المبلغ المدفوع" value={formatCurrency(selected.amount_paid)} />
            </div>

            {/* Delivery Info */}
            {(selected.driver_name || Number(selected.shipping_cost) > 0 || selected.delivery_date || selected.delivery_notes) && (
              <div style={{
                background: 'var(--bg)',
                border: '1px solid var(--border)',
                borderRadius: 'var(--radius)',
                padding: '0.75rem',
                marginBottom: '1rem'
              }}>
                <h4 style={{ fontSize: '0.85rem', fontWeight: 700, marginBottom: '0.5rem', display: 'flex', alignItems: 'center', gap: '0.3rem' }}>
                  🚚 بيانات التسليم والشحن
                </h4>
                <div className="resp-2col" style={{ gap: '0.5rem', fontSize: '0.8rem' }}>
                  {selected.driver_name && <div><strong>اسم السائق:</strong> {selected.driver_name}</div>}
                  {Number(selected.shipping_cost) > 0 && <div><strong>تكلفة الشحن:</strong> {formatCurrency(selected.shipping_cost)}</div>}
                  {selected.delivery_date && <div><strong>تاريخ التسليم:</strong> {formatDate(selected.delivery_date)}</div>}
                  {selected.delivery_notes && <div style={{ gridColumn: '1/-1', whiteSpace: 'pre-wrap' }}><strong>ملاحظات التسليم:</strong><br/>{selected.delivery_notes}</div>}
                </div>
              </div>
            )}

            {/* Items table */}
            <div className="table-wrapper" style={{ marginBottom: '1rem' }}>
              <table style={{ fontSize: '0.88rem' }}>
                <thead>
                  <tr>
                    <th>المنتج</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                    <th>الإجمالي</th>
                  </tr>
                </thead>
                <tbody>
                  {(selected.items ?? []).map((item: any, idx: number) => {
                    const qty = parseFloat(item.quantity)
                    const unitType = item.unit_type ?? (parseInt(item.sell_by_weight) === 1 ? 'weight' : 'piece')
                    const isByWeight = unitType === 'weight'
                    const isByLiter = unitType === 'liter'
                    const qtyDisplay = isByWeight 
                      ? `${qty.toFixed(3)} كجم` 
                      : isByLiter 
                      ? `${qty.toFixed(2)} لتر` 
                      : formatNumber(item.quantity)
                    const nameDisplay = (item.product_name ?? item.name) + (item.size_name ? ` (${item.size_name})` : '')
                    return (
                    <tr key={idx}>
                      <td>{nameDisplay}</td>
                      <td>{qtyDisplay}</td>
                      <td>{formatCurrency(item.price)}</td>
                      <td style={{ fontWeight: 600 }}>{formatCurrency(item.price * qty)}</td>
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {/* Totals */}
            <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', fontSize: '0.9rem', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
              <TotalRow label="المجموع الجزئي" value={formatCurrency(selected.subtotal)} />
              {parseFloat(selected.discount) > 0 && (
                <TotalRow label="الخصم" value={`- ${formatCurrency(selected.discount)}`} danger />
              )}
              {parseFloat(selected.tax) > 0 && (
                <TotalRow label="الضريبة" value={formatCurrency(selected.tax)} />
              )}
              <div style={{ borderTop: '2px solid var(--border)', margin: '0.3rem 0' }} />
              <TotalRow label="الإجمالي" value={formatCurrency(selected.total)} bold green />
              {parseFloat(selected.change_due) > 0 && (
                <TotalRow label="الباقي" value={formatCurrency(selected.change_due)} />
              )}
              {selected.payment_method === 'credit' && (
                <TotalRow label="المدفوع (عربون)" value={formatCurrency(selected.amount_paid)} />
              )}
              {selected.payment_method === 'credit' && parseFloat(selected.amount_due) > 0 && (
                <TotalRow label="المتبقي على الذمة" value={formatCurrency(selected.amount_due)} danger bold />
              )}
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginTop: '1rem' }}>
              <button
                type="button"
                className="btn btn-primary"
                style={{ flex: '1 1 160px', justifyContent: 'center' }}
                onClick={onReturnToCart}
              >
                <ShoppingCart size={16} />
                إرجاع المنتجات للسلة
              </button>
              {isAdmin && (
                <button
                  type="button"
                  className="btn btn-danger"
                  style={{ flex: '1 1 160px', justifyContent: 'center' }}
                  onClick={onDelete}
                  disabled={deleting}
                >
                  {deleting ? <span className="spinner" /> : <Trash2 size={16} />}
                  حذف الفاتورة
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
