import { useState, useEffect, useRef, useMemo } from 'react'
import { Plus, X, Search, ChevronDown, Camera, Scale, Droplet, Layers, Box } from 'lucide-react'
import { formatNumber } from '../../utils/formatters'
import toast from 'react-hot-toast'
import { extractApiError } from '../../utils/apiError'
import IconBadge from '../../components/common/IconBadge'
import NumericInput from '../../components/forms/NumericInput'

/* ── Barcode conflict helpers ── */

/** منتج يملك هذا الباركود (أساسي أو إضافي)، مع استثناء منتج أثناء التعديل */
export function findProductOwningBarcode(allProducts: any[], barcode: any, excludeProductId: any) {
  const t = String(barcode).trim()
  if (!t) return null
  for (const p of allProducts) {
    if (excludeProductId != null && Number(p.id) === Number(excludeProductId)) continue
    if (String(p.barcode ?? '') === t) return p
    if (String(p.box_barcode ?? '') === t) return p
    if ((p.additional_barcodes || []).some((b: any) => String(b) === t)) return p
  }
  return null
}

export function getBarcodeRowConflict(barcodes: any[], rowIndex: any, excludeProductId: any, allProducts: any[], form: any) {
  const bc = rowIndex === 'box'
    ? String(form?.box_barcode ?? '').trim()
    : String(barcodes[rowIndex] ?? '').trim()
  if (!bc) return null
  const dupElsewhere = rowIndex === 'box'
    ? barcodes.some((b: any) => String(b).trim() === bc)
    : barcodes.some((b: any, j: number) => j !== rowIndex && String(b).trim() === bc) || String(form?.box_barcode ?? '').trim() === bc
  if (dupElsewhere) {
    return {
      kind: 'duplicate',
      title: 'باركود مكرر في النموذج',
      line: 'هذا الباركود مُدخل في أكثر من حقل؛ احذف التكرار أو غيّر أحد القيمتين.',
    }
  }
  const owner = findProductOwningBarcode(allProducts, bc, excludeProductId)
  if (owner) {
    return {
      kind: 'taken',
      title: `مسجّل للمنتج: «${owner.name}»`,
      line: `هذا الباركود يخص المنتج «${owner.name}» بالفعل.`,
      productName: owner.name,
    }
  }
  return null
}

export function formatProductApiError(err: any) {
  const d = err?.response?.data
  if (!d) return 'حدث خطأ'
  if (typeof d.message === 'string' && d.message.trim()) return d.message
  const errors = d.errors
  if (errors && typeof errors === 'object') {
    const lines: string[] = []
    for (const [field, msgs] of Object.entries(errors)) {
      const arr = Array.isArray(msgs) ? msgs : [msgs]
      const label = field === 'name' ? 'اسم المنتج' : field === 'price' ? 'سعر البيع' : field
      arr.forEach((raw) => {
        const m = String(raw)
        if (m.includes('required')) lines.push(`${label} مطلوب`)
        else if (m.includes('numeric')) lines.push(`${label} يجب أن يكون رقماً`)
        else lines.push(`${label}: ${m}`)
      })
    }
    if (lines.length) return lines.join(' — ')
  }
  return 'حدث خطأ'
}

/* ── Label ── */
function Label({ children }: any) {
  return <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>{children}</label>
}

/* ── Category Combobox ── */
export function CategoryCombobox({ categories, value, onChange }: any) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const rootRef = useRef<any>(null)

  useEffect(() => {
    const close = (e: any) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) {
        setOpen(false)
        setQ('')
      }
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return categories
    return categories.filter((c: any) => (c.name || '').toLowerCase().includes(t))
  }, [categories, q])

  const selected = categories.find((c: any) => String(c.id) === String(value))
  const displayLabel =
    value === '' || value == null ? 'بدون فئة' : (selected?.name ?? 'فئة غير معروفة')

  const pickNone = () => {
    onChange('')
    setOpen(false)
    setQ('')
  }
  const pick = (id: any) => {
    onChange(String(id))
    setOpen(false)
    setQ('')
  }

  return (
    <div ref={rootRef} style={{ position: 'relative' }}>
      <button
        type="button"
        className="input"
        onClick={() => {
          setOpen((prev) => {
            if (prev) setQ('')
            return !prev
          })
        }}
        style={{
          width: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: '0.5rem',
          cursor: 'pointer',
          textAlign: 'right',
          background: 'var(--surface)',
        }}
      >
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{displayLabel}</span>
        <ChevronDown size={18} style={{ flexShrink: 0, opacity: 0.6, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
      </button>
      {open && (
        <div
          className="card"
          style={{
            position: 'absolute',
            zIndex: 50,
            left: 0,
            right: 0,
            top: 'calc(100% + 4px)',
            padding: '0.5rem',
            maxHeight: 'min(320px, 70vh)',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.4rem',
            boxShadow: 'var(--shadow-lg, 0 12px 40px rgba(0,0,0,.12))',
          }}
          onMouseDown={(e) => e.preventDefault()}
        >
          <div style={{ position: 'relative' }}>
            <Search size={16} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.65rem', color: 'var(--text-muted)', pointerEvents: 'none' }} />
            <input
              className="input"
              style={{ paddingRight: '2.2rem', fontSize: '0.88rem' }}
              placeholder="ابحث عن فئة…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              autoFocus
            />
          </div>
          <div style={{ overflowY: 'auto', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', gap: '0.15rem' }}>
            <button
              type="button"
              className={`btn btn-sm ${value === '' || value == null ? 'btn-primary' : 'btn-ghost'}`}
              style={{ justifyContent: 'flex-start', fontWeight: 600 }}
              onClick={pickNone}
            >
              بدون فئة
            </button>
            {filtered.length === 0 ? (
              <div style={{ padding: '0.75rem', textAlign: 'center', fontSize: '0.82rem', color: 'var(--text-muted)' }}>
                لا توجد فئات تطابق البحث
              </div>
            ) : (
              filtered.map((c: any) => (
                <button
                  key={c.id}
                  type="button"
                  className={`btn btn-sm ${String(value) === String(c.id) ? 'btn-primary' : 'btn-ghost'}`}
                  style={{ justifyContent: 'flex-start' }}
                  onClick={() => pick(c.id)}
                >
                  {c.name}
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Product Form ── */
export default function ProductForm({ form, setForm, categories, modalKey, allProducts = [], editingProductId = null }: any) {
  const [barcodeCameraRow, setBarcodeCameraRow] = useState<any>(null)
  const [BarcodeScannerLazy, setBarcodeScannerLazy] = useState<any>(null)

  const openBarcodeCamera = async (target: any) => {
    try {
      if (!BarcodeScannerLazy) {
        const m = await import('../../components/BarcodeCameraScanner')
        setBarcodeScannerLazy(() => m.default)
      }
      setBarcodeCameraRow(target)
    } catch (err) { toast.error(extractApiError(err, 'تعذر تحميل ماسح الباركود')) }
  }
  const f = (k: any) => ({ value: form[k] ?? '', onChange: (e: any) => setForm((p: any) => ({ ...p, [k]: e.target.value })) })
  const unitType = form.unit_type ?? (parseInt(form.sell_by_weight) === 1 ? 'weight' : 'piece')
  const isByWeight = unitType === 'weight'
  const isByPiece = unitType === 'piece'
  const isByLiter = unitType === 'liter'
  const barcodes = Array.isArray(form.barcodes) ? form.barcodes : [form.barcode || '']

  const setBarcodeAt = (i: any, v: any) => {
    setForm((p: any) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      b[i] = v
      return { ...p, barcodes: b }
    })
  }
  const addBarcodeRow = () =>
    setForm((p: any) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      return { ...p, barcodes: [...b, ''] }
    })
  const removeBarcodeRow = (i: number) => {
    if (i === 0) return
    setForm((p: any) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      const next = b.filter((_, j) => j !== i)
      return { ...p, barcodes: next.length ? next : [''] }
    })
  }

  /** عدد الصناديق المكافئة للمخزون (الكمية ÷ قطع/صندوق) */
  const stockBoxesHint = useMemo(() => {
    const rawQ = form.quantity
    const rawU = form.units_per_box
    const qty =
      rawQ === '' || rawQ === null || rawQ === undefined
        ? null
        : parseInt(String(rawQ).trim(), 10)
    const upb = Math.max(1, parseInt(String(rawU ?? '1').trim(), 10) || 1)

    if (qty === null || Number.isNaN(qty)) {
      return 'بعد إدخال «الكمية» أعلاه، يُعرض هنا كم صندوقًا يمثّلها المخزون.'
    }
    if (qty < 0) {
      return 'أدخل كمية صحيحة في حقل «الكمية».'
    }
    if (qty === 0) {
      return 'الكمية الحالية 0 — لا يوجد مخزون يعادل صناديق بعد.'
    }
    if (upb <= 1) {
      return `المخزون ${formatNumber(qty)} قطعة؛ صندوق البيع = قطعة واحدة (لا تجميع)، أي ${formatNumber(qty)} وحدة بيع بالصندوق.`
    }
    const full = Math.floor(qty / upb)
    const rem = qty % upb
    if (rem === 0) {
      return `مخزونك ${formatNumber(qty)} قطعة يعادل ${formatNumber(full)} صندوقًا كاملاً (${formatNumber(upb)} قطعة في كل صندوق).`
    }
    return `مخزونك ${formatNumber(qty)} قطعة يعادل ${formatNumber(full)} صندوقًا كاملاً + ${formatNumber(rem)} قطعة لا تكمل صندوقًا (${formatNumber(upb)} قطعة/صندوق).`
  }, [form.quantity, form.units_per_box])

  return (
    <>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.5rem', textAlign: 'right' }}>
      
      {/* ── العمود الأيمن ── */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        <div>
          <Label>اسم المنتج *</Label>
          <input className="input" {...f('name')} placeholder="مثال: أرز بسمتي 1كغ" required />
        </div>

        <div>
          <Label>الباركود</Label>
          <p style={{ fontSize: '0.74rem', color: 'var(--text-muted)', margin: '0 0 0.4rem' }}>
            اختياري — إذا تركته فارغًا سيُولد تلقائيًا.
          </p>
          {barcodes.map((bc: any, idx: number) => {
            const conflict = getBarcodeRowConflict(barcodes, idx, editingProductId, allProducts, form)
            return (
              <div key={idx} style={{ marginBottom: '0.4rem' }}>
                <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'flex-start' }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <input
                      className="input"
                      style={{
                        width: '100%',
                        borderColor: conflict ? '#dc2626' : undefined,
                        boxShadow: conflict ? '0 0 0 1px rgba(220, 38, 38, 0.35)' : undefined,
                      }}
                      value={bc}
                      onChange={(e) => setBarcodeAt(idx, e.target.value)}
                      placeholder={idx === 0 ? 'باركود المنتج (أو اتركه فارغًا للإنشاء التلقائي)' : 'باركود إضافي'}
                      title={conflict ? conflict.title : undefined}
                    />
                    {conflict && (
                      <div
                        style={{
                          fontSize: '0.7rem', color: '#991b1b', marginTop: '0.2rem',
                          padding: '0.25rem 0.4rem', background: '#fee2e2',
                          border: '1px solid #fecaca', borderRadius: '4px',
                        }}
                      >
                        {conflict.line}
                      </div>
                    )}
                  </div>
                  <button
                    type="button"
                    className="btn btn-ghost btn-icon btn-sm"
                    style={{ flexShrink: 0, marginTop: '0.15rem' }}
                    onClick={() => openBarcodeCamera(idx)}
                    title="مسح بالكاميرا"
                  >
                    <Camera size={16} />
                  </button>
                  {idx > 0 && (
                    <button
                      type="button"
                      className="btn btn-ghost btn-icon btn-sm"
                      style={{ flexShrink: 0, marginTop: '0.15rem' }}
                      onClick={() => removeBarcodeRow(idx)}
                    >
                      <X size={14} />
                    </button>
                  )}
                </div>
              </div>
            )
          })}
          <button type="button" className="btn btn-ghost btn-sm" onClick={addBarcodeRow} style={{ marginTop: '0.15rem', padding: '0.2rem 0.5rem' }}>
            <Plus size={12} style={{ marginLeft: '0.2rem' }} /> إضافة باركود إضافي
          </button>
        </div>

        {/* نوع الوحدة */}
        <div style={{ marginTop: '0.5rem' }}>
          <Label>نوع الوحدة</Label>
          <div style={{ display: 'flex', gap: '0.4rem' }}>
            {[
              { id: 'piece', label: 'قطعة', icon: Box, color: 'secondary' },
              { id: 'weight', label: 'وزن (كجم)', icon: Scale, color: 'primary' },
              { id: 'liter', label: 'لتر', icon: Droplet, color: 'info' }
            ].map(item => (
              <button
                key={item.id}
                type="button"
                className={`btn btn-sm ${unitType === item.id ? 'btn-primary' : 'btn-ghost'}`}
                style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.35rem', height: '34px', fontSize: '0.82rem' }}
                onClick={() => setForm((p: any) => ({ ...p, unit_type: item.id, sell_by_weight: item.id === 'weight' ? 1 : 0 }))}
              >
                <IconBadge icon={item.icon} color={unitType === item.id ? 'default' : item.color as any} shape="rounded" size={12} badgeSize={20} />
                {item.label}
              </button>
            ))}
          </div>
        </div>

        {/* قسم المقاسات المتعددة */}
        <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', marginTop: '0.75rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.4rem' }}>
            <span style={{ fontSize: '0.82rem', fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
              <IconBadge icon={Layers} color="secondary" shape="rounded" size={12} badgeSize={22} />
              المقاسات والأحجام المتعددة
            </span>
            <button
              type="button"
              className="btn btn-ghost btn-sm"
              style={{ fontSize: '0.76rem', padding: '0.2rem 0.5rem' }}
              onClick={() => setForm((p: any) => ({
                ...p,
                sizes: [...(p.sizes || []), { size_name: '', price: p.price || '', cost: p.cost || '', barcode: '' }]
              }))}
            >
              <Plus size={12} style={{ marginLeft: '0.2rem' }} /> إضافة مقاس
            </button>
          </div>
          {(!form.sizes || form.sizes.length === 0) ? (
            <p style={{ fontSize: '0.74rem', color: 'var(--text-muted)', margin: 0 }}>
              لا توجد مقاسات مضافة. يُباع المنتج كمقاس واحد افتراضي.
            </p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', maxHeight: '180px', overflowY: 'auto', paddingRight: '2px' }}>
              {form.sizes.map((sz: any, sIdx: number) => (
                <div key={sIdx} style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr 1fr 1.5fr auto', gap: '0.3rem', alignItems: 'center' }}>
                  <input
                    className="input input-sm"
                    style={{ fontSize: '0.78rem', height: '30px', padding: '0.2rem 0.4rem' }}
                    placeholder="المقاس (صغير)"
                    value={sz.size_name}
                    onChange={(e) => {
                      const newSizes = [...form.sizes]
                      newSizes[sIdx].size_name = e.target.value
                      setForm((p: any) => ({ ...p, sizes: newSizes }))
                    }}
                    required
                  />
                  <NumericInput
                    className="input input-sm"
                    style={{ fontSize: '0.78rem', height: '30px', padding: '0.2rem 0.4rem' }}
                    step="0.01"
                    placeholder="سعر البيع"
                    value={sz.price}
                    onChange={(e) => {
                      const newSizes = [...form.sizes]
                      newSizes[sIdx].price = e.target.value
                      setForm((p: any) => ({ ...p, sizes: newSizes }))
                    }}
                    required
                  />
                  <NumericInput
                    className="input input-sm"
                    style={{ fontSize: '0.78rem', height: '30px', padding: '0.2rem 0.4rem' }}
                    step="0.01"
                    placeholder="تكلفة"
                    value={sz.cost}
                    onChange={(e) => {
                      const newSizes = [...form.sizes]
                      newSizes[sIdx].cost = e.target.value
                      setForm((p: any) => ({ ...p, sizes: newSizes }))
                    }}
                  />
                  <input
                    className="input input-sm"
                    style={{ fontSize: '0.78rem', height: '30px', padding: '0.2rem 0.4rem' }}
                    placeholder="باركود (تلقائي)"
                    value={sz.barcode}
                    onChange={(e) => {
                      const newSizes = [...form.sizes]
                      newSizes[sIdx].barcode = e.target.value
                      setForm((p: any) => ({ ...p, sizes: newSizes }))
                    }}
                  />
                  <button
                    type="button"
                    className="btn btn-ghost btn-icon btn-sm"
                    style={{ color: 'var(--danger)', padding: '0.2rem' }}
                    onClick={() => setForm((p: any) => ({
                      ...p,
                      sizes: p.sizes.filter((_: any, j: number) => j !== sIdx)
                    }))}
                  >
                    <X size={14} />
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

      </div>

      {/* ── العمود الأيسر ── */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        
        <div>
          <Label>الفئة</Label>
          <CategoryCombobox
            key={modalKey}
            categories={categories}
            value={form.category_id ?? ''}
            onChange={(id: any) => setForm((p: any) => ({ ...p, category_id: id }))}
          />
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.5rem' }}>
          <div>
            <Label>سعر البيع الافتراضي *</Label>
            <NumericInput className="input" step="0.01" min="0" {...f('price')} placeholder="0.00" required />
          </div>
          <div>
            <Label>سعر التكلفة الافتراضي</Label>
            <NumericInput className="input" step="0.01" min="0" {...f('cost')} placeholder="0.00" />
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.5rem' }}>
          <div>
            <Label>{isByWeight ? 'الوزن الحالي (كجم)' : isByLiter ? 'الحجم الحالي (لتر)' : 'الكمية الحالية'}</Label>
            <NumericInput className="input" min="0" step={isByPiece ? '1' : '0.001'} {...f('quantity')} placeholder="0" />
          </div>
          <div>
            <Label>حد التنبيه المنخفض</Label>
            <NumericInput className="input" min="0" {...f('low_stock_threshold')} placeholder="5" />
          </div>
        </div>

        {/* قسم كرتونة البيع بالتجزئة (قطعة فقط) */}
        {isByPiece && (
          <div style={{ background: 'rgba(59,130,246,0.04)', border: '1px dashed var(--border)', borderRadius: 'var(--radius)', padding: '0.6rem' }}>
            <span style={{ fontSize: '0.8rem', fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: '0.35rem', marginBottom: '0.4rem' }}>
              <IconBadge icon={Box} color="secondary" shape="rounded" size={12} badgeSize={22} />
              كرتونة البيع التجميعي
            </span>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.5fr', gap: '0.4rem', marginBottom: '0.4rem' }}>
              <div>
                <Label>قطع/صندوق</Label>
                <NumericInput className="input input-sm" min="1" step="1" {...f('units_per_box')} placeholder="1" />
              </div>
              <div>
                <Label>باركود الصندوق</Label>
                <div style={{ display: 'flex', gap: '0.2rem' }}>
                  <input
                    className="input input-sm"
                    {...f('box_barcode')}
                    placeholder="باركود الكرتونة"
                    style={{
                      width: '100%',
                      borderColor: getBarcodeRowConflict(barcodes, 'box', editingProductId, allProducts, form) ? '#dc2626' : undefined,
                    }}
                  />
                  <button
                    type="button"
                    className="btn btn-ghost btn-icon btn-sm"
                    onClick={() => openBarcodeCamera('box')}
                    title="مسح بالكاميرا"
                  >
                    <Camera size={16} />
                  </button>
                </div>
              </div>
            </div>
            <div style={{ fontSize: '0.74rem', color: 'var(--text-muted)', lineHeight: 1.4 }}>
              {stockBoxesHint}
            </div>
          </div>
        )}

      </div>

    </div>

    {BarcodeScannerLazy && barcodeCameraRow !== null && (
      <BarcodeScannerLazy
        onResult={(text: string) => {
          if (barcodeCameraRow === 'box') {
            setForm((p: any) => ({ ...p, box_barcode: text }))
          } else {
            setBarcodeAt(barcodeCameraRow, text)
          }
          setBarcodeCameraRow(null)
          toast.success('تمت قراءة الباركود')
        }}
        onClose={() => setBarcodeCameraRow(null)}
      />
    )}
    </>
  )
}
