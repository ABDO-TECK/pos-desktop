/**
 * QZPrinterUI — Reusable UI components for QZ Tray integration.
 * Includes: QZStatusBar, QZPrinterPicker, and QZPrintButton.
 */
import { Printer, Settings, X } from 'lucide-react'
import type { CSSProperties, MouseEvent } from 'react'
import styles from './QZPrinterUI.module.css'

interface QZStatusBarProps {
    status: string
    printer: string
    onPickPrinter?: () => void
    remoteError?: { message: string; certUrl: string } | null
    onRetry?: () => void
}

interface QZPrinterPickerProps {
    printers: readonly string[]
    selectedPrinter: string
    onSelect: (printer: string) => void
    onClose: () => void
}

type PrintHandler = {
    handlePrint(paperSize?: string): void | Promise<void>
}['handlePrint']

interface QZPrintButtonProps {
    qzReady: boolean
    printing: boolean
    onQZPrint: PrintHandler
    onBrowserPrint: PrintHandler
    onPickPrinter?: () => void
    multiSize?: boolean
    label?: string
    size?: string
    style?: CSSProperties
}

/** Status indicator bar */
export function QZStatusBar({ status, printer, onPickPrinter, remoteError, onRetry }: QZStatusBarProps) {
    const cfg: Record<string, { bg: string; text: string; label: string }> = {
        idle:        { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray: جاري التحميل…' },
        connecting:  { bg: '#fef9c3', text: '#854d0e', label: 'QZ Tray: جاري الاتصال…' },
        ready:       { bg: '#dcfce7', text: '#166534',
                       label: printer
                           ? `QZ ✓ — ${printer.length > 28 ? printer.slice(0,28)+'…' : printer}`
                           : 'QZ Tray: متصل — انقر لاختيار الطابعة' },
        error:       { bg: '#fee2e2', text: '#991b1b', label: 'QZ Tray: فشل الاتصال' },
        unavailable: { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray غير مثبت' },
    }
    const { bg, text, label } = cfg[status] ?? cfg.idle

    // عند فشل الاتصال بـ QZ Tray — إظهار تعليمات واضحة
    if (status === 'error' && remoteError) {
        return (
            <div style={{
                padding: '0.6rem 0.75rem',
                background: '#fef3c7', color: '#92400e', borderRadius: 'var(--radius)',
                fontSize: '0.72rem', fontWeight: 600,
                display: 'flex', flexDirection: 'column', gap: '0.35rem',
            }}>
                <span> لتفعيل الطباعة على هذا الجهاز:</span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>
                    ① تأكد أن <strong>QZ Tray</strong> مثبت ويعمل على <strong>هذا الجهاز</strong>
                </span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>
                    ② افتح{' '}
                    <a href="https://localhost:8181" target="_blank" rel="noopener noreferrer"
                        style={{ color: '#1d4ed8', textDecoration: 'underline', fontWeight: 700 }}>
                        https://localhost:8181
                    </a>
                    {' '}واضغط <strong>Advanced → Proceed</strong>
                </span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>③ ارجع هنا واضغط «إعادة المحاولة»</span>
                {onRetry && (
                    <button onClick={onRetry} style={{
                        marginTop: '0.1rem', padding: '0.3rem 0.6rem',
                        background: '#1d4ed8', color: '#fff', border: 'none',
                        borderRadius: 'var(--radius)', cursor: 'pointer',
                        fontSize: '0.7rem', fontWeight: 600, alignSelf: 'flex-start',
                    }}>
                         إعادة المحاولة
                    </button>
                )}
            </div>
        )
    }

    return (
        <div onClick={status === 'ready' ? onPickPrinter : undefined}
            title={status === 'ready' ? 'انقر لتغيير الطابعة' : undefined}
            style={{
                padding: '0.35rem 0.6rem',
                background: bg, color: text, borderRadius: 'var(--radius)',
                fontSize: '0.72rem', fontWeight: 600,
                cursor: status === 'ready' ? 'pointer' : 'default',
                display: 'flex', alignItems: 'center', gap: '0.3rem',
            }}>
            {status === 'ready' && <Settings size={11} />}
            {label}
        </div>
    )
}

/** Printer picker modal */
export function QZPrinterPicker({ printers, selectedPrinter, onSelect, onClose }: QZPrinterPickerProps) {
    return (
        <div className="modal-overlay" style={{ zIndex: 1100 }}
            onClick={(event: MouseEvent<HTMLDivElement>) => event.target === event.currentTarget && onClose()}>
            <div className="modal" style={{ maxWidth: '380px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>اختر الطابعة</h3>
                    <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18}/></button>
                </div>
                {printers.length === 0 ? (
                    <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '1rem' }}>لا توجد طابعات متاحة</p>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                        {printers.map(p => (
                            <button
                                key={p}
                                onClick={() => onSelect(p)}
                                className={`${styles.qzPrinterItem} ${p === selectedPrinter ? styles.selected : ''}`}
                            >
                                <Printer size={16} style={{ color: p === selectedPrinter ? 'var(--primary)' : 'var(--text-muted)' }} />
                                {p}
                            </button>
                        ))}
                    </div>
                )}
                <button className="btn btn-ghost"
                    style={{ width: '100%', justifyContent: 'center', marginTop: '0.75rem' }}
                    onClick={onClose}>
                    إغلاق
                </button>
            </div>
        </div>
    )
}

/**
 * QZPrintButton — A smart print button.
 * Supports multiSize (80mm/A4) for invoices, or single button with custom label for others.
 */
export function QZPrintButton({
    qzReady,
    printing,
    onQZPrint,
    onBrowserPrint,
    onPickPrinter,
    multiSize = false,
    label = 'طباعة',
    size = 'sm',
    style = {},
}: QZPrintButtonProps) {
    if (qzReady) {
        return (
            <div style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center', ...style }}>
                {multiSize ? (
                    <>
                        <button
                            className={`btn btn-primary btn-${size}`}
                            onClick={() => onQZPrint('80mm')}
                            disabled={printing}
                            title="طباعة عبر QZ Tray (80mm)"
                        >
                            {printing ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Printer size={15} />}
                            {printing ? 'جاري…' : 'QZ (80mm)'}
                        </button>
                        <button
                            className={`btn btn-secondary btn-${size}`}
                            onClick={() => onQZPrint('A4')}
                            disabled={printing}
                            title="طباعة عبر QZ Tray (A4)"
                        >
                            {printing ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Printer size={15} />}
                            QZ (A4)
                        </button>
                    </>
                ) : (
                    <button
                        className={`btn btn-primary btn-${size}`}
                        onClick={() => onQZPrint()}
                        disabled={printing}
                        title="طباعة عبر QZ Tray"
                    >
                        {printing ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Printer size={15} />}
                        {printing ? 'جاري…' : label}
                    </button>
                )}
                {onPickPrinter && (
                    <button
                        className={`btn btn-ghost btn-icon`}
                        onClick={onPickPrinter}
                        title="اختيار الطابعة"
                    >
                        <Settings size={15} />
                    </button>
                )}
            </div>
        )
    }

    return multiSize ? (
        <div style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center', ...style }}>
            <button
                className={`btn btn-primary btn-${size}`}
                onClick={() => onBrowserPrint('80mm')}
                title="طباعة عبر المتصفح (80mm)"
            >
                <Printer size={15} /> طباعة (80mm)
            </button>
            <button
                className={`btn btn-secondary btn-${size}`}
                onClick={() => onBrowserPrint('A4')}
                title="طباعة عبر المتصفح (A4)"
            >
                <Printer size={15} /> طباعة (A4)
            </button>
        </div>
    ) : (
        <button
            className={`btn btn-primary btn-${size}`}
            onClick={() => onBrowserPrint()}
            title="طباعة عبر المتصفح"
            style={style}
        >
            <Printer size={15} /> {label}
        </button>
    )
}
