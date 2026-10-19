
import { useCallback, useEffect, useRef, useState, type MouseEvent } from 'react'

import { BookOpen, Search, UserPlus } from 'lucide-react'
import useQZPrinter from '../hooks/useQZPrinter'
import { QZPrinterPicker } from '../components/QZPrinterUI'
import useSettingsStore from '../store/settingsStore'
import {
  getCustomers, getCustomer, createCustomer,
  updateCustomer, deleteCustomer, addCustomerPayment, updateCustomerLedgerEntry,
  deleteCustomerLedgerEntry, getSale
} from '../api/endpoints'
import { formatCurrency } from '../utils/formatters'
import toast from 'react-hot-toast'
import useAuthStore from '../store/authStore'
import { useConfirmStore } from '../store/confirmStore'
import CustomerCard from '../components/customers/CustomerCard'
import CustomerFormModal from './customers/components/CustomerFormModal'
import CustomerPaymentModal from './customers/components/CustomerPaymentModal'
import CustomerEditEntryModal from './customers/components/CustomerEditEntryModal'
import { extractApiError } from '../utils/apiError'
import CustomerLedgerPanel from './customers/CustomerLedgerPanel'
import SaleDetailModal from './sales/SaleDetailModal'
import Pagination from '../components/Pagination'

// ── helpers ──────────────────────────────────────────────────────────────────

const emptyForm = { name: '', phone: '', address: '', initial_balance: '', balance_direction: 'debit' }

// ─────────────────────────────────────────────────────────────────────────────
export default function Customers() {
  const [customers, setCustomers]       = useState<Customer[]>([])
  const [loading, setLoading]           = useState(true)
  const [search, setSearch]             = useState('')
  const [serverSearch, setServerSearch] = useState('')
  const [currentPage, setCurrentPage]   = useState(1)
  const [totalPages, setTotalPages]     = useState(1)
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const { user } = useAuthStore()
  const { confirm } = useConfirmStore()

  // كشف الحساب
  const [ledgerData, setLedgerData]     = useState<CustomerLedgerData | null>(null)
  const [ledgerLoading, setLedgerLoading] = useState(false)

  // modal العميل (إضافة / تعديل)
  const [modal, setModal]               = useState<'create' | 'edit' | null>(null)
  const [form, setForm]                 = useState(emptyForm)
  const [editId, setEditId]             = useState<number | null>(null)
  const [saving, setSaving]             = useState(false)

  // modal الدفعة
  const [payModal, setPayModal]         = useState(false)
  const [payAmount, setPayAmount]       = useState('')
  const [payDesc, setPayDesc]           = useState('دفعة نقدية')
  const [payType, setPayType]           = useState('credit')
  const [payLoading, setPayLoading]     = useState(false)

  // modal التعديل للقيد
  const [editEntryModal, setEditEntryModal] = useState<CustomerLedgerRow | null>(null)
  const [editEntryForm, setEditEntryForm] = useState({ type: 'debit', amount: '', description: '' })
  const [editEntryLoading, setEditEntryLoading] = useState(false)

  const [selectedSale, setSelectedSale] = useState<Sale | null>(null)
  const [saleDetailLoading, setSaleDetailLoading] = useState(false)

  const handleViewInvoice = async (invoiceId: number) => {
    setSaleDetailLoading(true)
    try {
      const res = await getSale(invoiceId)
      setSelectedSale(res.data.data)
    } catch (err) {
      toast.error(extractApiError(err, 'فشل تحميل تفاصيل الفاتورة'))
    } finally {
      setSaleDetailLoading(false)
    }
  }

  const handleDeleteEntry = async (entryId: number) => {
    if (!window.confirm('هل أنت متأكد من حذف هذا القيد؟')) return
    try {
      const res = await deleteCustomerLedgerEntry(entryId)
      setLedgerData(res.data.data)
      toast.success('تم حذف القيد بنجاح')
      void load(currentPage, serverSearch) // تحديث رصيد العميل في القائمة
    } catch (err) {
      toast.error(extractApiError(err, 'فشل حذف القيد'))
    }
  }

  const settings = useSettingsStore()

  const qz = useQZPrinter()

  // ── data ──
  const load = useCallback(async (page: number, query: string, signal?: AbortSignal) => {
    setLoading(true)
    try {
      const response = await getCustomers(
        { page, limit: 20, search: query || undefined },
        signal ? { signal } : undefined,
      )
      setCustomers(Array.isArray(response.data.data) ? response.data.data : [])
      setCurrentPage(response.data.pagination?.page ?? page)
      setTotalPages(response.data.pagination?.pages ?? 1)
    }
    catch (err) {
      if (!signal?.aborted) toast.error(extractApiError(err, 'فشل تحميل العملاء'))
    }
    finally {
      if (!signal?.aborted) setLoading(false)
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    void load(currentPage, serverSearch, controller.signal)
    return () => controller.abort()
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
  const openLedger = async (c: Customer) => {
    setLedgerLoading(true)
    setLedgerData({ customer: c, entries: [], balance: 0, total_entries: 0, truncated: false })
    try {
      const res = await getCustomer(c.id)
      setLedgerData(res.data.data)
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل كشف الحساب')) }
    finally { setLedgerLoading(false) }
  }

  // ── CRUD ──
  const openCreate = () => { setForm(emptyForm); setEditId(null); setModal('create') }
  const openEdit   = (c: Customer, e: MouseEvent<HTMLButtonElement>) => {
    e.stopPropagation()
    setForm({
      name: c.name, phone: c.phone || '', address: c.address || '',
      initial_balance: String(Math.abs(c.initial_balance || 0) || ''),
      balance_direction: (c.initial_balance || 0) < 0 ? 'credit' : 'debit',
    })
    setEditId(c.id)
    setModal('edit')
  }

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('الاسم مطلوب'); return }
    setSaving(true)
    try {
      const rawBal = parseFloat(form.initial_balance) || 0
      const payload = {
        name: form.name,
        phone: form.phone,
        address: form.address,
        initial_balance: form.balance_direction === 'credit' ? -Math.abs(rawBal) : Math.abs(rawBal),
      }
      if (modal === 'create') {
        await createCustomer(payload)
        toast.success('تم إضافة العميل')
      } else {
        if (editId === null) throw new Error('معرف العميل غير متاح')
        await updateCustomer(editId, payload)
        toast.success('تم تحديث العميل')
        // تحديث كشف الحساب إذا كان مفتوحاً لنفس العميل
        if (ledgerData?.customer?.id === editId) {
          const res = await getCustomer(editId)
          setLedgerData(res.data.data)
        }
      }
      setModal(null)
      void load(currentPage, serverSearch)
    } catch (err) { toast.error(extractApiError(err, 'حدث خطأ')) }
    finally { setSaving(false) }
  }

  const handleDelete = async (c: Customer, e: MouseEvent<HTMLButtonElement>) => {
    e.stopPropagation()
    if (!(await confirm(`هل تريد حذف العميل "${c.name}"؟`))) return
    try {
      await deleteCustomer(c.id)
      toast.success('تم الحذف')
      if (ledgerData?.customer?.id === c.id) setLedgerData(null)
      void load(currentPage, serverSearch)
    } catch (err) { toast.error(extractApiError(err, 'فشل الحذف')) }
  }

  // ── payment ──
  const handlePayment = async () => {
    if (!ledgerData) return
    const amount = parseFloat(payAmount)
    if (!amount || amount <= 0) { toast.error('أدخل مبلغاً صحيحاً'); return }
    setPayLoading(true)
    try {
      const res = await addCustomerPayment(ledgerData.customer.id, { amount, description: payDesc, type: payType })
      setLedgerData(res.data.data)
      setPayModal(false)
      setPayAmount('')
      setPayDesc('دفعة نقدية')
      setPayType('credit')
      toast.success(`تم تسجيل دفعة ${formatCurrency(amount)}`)
      void load(currentPage, serverSearch) // تحديث رصيد البطاقة
    } catch (err) { toast.error(extractApiError(err, 'فشل التسجيل')) }
    finally { setPayLoading(false) }
  }

  const handleEditEntry = async () => {
    if (!editEntryModal?.id) return
    if (!editEntryForm.amount || Number.isNaN(Number(editEntryForm.amount))) {
      toast.error('الرجاء إدخال مبلغ صحيح')
      return
    }
    setEditEntryLoading(true)
    try {
      const res = await updateCustomerLedgerEntry(editEntryModal.id, {
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

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className={`split-layout ${ledgerData ? 'has-detail' : ''}`}>

      {/* ── القائمة ───────────────────────────────────────────────────────── */}
      <div className={`split-list ${ledgerData ? 'is-split' : 'full-width'}`}>
        {/* رأس */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <h1 style={{ fontSize: '1.2rem', fontWeight: 700, flex: 1 }}>العملاء</h1>
          <button className="btn btn-primary btn-sm" onClick={openCreate}>
            <UserPlus size={15} /> إضافة عميل
          </button>
        </div>

        {/* بحث */}
        <div style={{ position: 'relative' }}>
          <Search size={15} style={{ position: 'absolute', right: '0.65rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input className="input" style={{ paddingRight: '2rem' }} placeholder="ابحث بالاسم أو الهاتف..." value={search} onChange={e => handleSearchChange(e.target.value)} />
        </div>

        {/* قائمة */}
        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
        ) : customers.length === 0 ? (
          <div className="empty-state"><BookOpen size={36} color="var(--border)" /><p>لا يوجد عملاء</p></div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
            {customers.map(c => (
              <CustomerCard
                key={c.id}
                customer={c}
                active={ledgerData?.customer?.id === c.id}
                onClick={() => openLedger(c)}
                onEdit={(e) => openEdit(c, e)}
                onDelete={user?.role === 'admin' ? (e) => handleDelete(c, e) : undefined}
              />
            ))}
            <Pagination current={currentPage} total={totalPages} onPage={setCurrentPage} />
          </div>
        )}
      </div>

      {/* ── كشف الحساب ───────────────────────────────────────────────────── */}
      <CustomerLedgerPanel
        ledgerData={ledgerData}
        setLedgerData={setLedgerData}
        qz={qz}
        setPayModal={setPayModal}
        ledgerLoading={ledgerLoading}
        setEditEntryModal={setEditEntryModal}
        setEditEntryForm={setEditEntryForm}
        onDeleteEntry={handleDeleteEntry}
        onViewInvoice={handleViewInvoice}
      />

      {/* ── detail sale modal ────────────────────────────────────────────── */}
      {selectedSale && (
        <SaleDetailModal
          selected={selectedSale}
          detailLoading={saleDetailLoading}
          deleting={false}
          isAdmin={user?.role === 'admin'}
          settings={settings}
          qz={qz}
          onClose={() => setSelectedSale(null)}
          onReturnToCart={() => {}}
          onDelete={() => {}}
        />
      )}

      {/* ── modal إضافة / تعديل عميل ────────────────────────────────────── */}
      <CustomerFormModal
        modal={modal}
        setModal={setModal}
        form={form}
        setForm={setForm}
        handleSave={handleSave}
        saving={saving}
      />

      {/* ── modal تسجيل دفعة ─────────────────────────────────────────────── */}
      <CustomerPaymentModal
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

      {/* ── modal تعديل القيد ────────────────────────────────────────────── */}
      <CustomerEditEntryModal
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
    </div>
  )
}


