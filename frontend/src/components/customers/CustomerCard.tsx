import type { MouseEventHandler } from 'react';
import { Phone, Trash2, Edit2, ChevronRight } from 'lucide-react';
import { formatCurrency } from '../../utils/formatters';
import useSettingsStore from '../../store/settingsStore';

interface CustomerCardProps {
  customer: Customer;
  active: boolean;
  onClick: MouseEventHandler<HTMLDivElement>;
  onEdit: MouseEventHandler<HTMLButtonElement>;
  onDelete?: MouseEventHandler<HTMLButtonElement>;
}

export default function CustomerCard({ customer, active, onClick, onEdit, onDelete }: CustomerCardProps) {
  const balance = Number(customer.balance) || 0;
  return (
    <div
      onClick={onClick}
      style={{
        padding: '0.7rem 0.85rem',
        background: 'var(--surface)',
        borderRadius: 'var(--radius)',
        border: `1px solid ${active ? 'var(--primary)' : 'var(--border)'}`,
        cursor: 'pointer',
        display: 'flex', alignItems: 'center', gap: '0.6rem',
        transition: 'border-color .15s',
      }}
    >
      {/* أيقونة */}
      <div style={{
        width: '36px', height: '36px', borderRadius: '50%',
        background: balance > 0 ? 'rgba(239,68,68,.12)' : 'rgba(34,197,94,.12)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: '1rem', flexShrink: 0,
      }}>👤</div>

      {/* بيانات */}
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{customer.name}</div>
        {customer.phone && (
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
            <Phone size={10} style={{ verticalAlign: 'middle' }} /> {customer.phone}
          </div>
        )}
      </div>

      {/* رصيد */}
      <div style={{ textAlign: 'left', flexShrink: 0 }}>
        <div style={{ fontSize: '0.82rem', fontWeight: 700, color: balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
          {balance > 0 ? formatCurrency(balance) : '✓ مُسدَّد'}
        </div>
        {useSettingsStore.getState().loyaltyEnabled && (
          <div style={{ fontSize: '0.7rem', color: 'var(--warning)', marginTop: '2px' }}>
            ⭐ {customer.loyalty_points || 0} نقطة
          </div>
        )}
      </div>

      {/* أزرار */}
      <div style={{ display: 'flex', gap: '0.2rem', flexShrink: 0 }} onClick={e => e.stopPropagation()}>
        <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem' }} onClick={onEdit}><Edit2 size={14} /></button>
        {onDelete && <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem', color: 'var(--danger)' }} onClick={onDelete}><Trash2 size={14} /></button>}
      </div>

      <ChevronRight size={16} color="var(--text-muted)" style={{ flexShrink: 0, transform: 'scaleX(-1)' }} />
    </div>
  );
}
