
import { useCallback, useEffect, useRef, useState } from 'react'
import { Plus, Search, Package } from 'lucide-react'
import { exportSupplierLedgerPDF } from '../../utils/pdfExport'
import useQZPrinter from '../../hooks/useQZPrinter'
import { QZPrinterPicker, QZPrintButton } from '../../components/QZPrinterUI'
import toast from 'react-hot-toast'
import { getSuppliers, getSupplier, addSupplierPayment, updateSupplierLedgerEntry, deleteSupplierLedgerEntry, getPurchaseInvoice } from '../../api/endpoints'
import { formatCurrency } from '../../utils/formatters'
import SupplierPaymentModal from './components/SupplierPaymentModal'
import SupplierEditEntryModal from './components/SupplierEditEntryModal'
import SupplierLedgerTable from './components/SupplierLedgerTable'
import PurchaseReceiptModal from './components/PurchaseReceiptModal'
import { extractApiError } from '../../utils/apiError'
import Pagination from '../../components/Pagination'

export default function SupplierAccounts() {
  const [suppliers, setSuppliers]       = useState<Supplier[]>([])
  const [loading, setLoading]           = useState(true)
  const [search, setSearch]             = useState('')
  const [serverSearch, setServerSearch] = useState('')
  const [currentPage, setCurrentPage]   = useState(1)
  const [totalPages, setTotalPages]     = useState(1)
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  // كشف الحساب
  const [ledgerData, setLedgerData]     = useState<any>(null)
  const [ledgerLoading, setLedgerLoading] = useState(false)

  // modal الدفعة
  const [payModal, setPayModal]         = useState(false)
  const [payAmount, setPayAmount]       = useState('')
  const [payDesc, setPayDesc]           = useState('دفعة نقدية للمورد')
  const [payType, setPayType]           = useState('credit')
  const [payLoading, setPayLoading]     = useState(false)

  // modal التعديل للقيد
  const [editEntryModal, setEditEntryModal] = useState<any>(null)
  const [editEntryForm, setEditEntryForm] = useState({ type: 'debit', amount: '', description: '' })
  const [editEntryLoading, setEditEntryLoading] = useState(false)

  const [selectedInvoice, setSelectedInvoice] = useState<PurchaseInvoice | null>(null)

  const handleViewInvoice = async (invoiceId: number) => {
    try {
      const res = await getPurchaseInvoice(invoiceId)
      setSelectedInvoice(res.data.data)
    } catch (err) {
      toast.error(extractApiError(err, 'فشل تحميل تفاصيل الفاتورة'))
    }
  }

  const handleDeleteEntry = async (entryId: number) => {
    if (!window.confirm('هل أنت متأكد من حذف هذا القيد؟')) return
    try {
      const res = await deleteSupplierLedgerEntry(entryId)
      setLedgerData(res.data.data)
      toast.success('تم حذف القيد بنجاح')
      void load(currentPage, serverSearch) // تحديث رصيد المورد في القائمة
    } catch (err) {
      toast.error(extractApiError(err, 'فشل حذف القيد'))
    }
  }

  const qz = useQZPrinter()
  const load = useCallback(async (page: number, query: string) => {
    setLoading(true)
    try {
      const response = await getSuppliers({ page, limit: 20, search: query || undefined })
      setSuppliers(Array.isArray(response.data.data) ? response.data.data : [])
      setCurrentPage(response.data.pagination?.page ?? page)
      setTotalPages(response.data.pagination?.pages ?? 1)
    }
    catch (err) { toast.error(extractApiError(err, 'فشل تحميل الموردين')) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => {
    void load(currentPage, serverSearch)
  }, [currentPage, load, serverSearch])

  useEffect(() => () => {
    if (searchTimer.current) clearTimeout(searchTimer.current)
  }, [])

  const handleSearchChange = (value: string) => {
    setSearch(value)
    if (searchTimer.current) clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => {
      setCurrentPage(1)
      setServerSearch(value.trim())
    }, 300)
  }

  // ── ledger ──
  const openLedger = async (s: Supplier) => {
    setLedgerLoading(true)
    setLedgerData({ supplier: s, entries: [], balance: 0 })
    try {
      const res = await getSupplier(s.id)
      setLedgerData(res.data.data)
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل كشف الحساب')) }
    finally { setLedgerLoading(false) }
  }

  // ── payment ──
  const handlePayment = async () => {
    const amount = parseFloat(payAmount)
    if (!amount || amount <= 0) { toast.error('أدخل مبلغاً صحيحاً'); return }
    setPayLoading(true)
    try {
      const res = await addSupplierPayment(ledgerData.supplier.id, { amount, description: payDesc, type: payType })
      setLedgerData(res.data.data)
      setPayModal(false)
      setPayAmount('')
      setPayDesc('دفعة نقدية للمورد')
      setPayType('credit')
      toast.success(`تم تسجيل دفعة ${formatCurrency(amount)}`)
      void load(currentPage, serverSearch)
    } catch (err) { toast.error(extractApiError(err, 'فشل التسجيل')) }
    finally { setPayLoading(false) }
  }

  const handleEditEntry = async () => {
    if (!editEntryForm.amount || Number.isNaN(Number(editEntryForm.amount))) {
      toast.error('الرجاء إدخال مبلغ صحيح')
      return
    }
    setEditEntryLoading(true)
    try {
      const res = await updateSupplierLedgerEntry(Number(editEntryModal.id), {
        type: editEntryForm.type,
        amount: parseFloat(editEntryForm.amount),
        description: editEntryForm.description,
      })
      toast.success('تم تعديل القيد')
      setLedgerData(res.data.data) // Update UI
      setEditEntryModal(null)
      void load(currentPage, serverSearch) // Refresh list balances
    } catch (err) { toast.error(extractApiError(err, 'فشل تعديل القيد')) } finally {
      setEditEntryLoading(false)
    }
  }

  return (
    <div className={`split-layout ${ledgerData ? 'has-detail' : ''}`}>

      {/* ── القائمة ── */}
      <div className={`split-list ${ledgerData ? 'is-split' : 'full-width'}`}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <h1 style={{ fontSize: '1.2rem', fontWeight: 700, flex: 1 }}>حسابات الموردين</h1>
        </div>

        <div style={{ position: 'relative' }}>
          <Search size={15} style={{ position: 'absolute', right: '0.65rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input className="input" style={{ paddingRight: '2rem' }} placeholder="ابحث بالاسم أو الهاتف..." value={search} onChange={e => handleSearchChange(e.target.value)} />
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
        ) : suppliers.length === 0 ? (
          <div className="empty-state"><Package size={36} color="var(--border)" /><p>لا يوجد موردون</p></div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
            {suppliers.map(s => {
              const bal = Number(s.balance) || 0
              return (
                <div
                  key={s.id}
                  onClick={() => openLedger(s)}
                  style={{
                    padding: '0.7rem 0.85rem', background: 'var(--surface)',
                    borderRadius: 'var(--radius)',
                    border: `1px solid ${ledgerData?.supplier?.id === s.id ? 'var(--primary)' : 'var(--border)'}`,
                    cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '0.6rem',
                    transition: 'border-color .15s',
                  }}
                >
                  <div style={{
                    width: '36px', height: '36px', borderRadius: '50%',
                    background: bal > 0 ? 'rgba(239,68,68,.12)' : 'var(--sup-primary-soft)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    fontSize: '1rem', flexShrink: 0,
                  }}>🏭</div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.name}</div>
                    {s.phone && (
                      <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>📞 {s.phone}</div>
                    )}
                  </div>
                  <div style={{ textAlign: 'left', flexShrink: 0 }}>
                    <div style={{ fontSize: '0.82rem', fontWeight: 700, color: bal > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                      {bal > 0 ? formatCurrency(bal) : '✓ مُسدَّد'}
                    </div>
                  </div>
                </div>
              )
            })}
            <Pagination current={currentPage} total={totalPages} onPage={setCurrentPage} />
          </div>
        )}
      </div>

      {/* ── كشف الحساب ── */}
      {ledgerData && (
        <div className="split-detail">

          <div className="ledger-header">
            <div className="ledger-header-title">
              <button className="btn btn-ghost btn-icon" onClick={() => setLedgerData(null)}>
                <span style={{ transform: 'scaleX(-1)', display: 'inline-block' }}>←</span>
              </button>
              <div style={{ flex: 1, minWidth: 0 }}>
                <h2 style={{ fontSize: '1.1rem', fontWeight: 700, margin: 0, wordBreak: 'break-word' }}>
                  كشف حساب — {ledgerData.supplier.name}
                </h2>
                {ledgerData.supplier.phone && (
                  <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                    📞 {ledgerData.supplier.phone}
                  </span>
                )}
              </div>
            </div>

            <div className="ledger-header-actions">
              <div className="ledger-balance" style={{
                background: ledgerData.balance > 0 ? 'rgba(239,68,68,.08)' : 'var(--sup-primary-soft)',
                border: `1px solid ${ledgerData.balance > 0 ? '#fca5a5' : 'var(--sup-settled-border)'}`,
                borderRadius: 'var(--radius)', padding: '0.4rem 0.9rem', textAlign: 'center',
              }}>
                <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginBottom: '0.1rem' }}>الرصيد المستحق</div>
                <div style={{ fontSize: '1.1rem', fontWeight: 800, color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                  {formatCurrency(Math.abs(ledgerData.balance))}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '0.4rem' }}>
                <button className="btn btn-primary btn-sm" style={{ padding: '0.4rem 0.8rem', justifyContent: 'center' }} onClick={() => setPayModal(true)}>
                  <Plus size={15} /> تسجيل دفعة
                </button>
                <QZPrintButton
                  qzReady={qz.qzReady}
                  printing={qz.printing}
                  onQZPrint={async () => {
                    const b64 = await exportSupplierLedgerPDF(ledgerData.supplier.id, true)
                    if (!b64) return
                    const r = await qz.qzPrintPDF(b64)
                    if (r.ok) toast.success('تمت الطباعة بنجاح')
                    else if (r.error) toast.error('فشل الطباعة: ' + r.error)
                  }}
                  onPickPrinter={() => qz.setShowPrinterPicker(true)}
                  onBrowserPrint={() => exportSupplierLedgerPDF(ledgerData.supplier.id)}
                  label="طباعة وتصدير"
                />
              </div>
            </div>
          </div>

          <SupplierLedgerTable
            ledgerLoading={ledgerLoading}
            ledgerData={ledgerData}
            setEditEntryModal={setEditEntryModal}
            setEditEntryForm={setEditEntryForm}
            onDeleteEntry={handleDeleteEntry}
            onViewInvoice={handleViewInvoice}
          />
        </div>
      )}

      {/* ── modal تسجيل دفعة ── */}
      <SupplierPaymentModal
        payModal={payModal}
        setPayModal={setPayModal}
        ledgerData={ledgerData}
        payType={payType}
        setPayType={setPayType}
        payAmount={payAmount}
        setPayAmount={setPayAmount}
        payDesc={payDesc}
        setPayDesc={setPayDesc}
        handlePayment={handlePayment}
        payLoading={payLoading}
      />

      {/* ── modal تعديل القيد ── */}
      <SupplierEditEntryModal
        editEntryModal={editEntryModal}
        setEditEntryModal={setEditEntryModal}
        editEntryForm={editEntryForm}
        setEditEntryForm={setEditEntryForm}
        handleEditEntry={handleEditEntry}
        editEntryLoading={editEntryLoading}
      />


      {qz.showPrinterPicker && (
        <QZPrinterPicker
          printers={qz.printers}
          selectedPrinter={qz.selectedPrinter}
          onSelect={(name: string) => { qz.handlePrinterSelect(name); toast.success(`تم اختيار الطابعة: ${name}`) }}
          onClose={() => qz.setShowPrinterPicker(false)}
        />
      )}

      {selectedInvoice && (
        <PurchaseReceiptModal
          invoice={selectedInvoice}
          onClose={() => setSelectedInvoice(null)}
        />
      )}
    </div>
  )
}
