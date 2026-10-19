import { useState, type FormEvent } from 'react'
import { AlertTriangle, RotateCcw } from 'lucide-react'
import toast from 'react-hot-toast'

const CONFIRMATION_TOKEN = 'RESET_POS_DATA'

export default function FactoryResetSection() {
  const [confirmation, setConfirmation] = useState('')
  const [loading, setLoading] = useState(false)
  const setup = window.electronAPI?.setup

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (confirmation !== CONFIRMATION_TOKEN) {
      toast.error(`اكتب ${CONFIRMATION_TOKEN} للتأكيد`)
      return
    }
    if (!setup?.factoryReset) {
      toast.error('إعادة الضبط متاحة من تطبيق سطح المكتب فقط')
      return
    }

    setLoading(true)
    try {
      const result = await setup.factoryReset({ confirmationToken: CONFIRMATION_TOKEN })
      if (result.cancelled) return
      if (!result.success) {
        toast.error(result.error || 'فشلت إعادة ضبط النظام')
        return
      }
      setConfirmation('')
      toast.success('تمت إعادة ضبط النظام. سيظهر حساب المدير المؤقت عند تسجيل الدخول.')
    } catch {
      toast.error('فشلت إعادة ضبط النظام')
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '0.85rem', border: '1px solid rgba(239, 68, 68, 0.45)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', color: 'var(--danger, #ef4444)' }}>
        <AlertTriangle size={18} />
        <h2 style={{ margin: 0, fontSize: '1rem' }}>إعادة ضبط المصنع</h2>
      </div>
      <p style={{ margin: 0, color: 'var(--text-muted)', fontSize: '0.85rem', lineHeight: 1.6 }}>
        يحذف هذا الإجراء قاعدة البيانات بالكامل (المبيعات والمنتجات والعملاء والموردين والمستخدمين والإعدادات)، ثم يعيد الإعدادات الافتراضية وينشئ حساب مدير مؤقتاً. سيتم الاحتفاظ بملفات النسخ الاحتياطية، بينما سيُمسح سجل الأخطاء وملفات الجلسة.
      </p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <input
          className="input"
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
          placeholder={CONFIRMATION_TOKEN}
          dir="ltr"
          style={{ flex: '1 1 220px' }}
          aria-label="Factory reset confirmation"
          disabled={loading}
        />
        <button type="submit" className="btn btn-danger" disabled={loading || !setup?.factoryReset}>
          {loading ? <span className="spinner" /> : <RotateCcw size={16} />}
          تأكيد الحذف وإعادة الضبط
        </button>
      </form>
      {!setup?.factoryReset && (
        <small style={{ color: 'var(--text-muted)' }}>هذه العملية متاحة من نسخة سطح المكتب فقط.</small>
      )}
    </section>
  )
}
