import type { Dispatch, SetStateAction } from 'react'
import { formatCurrency, formatStatementDate } from '../../../utils/formatters'
import { Edit2, Trash2 } from 'lucide-react'
import '../../../styles/ledger-statement.css'

export interface SupplierLedgerEntry {
  id: number | null
  type: 'debit' | 'credit' | 'initial'
  date?: string
  created_at?: string
  description?: string
  purchase_invoice_id?: number | null
  debit: number
  credit: number
  balance: number
}

export interface SupplierLedgerData {
  entries: SupplierLedgerEntry[]
  balance: number
  truncated?: boolean
  total_entries?: number
}

export interface SupplierEntryForm {
  type: string
  amount: string
  description: string
}

interface SupplierLedgerTableProps {
  ledgerLoading: boolean
  ledgerData: SupplierLedgerData | null
  setEditEntryModal: Dispatch<SetStateAction<SupplierLedgerEntry | null>>
  setEditEntryForm: Dispatch<SetStateAction<SupplierEntryForm>>
  onDeleteEntry: (entryId: number) => void
  onViewInvoice: (invoiceId: number) => void
}

export default function SupplierLedgerTable({
  ledgerLoading, ledgerData,
  setEditEntryModal, setEditEntryForm,
  onDeleteEntry, onViewInvoice
}: SupplierLedgerTableProps) {
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
            {['التاريخ', 'البيان', 'مدين (دفعة)', 'دائن (مستحق)', 'الرصيد', ''].map((h, i) => (
              <th key={i} style={{
                padding: '0.65rem 0.75rem', fontWeight: 700,
                textAlign: h.includes('مدين') || h.includes('دائن') || h.includes('الرصيد') ? 'left' : 'right',
                borderBottom: '2px solid var(--border)', whiteSpace: 'nowrap', width: h === '' ? '80px' : 'auto'
              }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ledgerData.entries.length === 0 ? (
            <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد حركات بعد</td></tr>
          ) : ledgerData.entries.map((row, i) => {
            const isDebit = row.type === 'debit'
            const isCredit = row.type === 'credit'
            const purchaseInvoiceId = row.purchase_invoice_id
            const entryId = row.id
            return (
              <tr key={row.id ?? `init-${i}`} style={{ borderBottom: '1px solid var(--border)', background: 'var(--bg)', transition: 'background .2s' }}>
                <td className="statement-date" dir="ltr" style={{ padding: '0.6rem 0.75rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                  <bdi dir="ltr">{formatStatementDate(row.date ?? row.created_at)}</bdi>
                </td>
                <td style={{ padding: '0.6rem 0.75rem' }}>
                  {row.description}
                  {purchaseInvoiceId !== null && purchaseInvoiceId !== undefined && (
                    <button
                      className="btn btn-link btn-sm"
                      style={{
                        marginRight: '0.5rem',
                        padding: 0,
                        fontSize: '0.78rem',
                        color: 'var(--primary)',
                        textDecoration: 'underline',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '0.2rem',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer'
                      }}
                      onClick={() => onViewInvoice(purchaseInvoiceId)}
                    >
                      (فاتورة شراء #{row.purchase_invoice_id})
                    </button>
                  )}
                </td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: isDebit ? 'var(--danger)' : 'var(--text-muted)', fontWeight: isDebit ? 600 : 400 }}>
                  {isDebit ? formatCurrency(row.debit) : '-'}
                </td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: isCredit ? 'var(--primary)' : 'var(--text-muted)', fontWeight: isCredit ? 600 : 400 }}>
                  {isCredit ? formatCurrency(row.credit) : '-'}
                </td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', fontWeight: 700, color: row.balance > 0 ? 'var(--danger)' : row.balance < 0 ? 'var(--primary)' : 'inherit' }}>
                  {formatCurrency(Math.abs(row.balance))}
                </td>
                <td style={{ padding: '0.4rem', textAlign: 'center' }}>
                  <div style={{ display: 'flex', gap: '0.25rem', justifyContent: 'center' }}>
                    <button className="btn btn-ghost btn-icon btn-sm"
                      onClick={(e) => {
                        e.stopPropagation()
                        setEditEntryModal(row)
                        setEditEntryForm({
                          type: row.type,
                          amount: String(row.type === 'debit' ? row.debit : row.credit),
                          description: row.description || ''
                        })
                      }}
                      title="تعديل القيد"
                    >
                      <Edit2 size={13} />
                    </button>
                    {entryId !== null && row.type !== 'initial' && (
                      <button className="btn btn-ghost btn-icon btn-sm"
                        style={{ color: 'var(--danger)' }}
                        onClick={(e) => {
                          e.stopPropagation()
                          onDeleteEntry(entryId)
                        }}
                        title="حذف القيد"
                      >
                        <Trash2 size={13} />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            )
          })}
        </tbody>
        {ledgerData.entries.length > 0 && (
          <tfoot>
            <tr style={{ background: 'var(--surface)', fontWeight: 700, borderTop: '2px solid var(--border)' }}>
              <td colSpan={2} style={{ padding: '0.6rem 0.75rem' }}>{ledgerData.truncated ? 'إجمالي النافذة المعروضة' : 'الإجمالي'}</td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--danger)' }}>
                {formatCurrency(ledgerData.entries.filter((row) => row.id !== null).reduce((sum, row) => sum + row.debit, 0))}
              </td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--primary)' }}>
                {formatCurrency(ledgerData.entries.filter((row) => row.id !== null).reduce((sum, row) => sum + row.credit, 0))}
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
