import type { Dispatch, SetStateAction } from 'react'
import { X } from 'lucide-react'
import NumericInput from '../../../components/forms/NumericInput'

interface CustomerFormData {
  name: string
  phone: string
  address: string
  initial_balance: string
  balance_direction: string
}

interface CustomerFormModalProps {
  modal: 'create' | 'edit' | null
  setModal: (modal: 'create' | 'edit' | null) => void
  form: CustomerFormData
  setForm: Dispatch<SetStateAction<CustomerFormData>>
  handleSave: () => void
  saving: boolean
}

export default function CustomerFormModal({ modal, setModal, form, setForm, handleSave, saving }: CustomerFormModalProps) {
  if (!modal) return null

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setModal(null)}>
      <div className="modal" style={{ maxWidth: '440px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>
            {modal === 'create' ? 'إضافة عميل جديد' : 'تعديل بيانات العميل'}
          </h2>
          <button className="btn btn-ghost btn-icon" onClick={() => setModal(null)}><X size={18} /></button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          <div>
            <label className="label">الاسم *</label>
            <input className="input" placeholder="اسم العميل" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />
          </div>
          <div>
            <label className="label">رقم الهاتف</label>
            <input className="input" placeholder="05xxxxxxxx" value={form.phone} onChange={e => setForm(f => ({ ...f, phone: e.target.value }))} />
          </div>
          <div>
            <label className="label">العنوان</label>
            <input className="input" placeholder="العنوان (اختياري)" value={form.address} onChange={e => setForm(f => ({ ...f, address: e.target.value }))} />
          </div>
          <div style={{ background: 'rgba(59,130,246,.06)', border: '1px dashed var(--secondary)', borderRadius: 'var(--radius)', padding: '0.65rem 0.75rem' }}>
            <label className="label">📒 رصيد مبدئي (ج.م)</label>
            <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: '0 0 0.4rem' }}>
              حدد اتجاه الرصيد المبدئي ثم أدخل المبلغ، وإلا اتركه 0
            </p>
            <div style={{ display: 'flex', gap: '0.35rem', marginBottom: '0.5rem' }}>
              {[
                { id: 'debit',  label: '⬅ هو مدين لي',  color: 'var(--danger)',  bg: 'rgba(239,68,68,.1)' },
                { id: 'credit', label: '➡ أنا مدين له', color: 'var(--secondary)', bg: 'rgba(59,130,246,.1)' },
              ].map(d => (
                <button key={d.id} type="button" onClick={() => setForm(f => ({ ...f, balance_direction: d.id }))}
                  style={{
                    flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                    borderRadius: 'var(--radius)',
                    border: `2px solid ${form.balance_direction === d.id ? d.color : 'var(--border)'}`,
                    background: form.balance_direction === d.id ? d.bg : 'var(--surface)',
                    color: form.balance_direction === d.id ? d.color : 'var(--text-muted)',
                    cursor: 'pointer', transition: 'all .15s',
                  }}
                >{d.label}</button>
              ))}
            </div>
            <NumericInput className="input" min="0" step="0.01" placeholder="0.00"
              value={form.initial_balance}
              onChange={e => setForm(f => ({ ...f, initial_balance: e.target.value }))} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem', justifyContent: 'flex-end' }}>
          <button className="btn btn-ghost" onClick={() => setModal(null)}>إلغاء</button>
          <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
            {saving ? <span className="spinner" /> : null}
            {modal === 'create' ? 'إضافة' : 'حفظ التعديلات'}
          </button>
        </div>
      </div>
    </div>
  )
}
