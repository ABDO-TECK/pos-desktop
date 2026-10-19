import { useRef, useEffect, useState, useCallback, type ChangeEvent, type KeyboardEvent, type ComponentType } from 'react'
import { Scan, Camera } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useProductStore from '../../store/productStore'
import toast from 'react-hot-toast'
import { extractApiError } from '../../utils/apiError'

type BarcodeScannerProps = { onResult: (text: string) => void; onClose: () => void }

const INTERACTIVE_TARGET_SELECTOR = [
  'input',
  'textarea',
  'select',
  'button',
  'a',
  'label',
  'form',
  '[contenteditable="true"]',
  '[role="button"]',
  '[role="dialog"]',
  '[aria-modal="true"]',
  '.modal-overlay',
  '.sidebar-overlay',
].join(', ')

const beep = () => {
  try {
    const AudioContextConstructor = window.AudioContext
      ?? (window as Window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!AudioContextConstructor) return
    const ctx = new AudioContextConstructor()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.frequency.value = 880
    osc.type = 'sine'
    gain.gain.setValueAtTime(0.3, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + 0.15)
  } catch (err) { }
}

// بعد توقف الماسح قليلاً نفّذ البحث (لا نعطّل الحقل أثناء الطلب حتى لا يُفقد التركيز)
const SCANNER_DEBOUNCE = 280

/**
 * @param {object} props
 * @param {(q: string) => void} [props.onFilterChange]
 * @param {(product: object) => void} [props.onAddProduct] — بدل الإضافة الافتراضية للسلة (مثلاً استلام بضاعة)
 * @param {boolean} [props.allowOutOfStock] — السماح بإضافة منتج نافد المخزون (للموردين)
 */
export default function BarcodeInput({ onFilterChange, onAddProduct, allowOutOfStock = false }: { onFilterChange?: (value: string) => void, onAddProduct?: (product: Product) => void, allowOutOfStock?: boolean }) {
  const inputRef      = useRef<HTMLInputElement>(null)
  const debounceTimer = useRef<number>(0)
  const animationFrame = useRef<number | null>(null)
  const lastTypeTime  = useRef(0)
  const typeCount     = useRef(0)
  const busyRef       = useRef(false)

  const [value, setValue]     = useState('')
  const [loading, setLoading] = useState(false)
  const [showCameraScanner, setShowCameraScanner] = useState(false)
  const [BarcodeScannerLazy, setBarcodeScannerLazy] = useState<ComponentType<BarcodeScannerProps> | null>(null)

  const addItem       = useCartStore((s) => s.addItem)
  const findByBarcode = useProductStore((s) => s.findByBarcode)

  const isDesktop = typeof window !== 'undefined' && window.innerWidth >= 1024;

  // إعادة التركيز عند النقر خارج حقول الإدخال (لا نسرق التركيز من مودال أو حقل آخر)
  useEffect(() => {
    if (!isDesktop) return;
    const refocus = (e: globalThis.MouseEvent) => {
      const target = e.target instanceof Element ? e.target : null
      if (target?.closest(INTERACTIVE_TARGET_SELECTOR)) return
      if (document.querySelector('[role="dialog"], [aria-modal="true"]')) return
      inputRef.current?.focus({ preventScroll: true })
    }
    inputRef.current?.focus({ preventScroll: true })
    document.addEventListener('click', refocus)
    return () => document.removeEventListener('click', refocus)
  }, [isDesktop])

  useEffect(() => () => {
    clearTimeout(debounceTimer.current)
    if (animationFrame.current !== null) {
      cancelAnimationFrame(animationFrame.current)
      animationFrame.current = null
    }
  }, [])

  const handleSearch = useCallback(async (barcode: string) => {
    const trimmed = barcode.trim()
    if (!trimmed) return

    if (busyRef.current) {
      clearTimeout(debounceTimer.current)
      debounceTimer.current = setTimeout(() => {
        handleSearch((inputRef.current?.value ?? '').trim() || trimmed)
      }, 90)
      return
    }

    busyRef.current = true
    const snapshot = trimmed

    setLoading(true)
    let product: Product | null = null
    try {
      product = await findByBarcode(trimmed)
    } finally {
      setLoading(false)
    }

    if (product) {
      if (!allowOutOfStock && product.quantity <= 0) {
        toast.error(`${product.name} — نفد من المخزون`, { icon: '⚠️' })
      } else {
        if (onAddProduct) {
          onAddProduct(product)
        } else {
          addItem({
            id: product.id,
            name: product.name,
            barcode: product.barcode,
            price: product.price,
            units_per_box: product.units_per_box,
            sell_by_weight: product.sell_by_weight,
            unit_type: product.unit_type,
            size_name: product.size_name,
          })
        }
        beep()
        toast.success(product.name, { duration: 1200, icon: '✅' })
      }
    } else {
      toast.error('المنتج غير موجود', { icon: '❌' })
    }

    // تحديد القيمة التالية للحقل
    const currentValue = inputRef.current?.value ?? ''
    const nextValue = currentValue.trim() === snapshot ? '' : currentValue

    setValue(nextValue)
    onFilterChange?.(nextValue)

    typeCount.current = 0
    busyRef.current = false

    if (animationFrame.current !== null) cancelAnimationFrame(animationFrame.current)
    animationFrame.current = requestAnimationFrame(() => {
      animationFrame.current = null
      const el = inputRef.current
      if (isDesktop) el?.focus()
      const rest = (el?.value ?? '').trim()
      if (rest && rest !== snapshot) {
        clearTimeout(debounceTimer.current)
        debounceTimer.current = setTimeout(() => handleSearch(rest), SCANNER_DEBOUNCE)
      }
    })
  }, [addItem, allowOutOfStock, findByBarcode, isDesktop, onAddProduct, onFilterChange])

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const newVal = e.target.value
    setValue(newVal)
    onFilterChange?.(newVal)

    if (!newVal.trim()) return

    const now = Date.now()
    const gap = now - lastTypeTime.current
    lastTypeTime.current = now

    if (gap < 60) {
      typeCount.current += 1
    } else {
      typeCount.current = 1
    }

    clearTimeout(debounceTimer.current)

    // ≥3 أحرف بسرعة = غالباً ماسح (يدعم باركودات قصيرة)
    if (typeCount.current >= 3) {
      debounceTimer.current = setTimeout(() => {
        handleSearch(newVal)
      }, SCANNER_DEBOUNCE)
    }
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      clearTimeout(debounceTimer.current)
      handleSearch(value)
    }
    if (e.key === 'Escape') {
      setValue('')
      onFilterChange?.('')
      typeCount.current = 0
    }
  }

  const openCameraScanner = async () => {
    try {
      if (!BarcodeScannerLazy) {
        const m = await import('../BarcodeCameraScanner')
        setBarcodeScannerLazy(() => m.default)
      }
      setShowCameraScanner(true)
    } catch (err) { toast.error(extractApiError(err, 'تعذر تحميل ماسح الباركود')) }
  }

  const handleCameraScan = (text: string) => {
    setShowCameraScanner(false)
    setValue(text)
    onFilterChange?.(text)
    handleSearch(text)
  }

  return (
    <>
    <div style={{ position: 'relative', display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
      <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
        <Scan
          size={20}
          style={{
            position: 'absolute', top: '50%', transform: 'translateY(-50%)',
            right: '1rem', color: loading ? 'var(--primary)' : 'var(--text-muted)',
            transition: 'color .2s',
            pointerEvents: 'none',
          }}
        />
        {loading && (
          <span
            className="spinner"
            style={{
              position: 'absolute', top: '50%', left: '1rem',
              transform: 'translateY(-50%)',
              width: '1rem', height: '1rem',
            }}
          />
        )}
        <input
          id="main-barcode-input"
          ref={inputRef}
          className="input input-lg"
          style={{ paddingRight: '2.8rem', paddingLeft: loading ? '2.8rem' : '1rem' }}
          placeholder="امسح الباركود، أو اكتب لتصفية المنتجات فوراً، ثم Enter لإضافة بالباركود…"
          value={value}
          onChange={handleChange}
          onKeyDown={handleKeyDown}
          autoComplete="off"
          autoFocus={isDesktop}
          /* مهم: لا تستخدم disabled أثناء التحميل — في المتصفح يزيل التركيز ويقطع المسح المتكرر */
        />
      </div>
      <button
        type="button"
        className="btn btn-ghost btn-icon"
        style={{ flexShrink: 0, padding: '0.55rem' }}
        title="مسح الباركود بالكاميرا"
        aria-label="مسح الباركود بالكاميرا"
        onClick={openCameraScanner}
      >
        <Camera size={20} />
      </button>
    </div>

    {BarcodeScannerLazy && showCameraScanner && (
      <BarcodeScannerLazy
        onResult={handleCameraScan}
        onClose={() => setShowCameraScanner(false)}
      />
    )}
    </>
  )
}
