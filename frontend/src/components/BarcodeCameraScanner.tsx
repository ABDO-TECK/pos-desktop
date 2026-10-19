
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { BrowserMultiFormatReader, BrowserCodeReader } from '@zxing/browser'
import { DecodeHintType } from '@zxing/library'
import { X } from 'lucide-react'
import styles from './BarcodeScanner.module.css'

interface LegacyNavigator extends Navigator {
  getUserMedia?: (
    constraints: MediaStreamConstraints | undefined,
    success: (stream: MediaStream) => void,
    error: (err: unknown) => void
  ) => void
  webkitGetUserMedia?: (
    constraints: MediaStreamConstraints | undefined,
    success: (stream: MediaStream) => void,
    error: (err: unknown) => void
  ) => void
  mozGetUserMedia?: (
    constraints: MediaStreamConstraints | undefined,
    success: (stream: MediaStream) => void,
    error: (err: unknown) => void
  ) => void
  msGetUserMedia?: (
    constraints: MediaStreamConstraints | undefined,
    success: (stream: MediaStream) => void,
    error: (err: unknown) => void
  ) => void
}

interface CameraCapabilities extends MediaTrackCapabilities {
  focusMode?: string[]
}

/**
 * على HTTP (ما عدا localhost) المتصفحات الحديثة لا تعرّف `navigator.mediaDevices` — فينهار ZXing عند قراءة getUserMedia.
 * بعض الأجهزة تعرض فقط الواجهة المسبوقة (webkitGetUserMedia).
 */
function ensureCameraApi() {
  if (typeof navigator === 'undefined') return false

  const nav = navigator as LegacyNavigator

  if (typeof nav.mediaDevices?.getUserMedia === 'function') {
    return true
  }

  const legacy =
    nav.getUserMedia ||
    nav.webkitGetUserMedia ||
    nav.mozGetUserMedia ||
    nav.msGetUserMedia

  if (typeof legacy !== 'function') {
    return false
  }

  try {
    if (!nav.mediaDevices) {
      (nav as { mediaDevices?: Partial<MediaDevices> }).mediaDevices = {}
    }
    if (typeof nav.mediaDevices.getUserMedia !== 'function') {
      nav.mediaDevices.getUserMedia = function (constraints) {
        return new Promise((resolve, reject) => {
          legacy.call(nav, constraints, resolve, reject)
        })
      }
    }
  } catch {
    return false
  }

  return true
}

function cameraUnavailableMessage() {
  const host = typeof window !== 'undefined' ? window.location.hostname : ''
  const localhost =
    host === 'localhost' ||
    host === '127.0.0.1' ||
    host === '[::1]'
  const secure =
    (typeof window !== 'undefined' && window.isSecureContext === true) || localhost

  if (!secure) {
    return 'الكاميرا غير متاحة على هذا الرابط لأن الاتصال غير آمن (HTTP). افتح النظام عبر https:// أو استخدم شهادة SSL على الخادم (حتى على الشبكة الداخلية).'
  }

  return 'المتصفح لا يوفّر واجهة الكاميرا هنا. جرّب Chrome أو Safari المحدّث، وتأكد من أذونات الكاميرا للموقع.'
}

/** تلميحات ZXing: أهمها TRY_HARDER لقراءة EAN/Code128 من كاميرا الهاتف */
function buildReaderHints() {
  const hints = new Map()
  hints.set(DecodeHintType.TRY_HARDER, true)
  return hints
}

/**
 * فحص الخطأ بالاسم بدل instanceof — لأن @zxing/library مبنية بـ ES5
 * ما يكسر سلسلة prototype لفئات Error الفرعية ويجعل instanceof يفشل دائمًا.
 * هذا هو السبب الجذري لعدم قراءة الباركود: حلقة scan() الداخلية في المكتبة
 * تستخدم instanceof NotFoundException وعندما تفشل تتوقف الحلقة تمامًا بعد أول إطار.
 */
function isBenignScanError(err: unknown): boolean {
  if (!err || typeof err !== 'object') return false
  const name = (err as { name?: string; constructor?: { name?: string } }).name 
    || (err as { constructor?: { name?: string } }).constructor?.name || ''
  return (
    name === 'NotFoundException' ||
    name === 'ChecksumException' ||
    name === 'FormatException'
  )
}

/**
 * قيود فيديو أقوى لزيادة دقة الإطار (تحسين قراءة الباركود الخطي).
 * إن رفضها الجهاز نرجع لقيود أبسط.
 */
const RICH_VIDEO_CONSTRAINTS = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { min: 480, ideal: 1280 },
    height: { min: 360, ideal: 720 },
  },
}

const SIMPLE_VIDEO_CONSTRAINTS = {
  video: { facingMode: 'environment' },
}

/** تأخير بين محاولات المسح (بالمللي ثانية) */
const SCAN_INTERVAL = 80
/** تأخير بعد مسح ناجح */
const SCAN_SUCCESS_DELAY = 300

/**
 * ملء الشاشة فوق المودال: قراءة باركود/QR من كاميرا الجهاز (مفيد على الهاتف دون قارئ).
 * يُعرض عبر portal على document.body حتى لا يُقصّه overflow المودال.
 *
 * ملاحظة مهمة: لا نستخدم decodeFromConstraints/scan الداخلية للمكتبة لأنها تعتمد
 * على instanceof لفحص NotFoundException — وهذا يفشل في بنية ES5 ويوقف حلقة المسح.
 * بدلاً من ذلك ننشئ حلقة مسح يدوية تتحكم بكل شيء بنفسها.
 */
interface BarcodeCameraScannerProps {
  onResult: (barcode: string) => void
  onClose: () => void
}

export default function BarcodeCameraScanner({ onResult, onClose }: BarcodeCameraScannerProps) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const onResultRef = useRef(onResult)
  const cameraAvailable = ensureCameraApi()

  useEffect(() => {
    onResultRef.current = onResult
  }, [onResult])

  const [error, setError] = useState<string | null>(
    cameraAvailable ? null : cameraUnavailableMessage(),
  )
  const [starting, setStarting] = useState(cameraAvailable)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return undefined

    if (!cameraAvailable) return undefined

    const reader = new BrowserMultiFormatReader(buildReaderHints())
    let finished = false
    let scanTimer: ReturnType<typeof setTimeout> | null = null
    let activeStream: MediaStream | null = null

    const stopAll = () => {
      finished = true
      if (scanTimer !== null) clearTimeout(scanTimer)
      if (activeStream) {
        activeStream.getTracks().forEach((t) => {
          try { t.stop() } catch { /* ignore */ }
        })
        activeStream = null
      }
      try { BrowserCodeReader.releaseAllStreams() } catch { /* ignore */ }
      const v = videoRef.current
      if (v?.srcObject) {
        ;(v.srcObject as MediaStream).getTracks().forEach((t: MediaStreamTrack) => {
          try { t.stop() } catch { /* ignore */ }
        })
        v.srcObject = null
      }
    }

    /**
     * حلقة المسح اليدوية — تأخذ لقطة من الفيديو وتحاول فك تشفيرها.
     * تستخدم فحص الاسم بدل instanceof لتحديد الأخطاء «العادية».
     */
    const startManualScanLoop = () => {
      // إنشاء canvas لالتقاط الإطارات
      let canvas: HTMLCanvasElement | null = null
      let ctx: CanvasRenderingContext2D | null = null

      const ensureCanvas = () => {
        const vw = video?.videoWidth
        const vh = video?.videoHeight
        if (!vw || !vh) return false // الفيديو لم يتحمل بعد
        if (!canvas || canvas.width !== vw || canvas.height !== vh) {
          canvas = document.createElement('canvas')
          canvas.width = vw
          canvas.height = vh
          try {
            ctx = canvas.getContext('2d', { willReadFrequently: true })
          } catch {
            ctx = canvas.getContext('2d')
          }
        }
        return !!ctx
      }

      const loop = () => {
        if (finished) return
        try {
          if (!ensureCanvas()) {
            // الفيديو لم يبدأ بعد — نحاول مرة أخرى
            scanTimer = setTimeout(loop, SCAN_INTERVAL)
            return
          }
          if (ctx && canvas && video) ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
          const result = canvas ? reader.decodeFromCanvas(canvas) : null
          // نجحت القراءة!
          if (result) {
            const text = result.getText()?.trim()
            if (text && !finished) {
              finished = true
              stopAll()
              onResultRef.current(text)
              return
            }
          }
          // نجاح لكن بدون نص — نحاول مرة أخرى
          scanTimer = setTimeout(loop, SCAN_SUCCESS_DELAY)
        } catch (err: unknown) {
          if (isBenignScanError(err)) {
            // لم يُعثر على باركود في هذا الإطار — عادي، نحاول مرة أخرى
            scanTimer = setTimeout(loop, SCAN_INTERVAL)
          } else {
            // خطأ غير متوقع — نسجله ونستمر بالمحاولة
            console.warn('[BarcodeCameraScanner] خطأ أثناء المسح:', err)
            scanTimer = setTimeout(loop, SCAN_INTERVAL)
          }
        }
      }

      loop()
    }

    const startCamera = async () => {
      let stream = null

      // نحاول أولاً بقيود متقدمة (دقة عالية)
      try {
        stream = await navigator.mediaDevices.getUserMedia(RICH_VIDEO_CONSTRAINTS)
      } catch (richErr: unknown) {
        const name = (richErr as { name?: string })?.name || ''
        if (name === 'OverconstrainedError' || name === 'ConstraintNotSatisfiedError') {
          // القيود المتقدمة فشلت — نجرب قيود بسيطة
          try {
            stream = await navigator.mediaDevices.getUserMedia(SIMPLE_VIDEO_CONSTRAINTS)
          } catch (simpleErr) {
            throw simpleErr
          }
        } else {
          throw richErr
        }
      }

      if (finished && stream) {
        // المكون أُغلق أثناء انتظار الكاميرا
        stream.getTracks().forEach((t) => t.stop())
        return
      }

      activeStream = stream

      // تفعيل التركيز المستمر إن أمكن (يحسّن قراءة الباركود على الهواتف)
      try {
        const track = stream?.getVideoTracks()[0]
        if (track) {
          const caps = (typeof track.getCapabilities === 'function' ? track.getCapabilities() : {}) as CameraCapabilities
          if (caps.focusMode && caps.focusMode.includes('continuous')) {
            await track.applyConstraints({ advanced: [{ focusMode: 'continuous' } as MediaTrackConstraintSet] })
          }
        }
      } catch {
        /* ignore — ليس كل الأجهزة تدعم التركيز */
      }

      video.srcObject = stream
      video.setAttribute('autoplay', 'true')
      video.setAttribute('muted', 'true')
      video.setAttribute('playsinline', 'true')

      await video.play()

      // ننتظر حتى يتحمل الفيديو (videoWidth > 0)
      await new Promise<void>((resolve) => {
        if (video.videoWidth > 0 && video.videoHeight > 0) {
          resolve()
          return
        }
        const onLoadedData = () => {
          video.removeEventListener('loadeddata', onLoadedData)
          resolve()
        }
        video.addEventListener('loadeddata', onLoadedData)
        // timeout احتياطي
        setTimeout(resolve, 3000)
      })

      if (!finished) {
        setStarting(false)
        startManualScanLoop()
      }
    }

    startCamera().catch((e: unknown) => {
      if (finished) return
      setStarting(false)
      const err = e as { message?: string; name?: string } | undefined
      const raw = String(err?.message || '')
      const name = err?.name || ''
      let msg = 'تعذر تشغيل الكاميرا.'

      if (
        raw.includes('getUserMedia') ||
        raw.includes('mediaDevices') ||
        raw.includes('undefined')
      ) {
        msg = cameraUnavailableMessage()
      } else if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        msg = 'تم رفض إذن الكاميرا. اسمح بالوصول من إعدادات المتصفح أو أيقونة القفل في شريط العنوان.'
      } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        msg = 'لم يُعثر على كاميرا في هذا الجهاز.'
      } else if (raw.toLowerCase().includes('secure')) {
        msg = 'الكاميرا تتطلب اتصالاً آمناً (HTTPS) في معظم المتصفحات.'
      } else if (err?.message) {
        msg = String(err.message)
      }
      setError(msg)
    })

    return () => {
      stopAll()
    }
  }, [cameraAvailable])

  const ui = (
    <div
      className={`modal-overlay ${styles.overlay}`}
      style={{ zIndex: 1100 }}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="barcode-scanner-title"
    >
      <div
        className={styles.panel}
        onClick={(e) => e.stopPropagation()}
      >
        <div className={styles.toolbar}>
          <h2 id="barcode-scanner-title" className={styles.title}>
            مسح الباركود بالكاميرا
          </h2>
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            onClick={onClose}
            aria-label="إغلاق"
          >
            <X size={20} />
          </button>
        </div>

        <p className={styles.hint}>
          استخدم الكاميرا الخلفية، أبعد الباركود نحو 15–25 سم، وتأكد أن الخطوط واضحة ومضاءة بشكل جيد. للباركود الخطي اجعل الخطوط أفقية قدر الإمكان.
        </p>

        <div className={styles.videoWrap}>
          <video
            ref={videoRef}
            className={styles.video}
            muted
            playsInline
            autoPlay
          />
          <div className={styles.frame} aria-hidden="true" />
          {starting && !error && (
            <div className={styles.loading}>
              <span className="spinner" style={{ width: '2rem', height: '2rem', borderWidth: '3px' }} />
              <span>جاري تشغيل الكاميرا…</span>
            </div>
          )}
        </div>

        {error && (
          <div className={styles.error} role="alert">
            {error}
          </div>
        )}

        <div className={styles.actions}>
          <button type="button" className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>
            إلغاء
          </button>
        </div>
      </div>
    </div>
  )

  return createPortal(ui, document.body)
}
