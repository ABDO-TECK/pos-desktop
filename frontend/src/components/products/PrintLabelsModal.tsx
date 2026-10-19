import { useState } from 'react'
import { Printer, Settings, X } from 'lucide-react'
import { buildLabelHTML, LabelProduct } from '../../utils/barcodeLabelBuilder'
import useQZPrinter from '../../../src/hooks/useQZPrinter'
import PrinterPickerModal from '../pos/receipt/PrinterPickerModal'
import toast from 'react-hot-toast'
import NumericInput from '../forms/NumericInput'

interface Props {
  products: LabelProduct[]
  onClose: () => void
}

export default function PrintLabelsModal({ products, onClose }: Props) {
  const [copies, setCopies] = useState(1)
  
  const {
    qzReady,
    printers,
    selectedPrinter,
    showPrinterPicker,
    setShowPrinterPicker,
    handlePrinterSelect,
    printing,
    qzPrint,
  } = useQZPrinter()

  const handlePrint = async () => {
    if (!qzReady) {
      toast.error('QZ Tray غير متصل. يرجى التأكد من تشغيله.')
      return
    }

    const html = buildLabelHTML(products, copies)
    const { ok, error } = await qzPrint(html)
    
    if (ok) {
      toast.success('تم إرسال الملصقات للطباعة عبر QZ Tray')
      onClose()
    } else if (error) {
      toast.error(error)
    }
  }

  return (
    <>
      <div className="modal-overlay" onClick={onClose}>
        <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '400px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
            <h2 style={{ fontWeight: 700, margin: 0 }}>طباعة ملصقات باركود</h2>
            <div style={{ display: 'flex', gap: '0.4rem' }}>
              <button 
                className="btn btn-ghost btn-icon" 
                title="إعدادات الطابعة"
                onClick={() => setShowPrinterPicker(true)}
              >
                <Settings size={18} />
              </button>
              <button className="btn btn-ghost btn-icon" onClick={onClose}>
                <X size={18} />
              </button>
            </div>
          </div>
          
          <p style={{ marginBottom: '1rem', color: 'var(--text-muted)' }}>
            تم اختيار {products.length} منتج(ات) للطباعة.
            {selectedPrinter && <span style={{ display: 'block', fontSize: '0.8rem', marginTop: '0.25rem', color: 'var(--primary)' }}>الطابعة: {selectedPrinter}</span>}
          </p>
          
          <div>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>
              عدد النسخ لكل منتج
            </label>
            <NumericInput
              className="input input-lg" 
              min={1} 
              max={100}
              value={copies} 
              onChange={e => setCopies(+e.target.value)} 
            />
          </div>
          
          <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
            <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handlePrint} disabled={printing}>
              {printing ? <span className="spinner" /> : <Printer size={16} />} طباعة
            </button>
            <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={onClose}>
              إلغاء
            </button>
          </div>
        </div>
      </div>

      <PrinterPickerModal
        showPrinterPicker={showPrinterPicker}
        setShowPrinterPicker={setShowPrinterPicker}
        printers={printers}
        selectedPrinter={selectedPrinter}
        handlePrinterSelect={handlePrinterSelect}
      />
    </>
  )
}
