import { useState } from 'react'
import { getCustomerOption, searchCustomers } from '../../../api/endpoints'
import { formatCurrency } from '../../../utils/formatters'
import SearchableEntitySelect from '../../SearchableEntitySelect'
import NumericInput from '../../forms/NumericInput'
import styles from './CustomerSection.module.css'
interface Props {
  isCreditSale: boolean
  rebillingCustomerId: number | null
  computedTotal: number
  amountDue: number
  deposit: number
  onDepositChange: (val: number) => void
  onCustomerSelect: (customerId: number | null, newCustomer: NewCustomer | null) => void
}

interface NewCustomer {
  name: string
  phone: string
  address: string
}

export default function CustomerSection({ isCreditSale, rebillingCustomerId, computedTotal, amountDue, deposit, onDepositChange, onCustomerSelect }: Props) {
  const [customerMode, setCustomerMode] = useState('existing')
  const [selectedCustomerId, setSelectedCustomerId] = useState(rebillingCustomerId ? String(rebillingCustomerId) : '')
  const [newCust, setNewCust] = useState({ name: '', phone: '', address: '' })

  const notifyNewCustomer = (customer: NewCustomer) => {
    onCustomerSelect(
      null,
      customer.name.trim()
        ? { ...customer, name: customer.name.trim() }
        : null,
    )
  }

  const handleModeChange = (mode: 'existing' | 'new') => {
    setCustomerMode(mode)
    if (mode === 'existing') {
      onCustomerSelect(selectedCustomerId ? Number(selectedCustomerId) : null, null)
    } else {
      notifyNewCustomer(newCust)
    }
  }

  const handleExistingCustomerChange = (value: string) => {
    setSelectedCustomerId(value)
    onCustomerSelect(value ? Number(value) : null, null)
  }

  const handleNewCustomerChange = (field: keyof NewCustomer, value: string) => {
    const nextCustomer = { ...newCust, [field]: value }
    setNewCust(nextCustomer)
    notifyNewCustomer(nextCustomer)
  }

  return (
    <div style={{
      border: isCreditSale ? '1px solid rgba(239,68,68,.3)' : '1px solid var(--border)',
      borderRadius: 'var(--radius)',
      background: isCreditSale ? 'rgba(239,68,68,.03)' : 'var(--bg)',
      padding: '0.85rem', marginBottom: '1rem',
      display: 'flex', flexDirection: 'column', gap: '0.65rem',
    }}>
      <div style={{ fontSize: '0.85rem', fontWeight: 700, color: isCreditSale ? 'var(--danger)' : 'var(--primary)' }}>
        {isCreditSale ? '⏳ بيع بالآجل (مطلوب)' : '👤 تسجيل الفاتورة على عميل (اختياري)'}
      </div>

      <div style={{ display: 'flex', gap: '0.4rem' }}>
        {(['existing', 'new'] as const).map(mode => (
          <button key={mode} type="button" onClick={() => handleModeChange(mode)}
            className={`${styles.custModeBtn} ${customerMode === mode ? styles.active : ''}`}>
            {mode === 'existing' ? '👤 عميل موجود' : '➕ عميل جديد'}
          </button>
        ))}
      </div>

      {customerMode === 'existing' && (
        <SearchableEntitySelect<Customer>
          value={selectedCustomerId}
          onChange={(value) => handleExistingCustomerChange(value)}
          searchOptions={searchCustomers}
          loadOption={getCustomerOption}
          searchPlaceholder="ابحث عن عميل بالاسم أو الهاتف..."
          emptyLabel="— اختر عميلاً —"
          loadingLabel="جارٍ التحميل..."
          getOptionLabel={(customer) => (
            `${customer.name}${customer.phone ? ` — ${customer.phone}` : ''}${Number(customer.balance) > 0 ? ` (رصيد: ${formatCurrency(customer.balance)})` : ''}`
          )}
        />
      )}

      {customerMode === 'new' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <input className="input" placeholder="اسم العميل *" value={newCust.name}
            onChange={e => handleNewCustomerChange('name', e.target.value)} />
          <input className="input" placeholder="رقم الهاتف (اختياري)" value={newCust.phone}
            onChange={e => handleNewCustomerChange('phone', e.target.value)} />
          <input className="input" placeholder="العنوان (اختياري)" value={newCust.address}
            onChange={e => handleNewCustomerChange('address', e.target.value)} />
        </div>
      )}

      {isCreditSale && (
        <div>
          <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.25rem' }}>
            العربون / المبلغ المقدَّم (ج.م) — اختياري
          </label>
          <NumericInput className="input" min={0} max={computedTotal} step="0.5"
            placeholder="0.00" value={deposit || ''}
            onChange={e => onDepositChange(Math.min(parseFloat(e.target.value) || 0, computedTotal))} />
          {amountDue > 0 && (
            <div style={{ fontSize: '0.78rem', color: 'var(--danger)', marginTop: '0.25rem', fontWeight: 600 }}>
              ⬅ المتبقي على الذمة: {formatCurrency(amountDue)}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
