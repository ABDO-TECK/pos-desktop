import React from 'react'

export interface IconBadgeProps {
  icon: React.ComponentType<{ size?: number; color?: string; className?: string }>;
  color?: 'primary' | 'secondary' | 'danger' | 'warning' | 'info' | 'muted' | 'default';
  shape?: 'circle' | 'square' | 'rounded';
  size?: number;
  badgeSize?: number;
  style?: React.CSSProperties;
}

export default function IconBadge({
  icon: IconComponent,
  color = 'default',
  shape = 'rounded',
  size = 14,
  badgeSize = 26,
  style
}: IconBadgeProps) {
  
  const colorMap = {
    primary: {
      color: 'var(--primary)',
      bg: 'rgba(34, 197, 94, 0.12)'
    },
    secondary: {
      color: 'var(--secondary)',
      bg: 'rgba(59, 130, 246, 0.12)'
    },
    danger: {
      color: 'var(--danger)',
      bg: 'rgba(239, 68, 68, 0.12)'
    },
    warning: {
      color: 'var(--warning)',
      bg: 'rgba(245, 158, 11, 0.12)'
    },
    info: {
      color: 'var(--secondary)',
      bg: 'rgba(59, 130, 246, 0.12)'
    },
    muted: {
      color: 'var(--text-muted)',
      bg: 'var(--bg)'
    },
    default: {
      color: 'var(--text)',
      bg: 'var(--bg)'
    }
  }

  const selectedColor = colorMap[color] || colorMap.default;
  const borderRadius = shape === 'circle' ? '50%' : shape === 'square' ? '4px' : '8px'

  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        width: `${badgeSize}px`,
        height: `${badgeSize}px`,
        borderRadius: borderRadius,
        background: selectedColor.bg,
        color: selectedColor.color,
        flexShrink: 0,
        ...style
      }}
    >
      <IconComponent size={size} />
    </span>
  )
}
