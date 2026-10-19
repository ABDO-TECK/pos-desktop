import LedgerRow from '../../../components/customers/LedgerRow'
import { formatCurrency } from '../../../utils/formatters'
import '../../../styles/ledger-statement.css'

interface CustomerEditEntryForm {
  type: string
  amount: string
  description: string
}

interface CustomerLedgerTableProps {
  ledgerLoading: boolean
  ledgerData: CustomerLedgerData | null
  setEditEntryModal: (entry: CustomerLedgerRow | null) => void
  setEditEntryForm: (form: CustomerEditEntryForm) => void
  onDeleteEntry: (entryId: number) => void
  onViewInvoice: (invoiceId: number) => void
}

export default function CustomerLedgerTable({
  ledgerLoading, ledgerData,
  setEditEntryModal, setEditEntryForm,
  onDeleteEntry, onViewInvoice
}: CustomerLedgerTableProps) {
  if (ledgerLoading) {
    return <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
  }

  if (!ledgerData) return null

  return (
    <div className="statement-table-shell" style={{ flex: 1, overflowY: 'auto', borderRadius: 'var(--radius)', border: '1px solid var(--border)' }}>
      {ledgerData.truncated && (
        <div className="statement-notice" role="status" style={{ padding: '0.65rem 0.75rem', background: 'var(--warning-bg, #fff7dd)', color: 'var(--text)' }}>
          يتم عرض أحدث 500 حركة من أصل {ledgerData.total_entries}. الرصيد الافتتاحي يلخّص الحركات الأقدم.
        </div>
      )}
      <table className="statement-table" style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
        <thead>
          <tr style={{ background: 'var(--surface)', position: 'sticky', top: 0, zIndex: 1 }}>
            {['التاريخ', 'البيان', 'مدين', 'دائن', 'الرصيد', ''].map(h => (
              <th key={h} style={{
                padding: '0.65rem 0.75rem', fontWeight: 700, textAlign: h === 'مدين' || h === 'دائن' || h === 'الرصيد' ? 'left' : 'right',
                borderBottom: '2px solid var(--border)', whiteSpace: 'nowrap', width: h === '' ? '80px' : 'auto'
              }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ledgerData.entries.length === 0 ? (
            <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد حركات بعد</td></tr>
          ) : ledgerData.entries.map((row, i) => (
            <LedgerRow key={row.id ?? `init-${i}`} row={row}
              onEdit={() => {
                setEditEntryModal(row)
                setEditEntryForm({
                  type: row.type,
                  amount: String(row.type === 'debit' ? (row.debit || 0) : (row.credit || 0)),
                  description: row.description || ''
                })
              }}
              onDelete={() => {
                if (row.id !== null) onDeleteEntry(row.id)
              }}
              onViewInvoice={onViewInvoice}
            />
          ))}
        </tbody>
        {ledgerData.entries.length > 0 && (
          <tfoot>
            <tr style={{ background: 'var(--surface)', fontWeight: 700, borderTop: '2px solid var(--border)' }}>
              <td colSpan={2} style={{ padding: '0.6rem 0.75rem' }}>{ledgerData.truncated ? 'إجمالي النافذة المعروضة' : 'الإجمالي'}</td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--danger)' }}>
                {formatCurrency(ledgerData.entries.filter(r => r.id !== null).reduce((s, r) => s + r.debit, 0))}
              </td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--primary)' }}>
                {formatCurrency(ledgerData.entries.filter(r => r.id !== null).reduce((s, r) => s + r.credit, 0))}
              </td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)', fontSize: '1rem' }}>
                {formatCurrency(ledgerData.balance)}
              </td>
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  )
}
