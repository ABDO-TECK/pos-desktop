import { formatCurrency, formatPercent } from '../../../utils/formatters'

interface Props {
  subtotal: number
  discount: number
  taxEnabled: boolean
  taxRate: number
  tax: number
  shippingCost: number
  total: number
  rebillingAmountPaid: number
  remainingToPay: number
  isCreditSale: boolean
  deposit: number
  amountDue: number
}

export default function PaymentSummary(props: Props) {
  const { subtotal, discount, taxEnabled, taxRate, tax, shippingCost, total, rebillingAmountPaid, remainingToPay, isCreditSale, deposit, amountDue } = props
  return (
    <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', marginBottom: '1rem' }}>
      <Row label="المجموع الجزئي" value={formatCurrency(subtotal)} />
      {discount > 0 && <Row label="الخصم" value={`- ${formatCurrency(discount)}`} />}
      {taxEnabled && <Row label={`ضريبة (${formatPercent(taxRate)})`} value={formatCurrency(tax)} />}
      {shippingCost > 0 && <Row label="تكلفة الشحن" value={formatCurrency(shippingCost)} />}
      <div style={{ borderTop: '2px solid var(--border)', margin: '0.5rem 0' }} />
      <Row label="الإجمالي" value={formatCurrency(total)} bold />
      {rebillingAmountPaid > 0 && <Row label="مدفوع مسبقاً (عربون)" value={`- ${formatCurrency(rebillingAmountPaid)}`} color="var(--primary)" />}
      {rebillingAmountPaid > 0 && <Row label="المتبقي للدفع" value={formatCurrency(remainingToPay)} bold color="var(--danger)" />}
      {isCreditSale && deposit > 0 && <Row label="عربون" value={`- ${formatCurrency(deposit)}`} />}
      {isCreditSale && <Row label="المتبقي آجلاً" value={formatCurrency(amountDue)} bold color={amountDue > 0 ? 'var(--danger)' : 'var(--primary)'} />}
    </div>
  )
}

export function Row({ label, value, bold, color }: { label: React.ReactNode, value: React.ReactNode, bold?: boolean, color?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.3rem 0', fontWeight: bold ? 700 : 400, fontSize: bold ? '1rem' : '0.9rem' }}>
      <span>{label}</span>
      <span style={{ color: color || 'inherit' }}>{value}</span>
    </div>
  )
}
