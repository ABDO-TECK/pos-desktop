import type { Dispatch, SetStateAction } from 'react'
import { ArrowRight, Phone, PlusCircle } from 'lucide-react'
import { formatCurrency } from '../../utils/formatters'
import { QZPrintButton } from '../../components/QZPrinterUI'
import { exportCustomerLedgerPDF } from '../../utils/pdfExport'
import toast from 'react-hot-toast'
import CustomerLedgerTable from './components/CustomerLedgerTable'
import type useQZPrinter from '../../hooks/useQZPrinter'

interface CustomerEditEntryForm {
  type: string
  amount: string
  description: string
}

interface CustomerLedgerPanelProps {
  ledgerData: CustomerLedgerData | null
  setLedgerData: Dispatch<SetStateAction<CustomerLedgerData | null>>
  qz: ReturnType<typeof useQZPrinter>
  setPayModal: (open: boolean) => void
  ledgerLoading: boolean
  setEditEntryModal: Dispatch<SetStateAction<CustomerLedgerRow | null>>
  setEditEntryForm: Dispatch<SetStateAction<CustomerEditEntryForm>>
  onDeleteEntry: (entryId: number) => void
  onViewInvoice: (invoiceId: number) => void
}

export default function CustomerLedgerPanel({
  ledgerData,
  setLedgerData,
  qz,
  setPayModal,
  ledgerLoading,
  setEditEntryModal,
  setEditEntryForm,
  onDeleteEntry,
  onViewInvoice
}: CustomerLedgerPanelProps) {
  if (!ledgerData) return null

  return (
    <div className="split-detail">
      {/* رأس كشف الحساب */}
      <div className="ledger-header">
        <div className="ledger-header-title">
          <button className="btn btn-ghost btn-icon" onClick={() => setLedgerData(null)}>
            <ArrowRight size={18} />
          </button>
          <div style={{ flex: 1, minWidth: 0 }}>
            <h2 style={{ fontSize: '1.1rem', fontWeight: 700, margin: 0, wordBreak: 'break-word' }}>
              كشف حساب — {ledgerData.customer.name}
            </h2>
            {ledgerData.customer.phone && (
              <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                <Phone size={11} style={{ verticalAlign: 'middle' }} /> {ledgerData.customer.phone}
              </span>
            )}
          </div>
        </div>

        <div className="ledger-header-actions">
          {/* الرصيد الإجمالي */}
          <div className="ledger-balance" style={{
            background: ledgerData.balance > 0 ? 'rgba(239,68,68,.08)' : 'rgba(34,197,94,.08)',
            border: `1px solid ${ledgerData.balance > 0 ? '#fca5a5' : '#86efac'}`,
            borderRadius: 'var(--radius)', padding: '0.4rem 0.9rem', textAlign: 'center',
          }}>
            <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginBottom: '0.1rem' }}>الرصيد المستحق</div>
            <div style={{ fontSize: '1.1rem', fontWeight: 800, color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
              {formatCurrency(Math.abs(ledgerData.balance))}
            </div>
          </div>

          <div style={{ display: 'flex', gap: '0.4rem' }}>
            <button className="btn btn-primary btn-sm" style={{ padding: '0.4rem 0.8rem', justifyContent: 'center' }} onClick={() => setPayModal(true)}>
              <PlusCircle size={15} /> تسجيل دفعة
            </button>
            <QZPrintButton
              qzReady={qz.qzReady}
              printing={qz.printing}
              onQZPrint={async () => {
                const b64 = await exportCustomerLedgerPDF(ledgerData.customer.id, true)
                if (!b64) return
                const r = await qz.qzPrintPDF(b64)
                if (r.ok) toast.success('تمت الطباعة بنجاح')
                else if (r.error) toast.error('فشل الطباعة: ' + r.error)
              }}
              onPickPrinter={() => qz.setShowPrinterPicker(true)}
              onBrowserPrint={() => exportCustomerLedgerPDF(ledgerData.customer.id)}
              label="طباعة وتصدير"
            />
          </div>
        </div>
      </div>

      {/* جدول كشف الحساب */}
      <CustomerLedgerTable
        ledgerLoading={ledgerLoading}
        ledgerData={ledgerData}
        setEditEntryModal={setEditEntryModal}
        setEditEntryForm={setEditEntryForm}
        onDeleteEntry={onDeleteEntry}
        onViewInvoice={onViewInvoice}
      />
    </div>
  )
}
