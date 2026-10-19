import { useState, useEffect } from 'react'
import { Save, Store, Percent, List, ShieldCheck, Image as ImageIcon, Trash2, Upload } from 'lucide-react'
import toast from 'react-hot-toast'
import { updateSettings } from '../../api/endpoints'
import useSettingsStore from '../../store/settingsStore'
import SectionTitle from '../../components/common/SectionTitle'
import Toggle from '../../components/common/Toggle'
import { extractApiError } from '../../utils/apiError'
import styles from '../Settings.module.css'
import NumericInput from '../../components/forms/NumericInput'

export default function StoreSettingsForm() {
  const { fetchSettings, setSettings } = useSettingsStore()

  const labelStyle = {
    display: 'block',
    fontSize: '0.88rem',
    fontWeight: 600,
    color: 'var(--text)',
    marginBottom: '0.4rem',
  }

  const [form, setForm] = useState({ 
    store_name: '', store_logo: '', tax_enabled: '0', tax_rate: '15', prevent_negative_stock: '1',
    loyalty_enabled: '0', loyalty_points_per_rial: '1', loyalty_rial_per_point: '0.01'
  })
  const [saving, setSaving] = useState(false)

  // Cropper states
  const [rawImage, setRawImage] = useState<string | null>(null)
  const [showCropper, setShowCropper] = useState(false)
  const [zoom, setZoom] = useState(1)
  const [imagePos, setImagePos] = useState({ x: 0, y: 0 })
  const [isDragging, setIsDragging] = useState(false)
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 })
  const [imageDims, setImageDims] = useState({ width: 0, height: 0, naturalWidth: 0, naturalHeight: 0 })

  useEffect(() => {
    fetchSettings().then(() => {
      const s = useSettingsStore.getState()
      setForm({
        store_name:  s.storeName,
        store_logo:  s.storeLogo ?? '',
        tax_enabled: s.taxEnabled ? '1' : '0',
        tax_rate:    String(s.taxRate),
        prevent_negative_stock: s.preventNegativeStock ? '1' : '0',
        loyalty_enabled: s.loyaltyEnabled ? '1' : '0',
        loyalty_points_per_rial: String(s.loyaltyPointsPerRial),
        loyalty_rial_per_point: String(s.loyaltyRialPerPoint),
      })
    })
  }, [fetchSettings])

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await updateSettings(form)
      setSettings({
        storeName:  form.store_name,
        storeLogo:  form.store_logo,
        taxEnabled: form.tax_enabled === '1',
        taxRate:    parseFloat(form.tax_rate),
        preventNegativeStock: form.prevent_negative_stock === '1',
        loyaltyEnabled: form.loyalty_enabled === '1',
        loyaltyPointsPerRial: parseInt(form.loyalty_points_per_rial, 10),
        loyaltyRialPerPoint: parseFloat(form.loyalty_rial_per_point),
      })
      toast.success('تم حفظ الإعدادات')
    } catch (err: any) { 
      toast.error(extractApiError(err, 'فشل حفظ الإعدادات')) 
    } finally {
      setSaving(false)
    }
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const file = e.target.files[0]
      const reader = new FileReader()
      reader.onload = (event) => {
        setRawImage(event.target?.result as string)
        setShowCropper(true)
        setZoom(1)
        setImagePos({ x: 0, y: 0 })
      }
      reader.readAsDataURL(file)
    }
  }

  const handleImageLoaded = (e: React.SyntheticEvent<HTMLImageElement>) => {
    const img = e.currentTarget
    const nw = img.naturalWidth
    const nh = img.naturalHeight
    const aspect = nw / nh
    
    let w = 200
    let h = 200
    if (aspect > 1) {
      w = 200 * aspect
    } else {
      h = 200 / aspect
    }
    
    setImageDims({ width: w, height: h, naturalWidth: nw, naturalHeight: nh })
    setImagePos({ x: (200 - w) / 2, y: (200 - h) / 2 })
  }

  const handleMouseDown = (e: React.MouseEvent) => {
    e.preventDefault()
    setIsDragging(true)
    setDragStart({ x: e.clientX - imagePos.x, y: e.clientY - imagePos.y })
  }

  const handleMouseMove = (e: React.MouseEvent) => {
    if (!isDragging) return
    const w = imageDims.width * zoom
    const h = imageDims.height * zoom
    let newX = e.clientX - dragStart.x
    let newY = e.clientY - dragStart.y
    if (newX > 0) newX = 0
    if (newY > 0) newY = 0
    if (newX < 200 - w) newX = 200 - w
    if (newY < 200 - h) newY = 200 - h
    setImagePos({ x: newX, y: newY })
  }

  const handleMouseUp = () => {
    setIsDragging(false)
  }

  const handleTouchStart = (e: React.TouchEvent) => {
    if (e.touches.length === 1) {
      setIsDragging(true)
      setDragStart({ 
        x: e.touches[0].clientX - imagePos.x, 
        y: e.touches[0].clientY - imagePos.y 
      })
    }
  }

  const handleTouchMove = (e: React.TouchEvent) => {
    if (!isDragging || e.touches.length !== 1) return
    const w = imageDims.width * zoom
    const h = imageDims.height * zoom
    let newX = e.touches[0].clientX - dragStart.x
    let newY = e.touches[0].clientY - dragStart.y
    if (newX > 0) newX = 0
    if (newY > 0) newY = 0
    if (newX < 200 - w) newX = 200 - w
    if (newY < 200 - h) newY = 200 - h
    setImagePos({ x: newX, y: newY })
  }

  const handleCropSave = () => {
    const canvas = document.createElement('canvas')
    canvas.width = 200
    canvas.height = 200
    const ctx = canvas.getContext('2d')
    if (!ctx) return

    const img = new Image()
    img.onload = () => {
      const w = imageDims.width * zoom
      const h = imageDims.height * zoom
      
      const sourceX = (-imagePos.x / w) * imageDims.naturalWidth
      const sourceY = (-imagePos.y / h) * imageDims.naturalHeight
      const sourceW = (200 / w) * imageDims.naturalWidth
      const sourceH = (200 / h) * imageDims.naturalHeight

      ctx.drawImage(img, sourceX, sourceY, sourceW, sourceH, 0, 0, 200, 200)
      
      const croppedBase64 = canvas.toDataURL('image/png')
      setForm(prev => ({ ...prev, store_logo: croppedBase64 }))
      setShowCropper(false)
    }
    img.src = rawImage!
  }

  return (
    <form onSubmit={handleSave} className={styles.settingsForm}>
      {/* ── Store Info ── */}
      <section className={`card ${styles.settingsCard}`}>
        <SectionTitle icon={<Store size={16}/>} label="معلومات المحل" />
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div>
            <label className={styles.settingsLabel}>اسم المحل</label>
            <input
              type="text"
              className="input"
              value={form.store_name}
              onChange={e => setForm({ ...form, store_name: e.target.value })}
              placeholder="اسم المحل"
            />
            <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginTop: '0.3rem' }}>
              يظهر في الفاتورة وعنوان الشريط الجانبي
            </p>
          </div>
        </div>
      </section>

      {/* ── Store Logo ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<ImageIcon size={16}/>} label="شعار الفاتورة" />
        <div style={{ display: 'flex', gap: '1.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
          {form.store_logo ? (
            <div style={{ position: 'relative', display: 'inline-block' }}>
              <img 
                src={form.store_logo} 
                alt="Store Logo" 
                style={{ 
                  maxHeight: '100px', 
                  maxWidth: '100px', 
                  borderRadius: 'var(--radius)', 
                  border: '1px solid var(--border)',
                  objectFit: 'contain',
                  background: '#fff',
                  padding: '4px'
                }} 
              />
              <button 
                type="button" 
                className="btn btn-danger btn-icon" 
                style={{ 
                  position: 'absolute', 
                  top: '-8px', 
                  left: '-8px', 
                  padding: '0.2rem', 
                  borderRadius: '50%',
                  height: '24px',
                  width: '24px',
                  boxShadow: '0 2px 4px rgba(0,0,0,0.1)'
                }}
                onClick={() => setForm(prev => ({ ...prev, store_logo: '' }))}
                title="حذف الشعار"
              >
                <Trash2 size={12} />
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', flex: 1 }}>
              <label 
                className="btn btn-ghost" 
                style={{ 
                  border: '1px dashed var(--border)', 
                  padding: '1.5rem', 
                  borderRadius: 'var(--radius)',
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  gap: '0.5rem',
                  cursor: 'pointer',
                  textAlign: 'center'
                }}
              >
                <Upload size={24} className="text-muted" />
                <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>اضغط لرفع شعار للمحل</span>
                <input 
                  type="file" 
                  accept="image/*" 
                  onChange={handleFileChange} 
                  style={{ display: 'none' }} 
                />
              </label>
            </div>
          )}
          
          <div style={{ flex: 2, minWidth: '200px' }}>
            <p style={{ fontSize: '0.85rem', fontWeight: 600, margin: '0 0 0.25rem 0' }}>إرشادات رفع الشعار:</p>
            <ul style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: 0, paddingRight: '1.2rem', lineHeight: 1.5 }}>
              <li>سيتم قص الشعار تلقائياً إلى شكل مربع متناسق (200×200 بكسل).</li>
              <li>يفضل استخدام صور عالية التباين (أبيض وأسود) للحصول على أفضل جودة طباعة حرارية.</li>
              <li>يدعم النظام صيغ PNG و JPG.</li>
            </ul>
          </div>
        </div>
      </section>

      {/* ── Tax ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<Percent size={16}/>} label="إعدادات الضريبة" />

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <p style={{ fontWeight: 600, fontSize: '0.95rem', margin: 0 }}>تفعيل الضريبة</p>
            <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)', margin: 0 }}>تطبيق ضريبة القيمة المضافة على المبيعات</p>
          </div>
          <Toggle
            checked={form.tax_enabled === '1'}
            onChange={() => setForm({ ...form, tax_enabled: form.tax_enabled === '1' ? '0' : '1' })}
          />
        </div>

        {form.tax_enabled === '1' && (
          <div>
            <label style={labelStyle}>نسبة الضريبة (%)</label>
            <NumericInput
              className="input"
              min="0"
              max="100"
              step="0.1"
              style={{ maxWidth: '160px' }}
              value={form.tax_rate}
              onChange={e => setForm({ ...form, tax_rate: e.target.value })}
            />
          </div>
        )}
      </section>

      {/* ── Stock policy ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<ShieldCheck size={16}/>} label="سياسة المخزون" />

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1rem' }}>
          <div>
            <p style={{ fontWeight: 600, fontSize: '0.95rem', margin: 0 }}>منع البيع عند نفاد المخزون</p>
            <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)', margin: 0 }}>
              عند التفعيل لا يمكن بيع كمية أكبر من المخزون المتاح.
            </p>
          </div>
          <Toggle
            checked={form.prevent_negative_stock === '1'}
            onChange={() => setForm({
              ...form,
              prevent_negative_stock: form.prevent_negative_stock === '1' ? '0' : '1',
            })}
          />
        </div>
        <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: 0 }}>
          عند إيقاف الخيار يسمح النظام بالبيع وتسجيل الكمية بالسالب حتى تتم إضافة المخزون لاحقاً.
        </p>
      </section>

      {/* ── Loyalty ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<List size={16}/>} label="نظام نقاط الولاء" />

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <p style={{ fontWeight: 600, fontSize: '0.95rem', margin: 0 }}>تفعيل نظام الولاء</p>
            <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)', margin: 0 }}>السماح للعملاء باكتساب نقاط عند الشراء</p>
          </div>
          <Toggle
            checked={form.loyalty_enabled === '1'}
            onChange={() => setForm({ ...form, loyalty_enabled: form.loyalty_enabled === '1' ? '0' : '1' })}
          />
        </div>

        {form.loyalty_enabled === '1' && (
          <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
            <div>
              <label style={labelStyle}>النقاط المكتسبة لكل 1 ريال</label>
              <NumericInput
                className="input"
                min="1"
                style={{ maxWidth: '160px' }}
                value={form.loyalty_points_per_rial}
                onChange={e => setForm({ ...form, loyalty_points_per_rial: e.target.value })}
              />
            </div>
            <div>
              <label style={labelStyle}>قيمة النقطة الواحدة (ريال)</label>
              <NumericInput
                className="input"
                min="0.01"
                step="0.01"
                style={{ maxWidth: '160px' }}
                value={form.loyalty_rial_per_point}
                onChange={e => setForm({ ...form, loyalty_rial_per_point: e.target.value })}
              />
            </div>
          </div>
        )}
      </section>

      <button type="submit" disabled={saving} className="btn btn-primary" style={{ alignSelf: 'flex-start' }}>
        {saving ? <span className="spinner" /> : <Save size={16}/>}
        {saving ? 'جاري الحفظ…' : 'حفظ الإعدادات'}
      </button>

      {/* Cropper Modal Overlay */}
      {showCropper && (
        <div className="modal-overlay" style={{ zIndex: 1200 }}>
          <div className="modal" style={{ maxWidth: '350px', padding: '1.25rem', textAlign: 'center' }}>
            <h3 style={{ fontWeight: 700, marginBottom: '0.5rem' }}>ضبط وقص الشعار</h3>
            <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '1.25rem' }}>
              اسحب الصورة لتعديل تموضعها داخل المربع، واستخدم شريط التكبير بالأسفل لضبط الحجم
            </p>
            
            {/* Viewport crop box container */}
            <div 
              style={{
                width: '200px',
                height: '200px',
                margin: '0 auto 1.25rem',
                position: 'relative',
                overflow: 'hidden',
                borderRadius: 'var(--radius)',
                border: '2px dashed var(--primary)',
                background: '#f3f4f6',
                cursor: 'move',
                boxShadow: 'inset 0 2px 4px rgba(0,0,0,0.06)'
              }}
              onMouseDown={handleMouseDown}
              onMouseMove={handleMouseMove}
              onMouseUp={handleMouseUp}
              onMouseLeave={handleMouseUp}
              onTouchStart={handleTouchStart}
              onTouchMove={handleTouchMove}
              onTouchEnd={handleMouseUp}
            >
              <img 
                src={rawImage!}
                onLoad={handleImageLoaded}
                alt="Source Image"
                style={{
                  position: 'absolute',
                  left: `${imagePos.x}px`,
                  top: `${imagePos.y}px`,
                  width: `${imageDims.width * zoom}px`,
                  height: `${imageDims.height * zoom}px`,
                  pointerEvents: 'none',
                  maxWidth: 'none',
                  maxHeight: 'none',
                }}
              />
            </div>
            
            {/* Slider zoom */}
            <div style={{ marginBottom: '1.5rem', padding: '0 0.5rem' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.4rem' }}>
                <span>مستوى التكبير</span>
                <span>{zoom.toFixed(1)}x</span>
              </div>
              <input 
                type="range"
                min="1"
                max="3"
                step="0.1"
                value={zoom}
                onChange={e => {
                  const newZoom = parseFloat(e.target.value)
                  setZoom(newZoom)
                  
                  const w = imageDims.width * newZoom
                  const h = imageDims.height * newZoom
                  setImagePos(prev => {
                    let x = prev.x
                    let y = prev.y
                    if (x > 0) x = 0
                    if (y > 0) y = 0
                    if (x < 200 - w) x = 200 - w
                    if (y < 200 - h) y = 200 - h
                    return { x, y }
                  })
                }}
                style={{ width: '100%', cursor: 'pointer' }}
              />
            </div>
            
            {/* Action buttons */}
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <button 
                type="button" 
                className="btn btn-primary" 
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={handleCropSave}
              >
                تطبيق القص
              </button>
              <button 
                type="button" 
                className="btn btn-ghost" 
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={() => setShowCropper(false)}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      )}
    </form>
  )
}
