import { CreditCard, Banknote, Smartphone, Wallet, Clock } from 'lucide-react'
import styles from './PaymentMethodSelector.module.css'

export const PAYMENT_METHODS = [
  { id: 'cash',          label: 'نقدي',          icon: <Banknote size={16}/>,     cashInput: true  },
  { id: 'card',          label: 'بطاقة',          icon: <CreditCard size={16}/>,   cashInput: false },
  { id: 'vodafone_cash', label: 'فودافون كاش',   icon: <Smartphone size={16}/>,   cashInput: false },
  { id: 'instapay',      label: 'انستاباي',       icon: <Wallet size={16}/>,       cashInput: false },
  { id: 'other_wallet',  label: 'محفظة أخرى',    icon: <Wallet size={16}/>,       cashInput: false },
  { id: 'credit',        label: 'آجل',            icon: <Clock size={16}/>,        cashInput: false },
]

interface Props {
  paymentMethod: string
  onSelect: (method: string) => void
}

export default function PaymentMethodSelector({ paymentMethod, onSelect }: Props) {
  return (
    <div style={{ marginBottom: '1rem' }}>
      <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.5rem' }}>طريقة الدفع</label>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '0.5rem' }}>
        {PAYMENT_METHODS.map(m => (
          <PayBtn key={m.id} active={paymentMethod === m.id}
            onClick={() => onSelect(m.id)} icon={m.icon} label={m.label}
            isCredit={m.id === 'credit'} />
        ))}
      </div>
    </div>
  )
}

function PayBtn({ active, onClick, icon, label, isCredit }: { active: boolean, onClick: () => void, icon: React.ReactNode, label: string, isCredit: boolean }) {
  const activeClass = active ? (isCredit ? styles.activeCredit : styles.activeNormal) : '';
  return (
    <button onClick={onClick} className={`${styles.payBtn} ${activeClass}`}>
      {icon} {label}
    </button>
  )
}
