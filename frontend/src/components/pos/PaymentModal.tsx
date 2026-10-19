import { useState, useEffect, useRef } from 'react'
import { X, CheckCircle2, Clock, DollarSign, User, Truck } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useSettingsStore from '../../store/settingsStore'
import { formatCurrency, formatNumber, roundCurrency } from '../../utils/formatters'
import { createSale } from '../../api/endpoints'
import { savePendingSale } from '../../utils/idb'
import toast from 'react-hot-toast'
import PaymentSummary from './payment/PaymentSummary'
import PaymentMethodSelector, { PAYMENT_METHODS } from './payment/PaymentMethodSelector'
import CustomerSection from './payment/CustomerSection'
import styles from './PaymentModal.module.css'
import { extractApiError } from '../../utils/apiError'
import useAuthStore from '../../store/authStore'
import NumericInput from '../forms/NumericInput'

interface PaymentModalProps {
  onClose: () => void
  onSuccess: (invoice: NonNullable<Sale['invoice']>, change: number) => void
}

export default function PaymentModal({ onClose, onSuccess }: PaymentModalProps) {
  const {
    items,
    setPaymentMethod,
    setAmountPaid,
    setDiscount,
    paymentMethod,
    rebillingInvoiceId,
    rebillingCustomerId,
    rebillingAmountPaid,
    rebillingPaymentMethod,
    rebillingShippingCost,
  } = useCartStore()
  const { taxEnabled, taxRate } = useSettingsStore()
  const authenticatedUser = useAuthStore((state) => state.user)

  const [loading, setLoading]                 = useState(false)
  const [localDiscount, setLocalDiscount]     = useState(0)
  const [localAmountPaid, setLocalAmountPaid] = useState(0)
  const idempotencyKey = useRef(globalThis.crypto.randomUUID())

  // ── آجل states ──────────────────────────────────────────────
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(rebillingCustomerId ?? null)
  const [deposit, setDeposit]             = useState(0)           // العربون
  const [newCustomerData, setNewCustomerData] = useState<NewCustomerPayload | null>(null)
  const [customerNameForReceipt, setCustomerNameForReceipt] = useState('')

  // ── delivery states ──────────────────────────────────────────────
  const [driverName, setDriverName] = useState('')
  const [shippingCost, setShippingCost] = useState(Math.max(0, rebillingShippingCost))
  const [deliveryDate, setDeliveryDate] = useState(new Date().toISOString().split('T')[0])
  const [deliveryNotes, setDeliveryNotes] = useState('')
  const [activeTab, setActiveTab] = useState<'payment' | 'customer' | 'delivery'>('payment')

  const handleCustomerSelect = (customerId: number | null, newCustomer: NewCustomerPayload | null) => {
    setSelectedCustomerId(customerId)
    setNewCustomerData(newCustomer)
  }

  const isCreditSale = paymentMethod === 'credit'

  const rate             = taxEnabled ? (taxRate / 100) : 0
  const computedSubtotal = items.reduce((s, i) => s + i.subtotal, 0)
  const clampedDiscount  = Math.min(localDiscount, computedSubtotal)
  const computedTaxable  = computedSubtotal - clampedDiscount
  const computedTax      = roundCurrency(computedTaxable * rate)
  const computedTotal    = roundCurrency(computedTaxable + computedTax + shippingCost)
  // An invoice edit replaces its payment details. Preserve an earlier payment
  // only while the original payment method remains selected. For example,
  // changing cash to credit must not turn the old cash amount into a deposit.
  const appliedPreviousPayment = rebillingInvoiceId && paymentMethod === rebillingPaymentMethod
    ? Math.max(0, rebillingAmountPaid)
    : 0
  const computedChange   = Math.max(0, localAmountPaid - (computedTotal - appliedPreviousPayment))
  const remainingToPay   = roundCurrency(Math.max(0, computedTotal - appliedPreviousPayment))
  const amountDue        = isCreditSale ? Math.max(0, computedTotal - deposit - appliedPreviousPayment) : 0

  const currentMethod = PAYMENT_METHODS.find(m => m.id === paymentMethod) ?? PAYMENT_METHODS[0]

  useEffect(() => { setLocalAmountPaid(remainingToPay) }, [remainingToPay])

  // Customer section is always rendered in its tab

  // تحميل العملاء عند الحاجة (إما آجل أو أراد المستخدم ربط الفاتورة)
  // تم نقله إلى CustomerSection

  // إعادة ضبط deposit عند التغيير
  useEffect(() => { if (!isCreditSale) setDeposit(0) }, [isCreditSale])

  // Enter shortcut for checkout
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter' && !loading) {
        // Prevent default double triggering if a button is focused
        if (e.target instanceof HTMLElement && e.target.tagName !== 'BUTTON') {
          e.preventDefault()
          handleCheckout('completed')
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  })

  const handleCheckout = async (status: SaleCreatePayload['status'] = 'completed') => {
    if (items.length === 0) return

    if (currentMethod.cashInput && localAmountPaid < remainingToPay) {
      toast.error('المبلغ المدفوع أقل من الإجمالي')
      return
    }

    // التحقق من بيانات العميل (مطلوب في الآجل، واختياري في الكاش)
    const customerId = selectedCustomerId
    const newCustomer = newCustomerData
    if (isCreditSale && !customerId && !newCustomer) {
      toast.error('اختر عميلاً أو أنشئ جديداً')
      return
    }

    setDiscount(clampedDiscount)
    const finalAmountPaid = status === 'reserved' 
      ? (isCreditSale ? deposit + appliedPreviousPayment : 0)
      : (isCreditSale ? (deposit + appliedPreviousPayment) : (currentMethod.cashInput ? (localAmountPaid + appliedPreviousPayment) : computedTotal))
    
    setAmountPaid(finalAmountPaid)

    const salePayload: SaleCreatePayload = {
      idempotency_key: idempotencyKey.current,
      items:          items.map(i => ({ product_id: i.id, quantity: i.quantity, price: i.price })),
      discount:       clampedDiscount,
      payment_method: paymentMethod,
      amount_paid:    finalAmountPaid,
      driver_name:    driverName.trim() || undefined,
      shipping_cost:  shippingCost,
      delivery_date:  deliveryDate || undefined,
      delivery_notes: deliveryNotes.trim() || undefined,
      ...(customerId ? { customer_id: customerId } : {}),
      ...(newCustomer ? { new_customer: newCustomer } : {}),
      ...(isCreditSale ? { deposit } : {}),
      ...(rebillingInvoiceId ? { invoice_id: rebillingInvoiceId } : {}),
      status,
    }

    setLoading(true)
    try {
      const res = await createSale(salePayload)
      const { invoice, low_stock_alerts } = res.data.data
      if (!invoice) {
        throw new Error('Sale response did not include an invoice')
      }
      toast.success(
        status === 'reserved'
          ? `تم حجز الفاتورة بنجاح`
          : rebillingInvoiceId
            ? `تم تحديث الفاتورة #${formatNumber(rebillingInvoiceId)}`
            : isCreditSale
              ? `تم تسجيل البيع الآجل — المتبقي ${formatCurrency(amountDue)}`
              : 'تمت عملية البيع بنجاح!',
        { duration: 3000 }
      )
      if (low_stock_alerts && low_stock_alerts.length > 0) {
        low_stock_alerts.forEach((p) =>
          toast(`تحذير: ${p.name} — كمية منخفضة (${formatNumber(p.quantity)})`, { icon: '⚠️', duration: 5000 })
        )
      }
      // The receipt name is intentionally print-only. Do not fall back to a
      // selected customer's account name when this optional field is empty.
      onSuccess({
        ...invoice,
        customer_name: customerNameForReceipt.trim() || undefined,
      }, isCreditSale ? 0 : computedChange)
    } catch (err) {
      if (!navigator.onLine) {
        if (!authenticatedUser || authenticatedUser.branch_id <= 0) {
          toast.error('Cannot save this sale offline because the current user or branch is unavailable')
          return
        }
        await savePendingSale({ ...salePayload }, {
          ownerUserId: authenticatedUser.id,
          branchId: authenticatedUser.branch_id,
        })
        toast('لا يوجد إنترنت — تم حفظ العملية للمزامنة لاحقًا', { icon: '📴', duration: 5000 })
        onClose()
      } else {
        toast.error(extractApiError(err, 'فشل في إتمام البيع'))
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '520px', width: '90%' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.15rem', fontWeight: 700 }}>
            {rebillingInvoiceId ? `تحديث فاتورة #${formatNumber(rebillingInvoiceId)}` : 'إتمام الدفع'}
          </h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {/* Summary */}
        <PaymentSummary
          subtotal={computedSubtotal}
          discount={clampedDiscount}
          taxEnabled={taxEnabled}
          taxRate={taxRate}
          tax={computedTax}
          shippingCost={shippingCost}
          total={computedTotal}
          rebillingAmountPaid={appliedPreviousPayment}
          remainingToPay={remainingToPay}
          isCreditSale={isCreditSale}
          deposit={deposit}
          amountDue={amountDue}
        />

        {/* Tab Header */}
        <div style={{
          display: 'flex',
          borderBottom: '1px solid var(--border)',
          marginBottom: '1rem',
          gap: '0.25rem',
          background: 'var(--bg)',
          borderRadius: 'var(--radius)',
          padding: '0.2rem',
        }}>
          <button
            onClick={() => setActiveTab('payment')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'payment' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'payment' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <DollarSign size={16} />
            <span>الدفع والخصم</span>
          </button>
          <button
            onClick={() => setActiveTab('customer')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'customer' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'customer' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <User size={16} />
            <span>العميل والآجل</span>
          </button>
          <button
            onClick={() => setActiveTab('delivery')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'delivery' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'delivery' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <Truck size={16} />
            <span>التوصيل والشحن</span>
          </button>
        </div>

        {/* Tab Body */}
        <div>
          {activeTab === 'payment' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', marginBottom: '1.25rem' }}>
              {/* Discount */}
              <div>
                <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
                  الخصم (ج.م)
                </label>
                <NumericInput min={0} max={computedSubtotal} step="0.5" className="input"
                  value={localDiscount} onChange={e => setLocalDiscount(parseFloat(e.target.value) || 0)} />
              </div>

              {/* Payment method */}
              <PaymentMethodSelector paymentMethod={paymentMethod} onSelect={setPaymentMethod} />

              {/* Cash input */}
              {currentMethod.cashInput && !isCreditSale && (
                <div>
                  <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>المبلغ المدفوع (ج.م)</label>
                  <NumericInput min={0} step="0.5" className="input input-lg"
                    value={localAmountPaid} onChange={e => setLocalAmountPaid(parseFloat(e.target.value) || 0)} />
                </div>
              )}

              {currentMethod.cashInput && !isCreditSale && (
                <div 
                  className={computedChange > 0 ? styles.changeBoxActive : ""}
                  style={{
                    background: computedChange > 0 ? undefined : 'var(--bg)',
                    borderRadius: 'var(--radius)', padding: '0.75rem 1rem',
                    display: 'flex', justifyContent: 'space-between',
                    fontWeight: 700, fontSize: '1.05rem',
                  }}
                >
                  <span>الباقي</span>
                  <span style={{ color: computedChange > 0 ? 'inherit' : 'var(--primary)' }}>{formatCurrency(computedChange)}</span>
                </div>
              )}
            </div>
          )}

          {activeTab === 'customer' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', marginBottom: '1.25rem', minHeight: '180px' }}>
              <CustomerSection
                isCreditSale={isCreditSale}
                rebillingCustomerId={rebillingCustomerId}
                computedTotal={computedTotal}
                amountDue={amountDue}
                deposit={deposit}
                onDepositChange={setDeposit}
                onCustomerSelect={handleCustomerSelect}
              />
              <div className="form-group" style={{ marginTop: '0.25rem' }}>
                <label htmlFor="invoice-customer-name" style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
                  اسم العميل على الفاتورة (اختياري)
                </label>
                <input
                  id="invoice-customer-name"
                  type="text"
                  className="input"
                  maxLength={150}
                  autoComplete="off"
                  placeholder="مثال: أحمد محمد"
                  value={customerNameForReceipt}
                  onChange={(e) => setCustomerNameForReceipt(e.target.value)}
                />
                <small style={{ display: 'block', marginTop: '0.25rem', color: 'var(--text-muted)', fontSize: '0.75rem' }}>
                  يظهر الاسم في الفاتورة عند الطباعة فقط
                </small>
              </div>
            </div>
          )}

          {activeTab === 'delivery' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', marginBottom: '1.25rem' }}>
              <div className="resp-2col" style={{ gap: '0.75rem' }}>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>اسم السائق</label>
                  <input
                    type="text"
                    className="input"
                    placeholder="مثال: محمد أحمد..."
                    value={driverName}
                    onChange={(e) => setDriverName(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>تكلفة الشحن (ج.م)</label>
                  <NumericInput
                    min={0}
                    step="0.5"
                    className="input"
                    placeholder="0.00"
                    value={shippingCost || ''}
                    onChange={(e) => setShippingCost(Math.max(0, parseFloat(e.target.value) || 0))}
                  />
                </div>
              </div>
              <div className="resp-2col" style={{ gap: '0.75rem' }}>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>تاريخ التسليم</label>
                  <input
                    type="date"
                    className="input"
                    value={deliveryDate}
                    onChange={(e) => setDeliveryDate(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>ملاحظات التسليم</label>
                  <input
                    type="text"
                    className="input"
                    placeholder="طريقة الشحن، العنوان التفصيلي للتوصيل..."
                    value={deliveryNotes}
                    onChange={(e) => setDeliveryNotes(e.target.value)}
                  />
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Checkout button */}
        <div style={{ display: 'flex', gap: '0.5rem', width: '100%', borderTop: '1px solid var(--border)', paddingTop: '1rem' }}>
          <button className={`btn ${isCreditSale ? 'btn-danger' : 'btn-primary'} btn-lg`}
            style={{ flex: 2, justifyContent: 'center', fontSize: '1.05rem' }}
            onClick={() => handleCheckout('completed')} disabled={loading}>
            {loading ? <span className="spinner" /> : <CheckCircle2 size={20} />}
            {rebillingInvoiceId ? 'حفظ التعديل — '
              : isCreditSale ? 'تأكيد الآجل — '
              : 'تأكيد البيع — '}
            {formatCurrency(remainingToPay)}
            <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>Enter</kbd>
          </button>
          
          <button className="btn btn-warning btn-lg"
            style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.4rem', fontSize: '1rem' }}
            onClick={() => handleCheckout('reserved')} disabled={loading}>
            <Clock size={18} />
            حجز
          </button>
        </div>
      </div>
    </div>
  )
}


