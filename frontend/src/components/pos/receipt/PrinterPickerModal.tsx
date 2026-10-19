import { X, Printer } from 'lucide-react'
import type { Dispatch, MouseEvent, SetStateAction } from 'react'
import styles from '../../QZPrinterUI.module.css'

interface PrinterPickerModalProps {
    showPrinterPicker: boolean
    setShowPrinterPicker: Dispatch<SetStateAction<boolean>>
    printers: readonly string[]
    selectedPrinter: string
    handlePrinterSelect: (printer: string) => void
}

export default function PrinterPickerModal({
    showPrinterPicker,
    setShowPrinterPicker,
    printers,
    selectedPrinter,
    handlePrinterSelect,
}: PrinterPickerModalProps) {
    if (!showPrinterPicker) return null

    return (
        <div className="modal-overlay" style={{ zIndex: 1100 }}
            onClick={(event: MouseEvent<HTMLDivElement>) => event.target === event.currentTarget && setShowPrinterPicker(false)}>
            <div className="modal" style={{ maxWidth: '380px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>اختر الطابعة</h3>
                    <button className="btn btn-ghost btn-icon" onClick={() => setShowPrinterPicker(false)}><X size={18}/></button>
                </div>
                {printers.length === 0 ? (
                    <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '1rem' }}>لا توجد طابعات متاحة</p>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                        {printers.map(p => (
                            <button
                                key={p}
                                onClick={() => handlePrinterSelect(p)}
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
                    onClick={() => setShowPrinterPicker(false)}>
                    إغلاق
                </button>
            </div>
        </div>
    )
}
