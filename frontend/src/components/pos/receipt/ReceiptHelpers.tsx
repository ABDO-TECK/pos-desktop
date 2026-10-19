import React from 'react'

export function InfoRow({ label, value }: { label: React.ReactNode, value: React.ReactNode }) {
    return (
        <div style={{ display: 'flex', justifyContent: 'space-between', margin: '0.8mm 0', fontSize: '3mm' }}>
            <span style={{ fontWeight: 900, whiteSpace: 'nowrap' }}>{label}:</span>
            <span style={{ textAlign: 'left' }}>{value}</span>
        </div>
    )
}

export function Th({ children, align = 'center' }: { children: React.ReactNode, align?: 'left' | 'right' | 'center' }) {
    return (
        <th style={{
            padding: '0.8mm 1mm', fontSize: '2.8mm', fontWeight: 900,
            border: '1pt solid #000', textAlign: align,
            background: '#fff', color: '#000', verticalAlign: 'middle',
        }}>
            {children}
        </th>
    )
}

export function Td({ children, align = 'center', isName }: { children: React.ReactNode, align?: 'left' | 'right' | 'center', isName?: boolean }) {
    return (
        <td style={{
            padding: '0.8mm 1mm', fontSize: '2.8mm', fontWeight: 700,
            border: '1pt solid #000', textAlign: align,
            background: '#fff', color: '#000', verticalAlign: 'middle',
            maxWidth: isName ? '25mm' : 'auto', wordBreak: isName ? 'break-word' : 'normal'
        }}>
            {children}
        </td>
    )
}

export function TotalLine({ label, value, grand }: { label: React.ReactNode, value: React.ReactNode, grand?: boolean }) {
    return (
        <div style={{
            display: 'flex', justifyContent: 'space-between',
            margin: grand ? '0.5mm 0 0.8mm 0' : '0.8mm 0',
            padding: grand ? '1mm 0' : '0',
            fontSize: grand ? '4mm' : '3mm',
            fontWeight: grand ? 900 : 700,
            color: '#000',
            borderTop: grand ? '1.5pt solid #000' : 'none',
            borderBottom: grand ? '1.5pt solid #000' : 'none',
        }}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    )
}
