import { X, PlusCircle } from 'lucide-react'
import { formatCurrency } from '../../../utils/formatters'
import NumericInput from '../../../components/forms/NumericInput'

interface SupplierPaymentModalProps {
  payModal: boolean
  setPayModal: (open: boolean) => void
  ledgerData: { balance?: number } | null
  payType: string
  setPayType: (type: string) => void
  payAmount: string
  setPayAmount: (amount: string) => void
  payDesc: string
  setPayDesc: (description: string) => void
  handlePayment: () => void
  payLoading: boolean
}

export default function SupplierPaymentModal({
  payModal, setPayModal, ledgerData,
  payType, setPayType, payAmount, setPayAmount,
  payDesc, setPayDesc, handlePayment, payLoading
}: SupplierPaymentModalProps) {
  if (!payModal) return null

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setPayModal(false)}>
      <div className="modal" style={{ maxWidth: '380px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>تسجيل دفعة للمورد</h2>
          <button className="btn btn-ghost btn-icon" onClick={() => setPayModal(false)}><X size={18} /></button>
        </div>

        <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.75rem 1rem', marginBottom: '1rem', display: 'flex', justifyContent: 'space-between' }}>
          <span style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>الرصيد المستحق</span>
          <span style={{ fontWeight: 700, color: 'var(--danger)' }}>{formatCurrency(ledgerData?.balance)}</span>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          <div>
            <label className="label">نوع الدفعة</label>
            <div style={{ display: 'flex', gap: '0.35rem' }}>
              {[
                { id: 'debit',  label: 'استرداد مبلغ',     color: 'var(--danger)', bg: 'rgba(239,68,68,.1)' },
                { id: 'credit', label: 'دفع مبلغ للمورد',  color: 'var(--primary)', bg: 'rgba(34,197,94,.1)' },
              ].map(d => (
                <button key={d.id} type="button" onClick={() => setPayType(d.id)}
                  style={{
                    flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                    borderRadius: 'var(--radius)',
                    border: `2px solid ${payType === d.id ? d.color : 'var(--border)'}`,
                    background: payType === d.id ? d.bg : 'var(--surface)',
                    color: payType === d.id ? d.color : 'var(--text-muted)',
                    cursor: 'pointer', transition: 'all .15s',
                  }}
                >{d.label}</button>
              ))}
            </div>
          </div>
          <div>
            <label className="label">المبلغ (ج.م) *</label>
            <NumericInput className="input input-lg" min="0.01" step="0.01"
              placeholder="0.00" value={payAmount}
              onChange={e => setPayAmount(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handlePayment()} />
          </div>
          <div>
            <label className="label">البيان</label>
            <input className="input" placeholder="دفعة نقدية" value={payDesc}
              onChange={e => setPayDesc(e.target.value)} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem', justifyContent: 'flex-end' }}>
          <button className="btn btn-ghost" onClick={() => setPayModal(false)}>إلغاء</button>
          <button className="btn btn-primary" onClick={handlePayment} disabled={payLoading}>
            {payLoading ? <span className="spinner" /> : <PlusCircle size={16} />}
            تسجيل الدفعة
          </button>
        </div>
      </div>
    </div>
  )
}
