import React from 'react'

interface TotalRowProps {
  label: React.ReactNode
  value: React.ReactNode
  bold?: boolean
  green?: boolean
  danger?: boolean
}

export default function TotalRow({ label, value, bold, green, danger }: TotalRowProps) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
      <span style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span style={{
        fontWeight: bold ? 700 : 500,
        color: green ? 'var(--primary-d)' : danger ? 'var(--danger)' : 'var(--text)',
        fontSize: bold ? '1rem' : undefined,
      }}>{value}</span>
    </div>
  )
}
