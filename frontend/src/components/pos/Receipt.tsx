
import { useState, useEffect } from 'react'
import { Printer, X, Settings } from 'lucide-react'
import toast from 'react-hot-toast'
import { browserPrint, buildReceiptInnerHTML, SCOPED_PRINT_CSS } from '../../utils/receiptBuilder'
import { formatNumber } from '../../utils/formatters'
import useSettingsStore from '../../store/settingsStore'
import {
    isQZAvailable,
    isQZConnected,
    connectQZ,
    printInvoice,
    listPrinters,
    getSavedPrinter,
    savePrinter,
} from '../../utils/qzPrint'
import QZStatusBar from './receipt/QZStatusBar'
import PrinterPickerModal from './receipt/PrinterPickerModal'

const METHOD_LABELS = {
    cash:          'نقدي',
    card:          'بطاقة ائتمان',
    vodafone_cash: 'فودافون كاش',
    instapay:      'انستاباي',
    other_wallet:  'محفظة إلكترونية',
    credit:        'آجل',
}

interface ReceiptProps {
    invoice: any;
    change?: number | string | null;
    onClose: () => void;
}

export default function Receipt({ invoice, change, onClose }: ReceiptProps) {
    const { storeName, storeLogo, taxEnabled, taxRate } = useSettingsStore()
    const settings = { storeName, storeLogo, taxEnabled, taxRate }

    const [qzStatus,         setQzStatus]         = useState('idle')
    const [printers,         setPrinters]         = useState<any[]>([])
    const [selectedPrinter,  setSelectedPrinter]  = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker,setShowPrinterPicker] = useState(false)
    const [printing,         setPrinting]         = useState(false)
    const [remoteError,      setRemoteError]      = useState<any>(null)
    const [hidePrices,       setHidePrices]       = useState(false)
    const [hideQuantities,   setHideQuantities]   = useState(false)

    useEffect(() => {
        if (!isQZAvailable()) { setQzStatus('unavailable'); return }
        if (isQZConnected())  { setQzStatus('ready'); loadPrinters(); return }
        setQzStatus('connecting')
        connectQZ()
            .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
            .catch((err: any) => {
                setQzStatus('error')
                if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
            })
    }, [])

    const retryQZ = () => {
        setQzStatus('connecting')
        setRemoteError(null)
        connectQZ()
            .then(() => { setQzStatus('ready'); loadPrinters() })
            .catch((err: any) => {
                setQzStatus('error')
                if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
            })
    }

    const loadPrinters = async () => {
        try {
            const list = await listPrinters()
            setPrinters(Array.isArray(list) ? list : [list] as any)
            const saved = getSavedPrinter()
            if (saved && list.includes(saved)) setSelectedPrinter(saved)
            else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
        } catch (err) {  /* ignore */ }
    }

    const changeAmt = invoice?.change_due ?? change

    // ── QZ Tray print ──
    const handleQZPrint = async (paperSize = '80mm') => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return }
        setPrinting(true)
        try {
            await printInvoice(invoice, Number(change || 0), settings, selectedPrinter, paperSize, { hidePrices, hideQuantities })
            toast.success(`تمت الطباعة بنجاح (${paperSize})`)
        } catch (err: any) {
            toast.error('فشل الطباعة: ' + (err.message ?? ''))
        } finally {
            setPrinting(false)
        }
    }

    // ── Browser print — opens a new clean window (fixes blank-page issue) ──
    const handleBrowserPrint = () => browserPrint(invoice, changeAmt, settings, '80mm', { hidePrices, hideQuantities })
    const handleA4Print = () => browserPrint(invoice, changeAmt, settings, 'A4', { hidePrices, hideQuantities })

    const handlePrinterSelect = (name: string) => {
        savePrinter(name)
        setSelectedPrinter(name)
        setShowPrinterPicker(false)
        toast.success(`تم اختيار الطابعة: ${name}`)
    }

    // ── Keyboard Shortcuts ──
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Enter' && !showPrinterPicker) {
                e.preventDefault()
                onClose()
            }
        }
        window.addEventListener('keydown', handleKeyDown)
        return () => window.removeEventListener('keydown', handleKeyDown)
    }, [onClose, showPrinterPicker])

    if (!invoice) return null

    return (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="modal" style={{ maxWidth: '420px' }}>

                {/* ── Modal header ── */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>فاتورة #{formatNumber(invoice.id)}</h3>
                    <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
                </div>

                {/* ── Print options ── */}
                <div style={{ display: 'flex', gap: '1rem', justifyContent: 'center', marginBottom: '1rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHidePrices(!hidePrices)}>
                        <input type="checkbox" checked={hidePrices} readOnly style={{ pointerEvents: 'none', cursor: 'pointer' }} />
                        <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الأسعار</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHideQuantities(!hideQuantities)}>
                        <input type="checkbox" checked={hideQuantities} readOnly style={{ pointerEvents: 'none', cursor: 'pointer' }} />
                        <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الكميات</span>
                    </div>
                </div>

                {/* ── Receipt preview (Matches Printed Version Exactly) ── */}
                <div style={{
                    background: '#e5e7eb', padding: '1rem',
                    borderRadius: 'var(--radius)',
                    maxHeight: '55vh', overflowY: 'auto',
                    display: 'flex', justifyContent: 'center'
                }}>
                    {/* The 80mm Thermal Paper */}
                    <div className="thermal-preview" style={{
                        background: '#ffffff',
                        width: '80mm', // standard thermal size
                        maxWidth: '100%',
                        boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
                        direction: 'rtl',
                    }}>
                        <style>{SCOPED_PRINT_CSS}</style>
                        <div dangerouslySetInnerHTML={{ __html: buildReceiptInnerHTML(invoice, Number(changeAmt || 0), settings, { hidePrices, hideQuantities }) }} />
                    </div>
                </div>

                {/* ── QZ Tray status bar ── */}
                <QZStatusBar
                    status={qzStatus}
                    printer={selectedPrinter}
                    onPickPrinter={() => setShowPrinterPicker(true)}
                    remoteError={remoteError}
                    onRetry={retryQZ}
                />

                {/* ── Action buttons ── */}
                <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem' }}>
                    {qzStatus === 'ready' ? (
                        <div style={{ display: 'flex', gap: '0.5rem', flex: 2 }}>
                            <button
                                className="btn btn-primary"
                                style={{ flex: 1, justifyContent: 'center', padding: '0.4rem' }}
                                onClick={() => handleQZPrint('80mm')}
                                disabled={printing}
                            >
                                {printing ? <span className="spinner" /> : <Printer size={16} />}
                                {printing ? '...' : 'QZ (80mm)'}
                            </button>
                            <button
                                className="btn btn-secondary"
                                style={{ flex: 1, justifyContent: 'center', padding: '0.4rem' }}
                                onClick={() => handleQZPrint('A4')}
                                disabled={printing}
                            >
                                {printing ? <span className="spinner" /> : <Printer size={16} />}
                                QZ (A4)
                            </button>
                        </div>
                    ) : (
                        <button
                            className="btn btn-primary"
                            style={{ flex: 1, justifyContent: 'center' }}
                            onClick={handleBrowserPrint}
                        >
                            <Printer size={16} /> طباعة
                        </button>
                    )}
                    <button
                        className="btn btn-ghost"
                        style={{ flex: 1, justifyContent: 'center' }}
                        onClick={onClose}
                    >
                        إغلاق <kbd style={{ fontSize: '0.7rem', background: 'var(--bg)', border: '1px solid var(--border)', padding: '0.1rem 0.3rem', borderRadius: '3px', marginRight: '0.4rem' }}>Enter</kbd>
                    </button>
                </div>

                {/* Always offer browser print as secondary */}
                {qzStatus === 'ready' && (
                    <button
                        className="btn btn-ghost btn-sm"
                        style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}
                        onClick={handleBrowserPrint}
                    >
                        <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة بون صغير (80mm)
                    </button>
                )}

                {/* A4 Print option */}
                <button
                    className="btn btn-secondary btn-sm"
                    style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', fontSize: '0.8rem' }}
                    onClick={handleA4Print}
                >
                    <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة ورقة كبيرة (A4)
                </button>
            </div>

            {/* ── Printer picker modal ── */}
            <PrinterPickerModal
                showPrinterPicker={showPrinterPicker}
                setShowPrinterPicker={setShowPrinterPicker}
                printers={printers}
                selectedPrinter={selectedPrinter}
                handlePrinterSelect={handlePrinterSelect}
            />
        </div>
    )
}
