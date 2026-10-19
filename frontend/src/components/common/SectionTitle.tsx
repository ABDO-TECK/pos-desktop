import React from 'react'

interface SectionTitleProps {
  icon: React.ReactNode
  label: string
}

export default function SectionTitle({ icon, label }: SectionTitleProps) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700, color: 'var(--primary-d)', fontSize: '0.95rem' }}>
      {icon} {label}
    </div>
  )
}
