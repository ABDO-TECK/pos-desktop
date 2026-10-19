import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Store, Eye, EyeOff, Moon, Sun } from 'lucide-react'
import useAuthStore from '../store/authStore'
import useThemeStore from '../store/themeStore'
import toast from 'react-hot-toast'
import styles from './Login.module.css'
import { extractApiError } from '../utils/apiError'
import { recoverPassword } from '../api/endpoints'

export default function Login() {
  const [form, setForm] = useState({ email: '', password: '' })
  const [showPass, setShowPass] = useState(false)
  const [loading, setLoading] = useState(false)
  const [recoveryOpen, setRecoveryOpen] = useState(false)
  const [recoveryEmail, setRecoveryEmail] = useState('')
  const [recoveryPassword, setRecoveryPassword] = useState('')
  const [recoveryConfirm, setRecoveryConfirm] = useState('')
  const [recoveryLoading, setRecoveryLoading] = useState(false)
  const [initialAdmin, setInitialAdmin] = useState<{
    email: string;
    name: string;
    password: string;
    forcePasswordChange: boolean;
  } | null>(null)
  const { login, isAuthenticated, _hasHydrated } = useAuthStore()
  const themeMode = useThemeStore((s) => s.mode)
  const toggleTheme = useThemeStore((s) => s.toggle)
  const navigate = useNavigate()

  useEffect(() => {
    const setup = window.electronAPI?.setup
    if (!setup) return
    setup.getInitialAdmin()
      .then((credentials) => {
        if (credentials?.email && credentials.password) setInitialAdmin(credentials)
      })
      .catch(() => {
        // A setup notice is optional; failure must not block normal login.
      })
  }, [])

  // Redirect if already logged in
  useEffect(() => {
    if (_hasHydrated && isAuthenticated) {
      navigate('/', { replace: true })
    }
  }, [_hasHydrated, isAuthenticated])

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setLoading(true)
    try {
      await login(form.email, form.password)
      toast.success('مرحبًا بك!')
      navigate('/')
    } catch (err) {
      toast.error(extractApiError(err, 'بيانات غير صحيحة'))
    } finally {
      setLoading(false)
    }
  }

  const openRecovery = () => {
    setRecoveryEmail(form.email.trim())
    setRecoveryPassword('')
    setRecoveryConfirm('')
    setRecoveryOpen(true)
  }

  const handleRecovery = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (recoveryPassword.length < 8 || !/\d/.test(recoveryPassword) || !/[A-Za-z\u0600-\u06ff]/.test(recoveryPassword)) {
      toast.error('كلمة المرور يجب أن تحتوي على 8 أحرف ورقم وحرف واحد على الأقل')
      return
    }
    if (recoveryPassword !== recoveryConfirm) {
      toast.error('تأكيد كلمة المرور غير مطابق')
      return
    }
    setRecoveryLoading(true)
    try {
      await recoverPassword({ email: recoveryEmail.trim(), password: recoveryPassword })
      toast.success('تم تغيير كلمة المرور. يمكنك تسجيل الدخول الآن')
      setRecoveryOpen(false)
      setForm({ email: recoveryEmail.trim(), password: '' })
    } catch (err: unknown) {
      toast.error(extractApiError(err, 'فشل استعادة كلمة المرور'))
    } finally {
      setRecoveryLoading(false)
    }
  }

  const dismissInitialAdmin = async () => {
    setInitialAdmin(null)
    try {
      await window.electronAPI?.setup?.acknowledgeInitialAdmin()
    } catch {
      // The one-time notice is held in the desktop process and can be shown
      // again after a reload if acknowledgement fails.
    }
  }

  const copyInitialPassword = async () => {
    if (!initialAdmin) return
    try {
      await navigator.clipboard.writeText(initialAdmin.password)
      toast.success('تم نسخ كلمة المرور المؤقتة')
    } catch {
      toast.error('تعذر نسخ كلمة المرور، انسخها يدوياً')
    }
  }

  return (
    <div className={styles.page}>
      <button
        type="button"
        className={`${styles.themeToggle} no-drag`}
        onClick={toggleTheme}
        aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
        title={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
      >
        {themeMode === 'dark' ? <Sun size={20} /> : <Moon size={20} />}
      </button>
      <div className="card no-drag" style={{ width: '380px', padding: '2rem', maxWidth: 'calc(100vw - 2rem)' }}>
        <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
          <Store size={40} color="var(--primary)" style={{ margin: '0 auto 0.75rem' }} />
          <h1 style={{ fontSize: '1.4rem', fontWeight: 700 }}>نظام الكاشير</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>تسجيل الدخول للمتابعة</p>
        </div>

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.35rem' }}>البريد الإلكتروني</label>
            <input
              type="email" className="input" required
              value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}
              placeholder="name@example.com"
            />
          </div>

          <div>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.35rem' }}>كلمة المرور</label>
            <div style={{ position: 'relative' }}>
              <input
                type={showPass ? 'text' : 'password'} className="input" required
                value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}
                placeholder="••••••••"
                style={{ paddingLeft: '2.5rem' }}
              />
              <button
                type="button"
                onClick={() => setShowPass(!showPass)}
                style={{ position: 'absolute', top: '50%', left: '0.75rem', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}
              >
                {showPass ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </div>

          <button type="submit" className="btn btn-primary btn-lg" style={{ justifyContent: 'center', marginTop: '0.5rem' }} disabled={loading}>
            {loading ? <span className="spinner" /> : null}
            تسجيل الدخول
          </button>
          <button type="button" className="btn btn-ghost" onClick={openRecovery} disabled={loading}>
            نسيت كلمة المرور؟
          </button>
        </form>

      </div>

      {initialAdmin && (
        <div className="modal-overlay" role="presentation">
          <div className="modal" role="dialog" aria-modal="true" aria-labelledby="initial-admin-title">
            <h2 id="initial-admin-title" style={{ marginTop: 0 }}>بيانات المدير الأول</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem', lineHeight: 1.6 }}>
              تم إنشاء حساب مدير محلي لأول تشغيل. احفظ هذه البيانات الآن؛ ستُطلب منك كلمة مرور جديدة بعد تسجيل الدخول.
            </p>
            <div style={{ display: 'grid', gap: '0.65rem', margin: '1rem 0' }}>
              <label style={{ fontSize: '0.85rem', fontWeight: 600 }}>البريد الإلكتروني</label>
              <code dir="ltr" style={{ padding: '0.65rem', borderRadius: '6px', background: 'var(--bg)', userSelect: 'all' }}>
                {initialAdmin.email}
              </code>
              <label style={{ fontSize: '0.85rem', fontWeight: 600 }}>كلمة المرور المؤقتة</label>
              <code dir="ltr" style={{ padding: '0.65rem', borderRadius: '6px', background: 'var(--bg)', userSelect: 'all', wordBreak: 'break-all' }}>
                {initialAdmin.password}
              </code>
            </div>
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <button type="button" className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={copyInitialPassword}>
                نسخ كلمة المرور
              </button>
              <button type="button" className="btn btn-ghost" onClick={dismissInitialAdmin}>
                فهمت
              </button>
            </div>
          </div>
        </div>
      )}

      {recoveryOpen && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && !recoveryLoading && setRecoveryOpen(false)}>
          <div className="modal" role="dialog" aria-modal="true" aria-labelledby="password-recovery-title">
            <h2 id="password-recovery-title" style={{ marginTop: 0 }}>استعادة كلمة المرور</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
              هذه الميزة تعمل من جهاز سطح المكتب المحلي فقط. سيؤدي التغيير إلى تسجيل خروج الحساب من كل الأجهزة.
            </p>
            <form onSubmit={handleRecovery} style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <input
                className="input"
                type="email"
                required
                autoComplete="username"
                value={recoveryEmail}
                onChange={(e) => setRecoveryEmail(e.target.value)}
                placeholder="name@example.com"
              />
              <input
                className="input"
                type="password"
                required
                autoComplete="new-password"
                value={recoveryPassword}
                onChange={(e) => setRecoveryPassword(e.target.value)}
                placeholder="كلمة المرور الجديدة"
              />
              <input
                className="input"
                type="password"
                required
                autoComplete="new-password"
                value={recoveryConfirm}
                onChange={(e) => setRecoveryConfirm(e.target.value)}
                placeholder="تأكيد كلمة المرور"
              />
              <div style={{ display: 'flex', gap: '0.5rem' }}>
                <button type="submit" className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} disabled={recoveryLoading}>
                  {recoveryLoading ? <span className="spinner" /> : null} تغيير كلمة المرور
                </button>
                <button type="button" className="btn btn-ghost" onClick={() => setRecoveryOpen(false)} disabled={recoveryLoading}>
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
