import type { Dispatch, SetStateAction } from 'react'
import { formatCurrency } from '../../../utils/formatters'
import NumericInput from '../../../components/forms/NumericInput'

type PurchasePaymentType = 'cash' | 'credit'

interface CreditPurchaseSectionProps {
  paymentType: PurchasePaymentType
  setPaymentType: Dispatch<SetStateAction<PurchasePaymentType>>
  deposit: number
  setDeposit: (deposit: number) => void
  purchaseTotal: number
}

export default function CreditPurchaseSection({ paymentType, setPaymentType, deposit, setDeposit, purchaseTotal }: CreditPurchaseSectionProps) {
  const amountDue = paymentType === 'credit' ? Math.max(0, purchaseTotal - deposit) : 0

  return (
    <div style={{
      border: `1px solid ${paymentType === 'credit' ? 'rgba(239,68,68,.3)' : 'var(--border)'}`,
      borderRadius: 'var(--radius)',
      background: paymentType === 'credit' ? 'rgba(239,68,68,.03)' : 'var(--surface)',
      padding: '0.65rem', display: 'flex', flexDirection: 'column', gap: '0.5rem',
    }}>
      <div style={{ display: 'flex', gap: '0.35rem' }}>
        {([
          { id: 'cash', label: '💵 نقدي' },
          { id: 'credit', label: '⏳ آجل' },
        ] as const).map((m) => (
          <button key={m.id} onClick={() => setPaymentType(m.id)}
            style={{
              flex: 1, padding: '0.35rem', fontSize: '0.82rem', fontWeight: 600,
              borderRadius: 'var(--radius)',
              border: `2px solid ${paymentType === m.id ? (m.id === 'credit' ? 'var(--danger)' : 'var(--primary)') : 'var(--border)'}`,
              background: paymentType === m.id ? (m.id === 'credit' ? 'rgba(239,68,68,.1)' : 'var(--sup-primary-soft)') : 'var(--surface)',
              color: paymentType === m.id ? (m.id === 'credit' ? 'var(--danger)' : 'var(--primary-d)') : 'var(--text)',
              cursor: 'pointer',
            }}>
            {m.label}
          </button>
        ))}
      </div>

      {paymentType === 'credit' && (
        <>
          <div>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, display: 'block', marginBottom: '0.2rem' }}>
              العربون / المبلغ المقدَّم (ج.م) — اختياري
            </label>
            <NumericInput className="input" min={0} max={purchaseTotal} step="0.5"
              placeholder="0.00" value={deposit || ''}
              onChange={e => setDeposit(Math.min(parseFloat(e.target.value) || 0, purchaseTotal))} />
            {amountDue > 0 && (
              <div style={{ fontSize: '0.78rem', color: 'var(--danger)', marginTop: '0.2rem', fontWeight: 600 }}>
                ⬅ المتبقي على الذمة: {formatCurrency(amountDue)}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
