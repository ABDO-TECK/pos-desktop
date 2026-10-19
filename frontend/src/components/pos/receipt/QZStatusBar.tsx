import { Settings } from 'lucide-react'

interface QZStatusBarProps {
    status: string
    printer: string
    onPickPrinter?: () => void
    remoteError?: { message: string; certUrl: string } | null
    onRetry?: () => void
}

export default function QZStatusBar({ status, printer, onPickPrinter, remoteError, onRetry }: QZStatusBarProps) {
    const cfg: Record<string, { bg: string; text: string; label: string }> = {
        idle:        { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray: جاري التحميل…' },
        connecting:  { bg: '#fef9c3', text: '#854d0e', label: 'QZ Tray: جاري الاتصال…' },
        ready:       { bg: '#dcfce7', text: '#166534',
                       label: printer
                           ? `QZ Tray ✓ — ${printer.length > 28 ? printer.slice(0,28)+'…' : printer}`
                           : 'QZ Tray: متصل — انقر لاختيار الطابعة' },
        error:       { bg: '#fee2e2', text: '#991b1b', label: 'QZ Tray: فشل الاتصال — سيُستخدم طباعة المتصفح' },
        unavailable: { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray غير مثبت — سيُستخدم طباعة المتصفح' },
    }
    const { bg, text, label } = cfg[status] ?? cfg.idle

    if (status === 'error' && remoteError) {
        return (
            <div style={{
                marginTop: '0.6rem', padding: '0.5rem 0.75rem',
                background: '#fef3c7', color: '#92400e', borderRadius: 'var(--radius)',
                fontSize: '0.72rem', fontWeight: 600,
                display: 'flex', flexDirection: 'column', gap: '0.4rem',
            }}>
                <span>🖨️ لتفعيل الطباعة عبر الشبكة:</span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>
                    1. افتح{' '}
                    <a href={remoteError.certUrl} target="_blank" rel="noopener noreferrer"
                        style={{ color: '#1d4ed8', textDecoration: 'underline', fontWeight: 600 }}>
                        هذا الرابط
                    </a>
                    {' '}واقبل الشهادة
                </span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>2. ارجع هنا واضغط «إعادة المحاولة»</span>
                {onRetry && (
                    <button onClick={onRetry} style={{
                        marginTop: '0.25rem', padding: '0.3rem 0.6rem',
                        background: '#1d4ed8', color: '#fff', border: 'none',
                        borderRadius: 'var(--radius)', cursor: 'pointer',
                        fontSize: '0.7rem', fontWeight: 600,
                    }}>
                        🔄 إعادة المحاولة
                    </button>
                )}
            </div>
        )
    }

    return (
        <div onClick={status === 'ready' ? onPickPrinter : undefined}
            title={status === 'ready' ? 'انقر لتغيير الطابعة' : undefined}
            style={{
                marginTop: '0.6rem', padding: '0.4rem 0.7rem',
                background: bg, color: text, borderRadius: 'var(--radius)',
                fontSize: '0.76rem', fontWeight: 600,
                cursor: status === 'ready' ? 'pointer' : 'default',
                display: 'flex', alignItems: 'center', gap: '0.35rem',
            }}>
            {status === 'ready' && <Settings size={12} />}
            {label}
        </div>
    )
}
