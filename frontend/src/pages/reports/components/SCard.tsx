import React from 'react'
import IconBadge from '../../../components/common/IconBadge'

export function profitColor(v: any) {
  const n = Number(v)
  if (Number.isNaN(n)) return undefined
  if (n < 0) return 'var(--danger)'
  if (n > 0) return 'var(--secondary)'
  return 'var(--text-muted)'
}

interface SCardProps {
  label: React.ReactNode
  value: React.ReactNode
  color?: string
  icon?: any // Lucide component or ReactNode
  title?: string
}

export function SCard({ label, value, color, icon, title: tip }: SCardProps) {
  const renderIcon = () => {
    if (!icon) return null
    if (typeof icon === 'function' || (typeof icon === 'object' && icon !== null)) {
      let badgeColor: any = 'default'
      if (color === 'var(--primary)' || color === 'var(--primary-d)') badgeColor = 'primary'
      else if (color === 'var(--secondary)' || color === 'var(--secondary-d)') badgeColor = 'secondary'
      else if (color === 'var(--danger)' || color === 'var(--danger-d)') badgeColor = 'danger'
      else if (color === 'var(--warning)') badgeColor = 'warning'
      
      return <IconBadge icon={icon} color={badgeColor} shape="rounded" size={16} badgeSize={32} />
    }
    return <div style={{ fontSize: '1.4rem' }}>{icon}</div>
  }

  return (
    <div className="stat-card" title={tip} style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', padding: '0.9rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }}>
        <span className="stat-label" style={{ fontSize: '0.78rem', color: 'var(--text-muted)', fontWeight: 600 }}>{label}</span>
        {renderIcon()}
      </div>
      <div className="stat-value" style={{ color: color || 'var(--text)', fontSize: '1.3rem', fontWeight: 700, marginTop: '0.15rem' }}>{value}</div>
    </div>
  )
}
