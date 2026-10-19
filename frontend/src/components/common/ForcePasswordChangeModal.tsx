import { useState, type FormEvent } from 'react'
import toast from 'react-hot-toast'
import { updateUser } from '../../api/endpoints'
import useAuthStore from '../../store/authStore'

export default function ForcePasswordChangeModal() {
  const { user, requireReauthentication } = useAuthStore()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [loading, setLoading] = useState(false)

  if (user?.force_password_change !== 1) {
    return null
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (password.length < 8) {
      toast.error('كلمة المرور يجب أن تكون 8 أحرف على الأقل')
      return
    }
    if (password !== confirmPassword) {
      toast.error('كلمات المرور غير متطابقة')
      return
    }

    setLoading(true)
    try {
      const response = await updateUser(user.id, {
        name: user.name,
        email: user.email,
        password,
        current_password: currentPassword,
      })

      if (response.data.requires_reauthentication) {
        sessionStorage.removeItem('fpc_reload')
        toast.success('تم تغيير كلمة المرور. سجّل الدخول مجدداً للمتابعة')
        await requireReauthentication()
      }
    } catch {
      // The shared API error handler displays validation and authentication errors.
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" style={{ zIndex: 9999 }}>
      <div className="modal-content" style={{ maxWidth: '400px' }}>
        <h2 style={{ marginBottom: '1rem', color: 'var(--color-danger)' }}>
          <i className="fa-solid fa-shield-halved" style={{ marginLeft: '0.5rem' }} />
          تغيير كلمة المرور إلزامي
        </h2>
        <p style={{ marginBottom: '1.5rem', color: 'var(--color-text-light)' }}>
          لأسباب أمنية، أدخل كلمة المرور المؤقتة الحالية ثم اختر كلمة مرور جديدة قبل استخدام النظام.
        </p>

        <form onSubmit={handleSubmit}>
          <div className="form-group" style={{ marginBottom: '1rem' }}>
            <label htmlFor="forced-current-password">كلمة المرور المؤقتة الحالية</label>
            <input
              id="forced-current-password"
              type="password"
              className="input"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </div>
          <div className="form-group" style={{ marginBottom: '1rem' }}>
            <label htmlFor="forced-new-password">كلمة المرور الجديدة</label>
            <input
              id="forced-new-password"
              type="password"
              className="input"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="new-password"
              placeholder="••••••••"
              required
              minLength={8}
            />
          </div>
          <div className="form-group" style={{ marginBottom: '1.5rem' }}>
            <label htmlFor="forced-confirm-password">تأكيد كلمة المرور</label>
            <input
              id="forced-confirm-password"
              type="password"
              className="input"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              autoComplete="new-password"
              placeholder="••••••••"
              required
              minLength={8}
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', padding: '0.75rem' }}
            disabled={loading}
          >
            {loading ? <span className="spinner" /> : 'تحديث كلمة المرور والمتابعة'}
          </button>
        </form>
      </div>
    </div>
  )
}
