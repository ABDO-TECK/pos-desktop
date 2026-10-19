import type { Dispatch, SetStateAction } from 'react'
import { X, Edit2 } from 'lucide-react'
import type { SupplierEntryForm, SupplierLedgerEntry } from './SupplierLedgerTable'
import NumericInput from '../../../components/forms/NumericInput'

interface SupplierEditEntryModalProps {
  editEntryModal: SupplierLedgerEntry | null
  setEditEntryModal: Dispatch<SetStateAction<SupplierLedgerEntry | null>>
  editEntryForm: SupplierEntryForm
  setEditEntryForm: Dispatch<SetStateAction<SupplierEntryForm>>
  handleEditEntry: () => void
  editEntryLoading: boolean
}

export default function SupplierEditEntryModal({
  editEntryModal, setEditEntryModal,
  editEntryForm, setEditEntryForm,
  handleEditEntry, editEntryLoading
}: SupplierEditEntryModalProps) {
  if (!editEntryModal) return null

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setEditEntryModal(null)}>
      <div className="modal" style={{ maxWidth: '400px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>تعديل القيد</h2>
          <button className="btn btn-ghost btn-icon" onClick={() => setEditEntryModal(null)}><X size={18} /></button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
          <div>
            <label className="label">نوع القيد</label>
            <div style={{ display: 'flex', gap: '0.35rem' }}>
              {[
                { id: 'credit', label: 'دائن (مستحق له)', color: 'var(--secondary)', bg: 'rgba(59,130,246,.1)' },
                { id: 'debit',  label: 'مدين (دفعة)',     color: 'var(--danger)', bg: 'rgba(239,68,68,.1)' },
              ].map((d) => (
                <button key={d.id} type="button" onClick={() => setEditEntryForm(f => ({ ...f, type: d.id }))}
                  style={{
                    flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                    borderRadius: 'var(--radius)',
                    border: `2px solid ${editEntryForm.type === d.id ? d.color : 'var(--border)'}`,
                    background: editEntryForm.type === d.id ? d.bg : 'var(--surface)',
                    color: editEntryForm.type === d.id ? d.color : 'var(--text-muted)',
                    cursor: 'pointer', transition: 'all .15s',
                  }}
                >{d.label}</button>
              ))}
            </div>
          </div>
          <div>
            <label className="label">المبلغ</label>
            <NumericInput className="input" min="0" step="0.01" value={editEntryForm.amount} onChange={e => setEditEntryForm({ ...editEntryForm, amount: e.target.value })} autoFocus />
          </div>
          <div>
            <label className="label">البيان (اختياري)</label>
            <input className="input" value={editEntryForm.description} onChange={e => setEditEntryForm({ ...editEntryForm, description: e.target.value })} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
          <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setEditEntryModal(null)}>إلغاء</button>
          <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleEditEntry} disabled={editEntryLoading}>
            {editEntryLoading ? <span className="spinner" /> : <Edit2 size={16} />}
            تأكيد التعديل
          </button>
        </div>
      </div>
    </div>
  )
}
