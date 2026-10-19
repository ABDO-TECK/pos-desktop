import { useState, useEffect } from 'react'
import { Printer, X, EyeOff, Eye } from 'lucide-react'
import toast from 'react-hot-toast'
import { browserPrintPurchase, buildPurchaseReceiptHTML, buildPurchaseReceiptInnerHTML, SCOPED_PRINT_CSS } from '../../../utils/receiptBuilder'
import { formatNumber } from '../../../utils/formatters'
import useSettingsStore from '../../../store/settingsStore'
import {
  isQZAvailable,
  isQZConnected,
  connectQZ,
  listPrinters,
  getSavedPrinter,
  savePrinter,
  printHTML,
} from '../../../utils/qzPrint'
import QZStatusBar from '../../../components/pos/receipt/QZStatusBar'
import PrinterPickerModal from '../../../components/pos/receipt/PrinterPickerModal'

interface PurchaseReceiptModalProps {
  invoice: PurchaseInvoice & { items_count?: number }
  onClose: () => void
}

function isRemoteQzError(error: unknown): error is { isRemoteQZ: true; message: string; certUrl: string } {
  if (typeof error !== 'object' || error === null) return false
  const candidate = error as Record<string, unknown>
  return candidate.isRemoteQZ === true
    && typeof candidate.message === 'string'
    && typeof candidate.certUrl === 'string'
}

export default function PurchaseReceiptModal({ invoice, onClose }: PurchaseReceiptModalProps) {
  const { storeName, storeLogo } = useSettingsStore()
  const settings = { storeName, storeLogo }

  // Print options state
  const [hidePrices, setHidePrices] = useState(false)
  const [hideQuantities, setHideQuantities] = useState(false)

  // QZ Tray printer states
  const [qzStatus, setQzStatus] = useState<'idle' | 'connecting' | 'ready' | 'error' | 'unavailable'>('idle')
  const [printers, setPrinters] = useState<string[]>([])
  const [selectedPrinter, setSelectedPrinter] = useState(getSavedPrinter() ?? '')
  const [showPrinterPicker, setShowPrinterPicker] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [remoteError, setRemoteError] = useState<{ message: string; certUrl: string } | null>(null)

  useEffect(() => {
    if (!isQZAvailable()) { setQzStatus('unavailable'); return }
    if (isQZConnected()) { setQzStatus('ready'); loadPrinters(); return }
    setQzStatus('connecting')
    connectQZ()
      .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
      .catch((err: unknown) => {
        setQzStatus('error')
        if (isRemoteQzError(err)) setRemoteError({ message: err.message, certUrl: err.certUrl })
      })
  }, [])

  const retryQZ = () => {
    setQzStatus('connecting')
    setRemoteError(null)
    connectQZ()
      .then(() => { setQzStatus('ready'); loadPrinters() })
      .catch((err: unknown) => {
        setQzStatus('error')
        if (isRemoteQzError(err)) setRemoteError({ message: err.message, certUrl: err.certUrl })
      })
  }

  const loadPrinters = async () => {
    try {
      const list = await listPrinters()
      const printerList = Array.isArray(list) ? list : [list]
      setPrinters(printerList)
      const saved = getSavedPrinter()
      if (saved && printerList.includes(saved)) setSelectedPrinter(saved)
      else if (printerList.length === 1) { savePrinter(printerList[0]); setSelectedPrinter(printerList[0]) }
    } catch (err) { /* ignore */ }
  }

  const printOptions = { hidePrices, hideQuantities }

  // ── QZ Tray print ──
  const handleQZPrint = async (paperSize = '80mm') => {
    if (!selectedPrinter) { setShowPrinterPicker(true); return }
    setPrinting(true)
    try {
      const html = buildPurchaseReceiptHTML(invoice, settings, paperSize, printOptions)
      await printHTML(html, selectedPrinter)
      toast.success(`تمت الطباعة بنجاح (${paperSize})`)
    } catch (err: unknown) {
      toast.error('فشل الطباعة: ' + (err instanceof Error ? err.message : ''))
    } finally {
      setPrinting(false)
    }
  }

  // ── Browser prints ──
  const handleBrowserPrint = () => browserPrintPurchase(invoice, settings, '80mm', printOptions)
  const handleA4Print = () => browserPrintPurchase(invoice, settings, 'A4', printOptions)

  const handlePrinterSelect = (name: string) => {
    savePrinter(name)
    setSelectedPrinter(name)
    setShowPrinterPicker(false)
    toast.success(`تم اختيار الطابعة: ${name}`)
  }

  // Keyboard shortcut: Enter to close
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
      <div className="modal" style={{ maxWidth: '480px', width: '95%' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h3 style={{ fontWeight: 700 }}>فاتورة مشتريات #{formatNumber(invoice.id)}</h3>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {/* Print Toggles */}
        <div style={{
          display: 'flex',
          gap: '1rem',
          background: 'var(--bg)',
          borderRadius: 'var(--radius)',
          padding: '0.75rem',
          marginBottom: '1rem',
          border: '1px solid var(--border)'
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHidePrices(!hidePrices)}>
            {hidePrices ? <EyeOff size={16} className="text-danger" /> : <Eye size={16} className="text-primary" />}
            <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الأسعار والتكاليف</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHideQuantities(!hideQuantities)}>
            {hideQuantities ? <EyeOff size={16} className="text-danger" /> : <Eye size={16} className="text-primary" />}
            <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الكميات</span>
          </div>
        </div>

        {/* Thermal Preview */}
        <div style={{
          background: '#e5e7eb', padding: '1rem',
          borderRadius: 'var(--radius)',
          maxHeight: '40vh', overflowY: 'auto',
          display: 'flex', justifyContent: 'center',
          marginBottom: '1rem'
        }}>
          <div className="thermal-preview" style={{
            background: '#ffffff',
            width: '80mm',
            maxWidth: '100%',
            boxShadow: '0 4px 6px -1px rgba(0,0,0,0.1)',
            direction: 'rtl',
          }}>
            <style>{SCOPED_PRINT_CSS}</style>
            <div dangerouslySetInnerHTML={{ __html: buildPurchaseReceiptInnerHTML(invoice, settings, printOptions) }} />
          </div>
        </div>

        {/* QZ Status */}
        <QZStatusBar
          status={qzStatus}
          printer={selectedPrinter}
          onPickPrinter={() => setShowPrinterPicker(true)}
          remoteError={remoteError}
          onRetry={retryQZ}
        />

        {/* Actions */}
        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem' }}>
          {qzStatus === 'ready' ? (
            <div style={{ display: 'flex', gap: '0.5rem', flex: 2 }}>
              <button
                className="btn btn-primary"
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={() => handleQZPrint('80mm')}
                disabled={printing}
              >
                <Printer size={16} /> QZ (80mm)
              </button>
              <button
                className="btn btn-secondary"
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={() => handleQZPrint('A4')}
                disabled={printing}
              >
                <Printer size={16} /> QZ (A4)
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
          <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={onClose}>
            إغلاق <kbd style={{ fontSize: '0.7rem', background: 'var(--bg)', border: '1px solid var(--border)', padding: '0.1rem 0.3rem', borderRadius: '3px', marginRight: '0.4rem' }}>Enter</kbd>
          </button>
        </div>

        {/* Secondary options */}
        {qzStatus === 'ready' && (
          <button
            className="btn btn-ghost btn-sm"
            style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}
            onClick={handleBrowserPrint}
          >
            <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة بون صغير (80mm)
          </button>
        )}
        <button
          className="btn btn-secondary btn-sm"
          style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', fontSize: '0.8rem' }}
          onClick={handleA4Print}
        >
          <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة ورقة كبيرة (A4)
        </button>
      </div>

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
