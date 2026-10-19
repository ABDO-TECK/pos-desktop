import { useState, useEffect, type ChangeEvent } from 'react'

import { Plus, Pencil, Trash2, X } from 'lucide-react'
import { getUsers, createUser, updateUser, deleteUser } from '../api/endpoints'
import useAuthStore from '../store/authStore'
import { formatDate } from '../utils/formatters'
import toast from 'react-hot-toast'

import { useConfirmStore } from '../store/confirmStore'
import { extractApiError } from '../utils/apiError'

type UserForm = Pick<User, 'name' | 'email' | 'role'> & {
  password: string
  current_password: string
  is_active: 0 | 1
}

type UserTextField = 'name' | 'email' | 'password' | 'current_password' | 'role'

const emptyForm: UserForm = {
  name: '',
  email: '',
  password: '',
  current_password: '',
  role: 'cashier',
  is_active: 1,
}

export default function Users() {
  const [users, setUsers] = useState<User[]>([])
  const [modal, setModal] = useState<'form' | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [editId, setEditId] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)
  const { user: me, setUser, requireReauthentication } = useAuthStore()
  const { confirm } = useConfirmStore()

  const load = async () => {
    const res = await getUsers()
    setUsers(res.data.data)
  }

  useEffect(() => { load() }, [])

  const openCreate = () => { setForm(emptyForm); setEditId(null); setModal('form') }
  const openEdit = (user: User) => {
    setForm({
      name: user.name,
      email: user.email,
      password: '',
      current_password: '',
      role: user.role,
      is_active: user.is_active ?? 1,
    })
    setEditId(user.id)
    setModal('form')
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editId) {
        const payload: UserUpdatePayload = {
          name: form.name,
          email: form.email,
          role: form.role,
          is_active: form.is_active,
        }
        if (form.password !== '') {
          payload.password = form.password
          if (editId === me?.id) payload.current_password = form.current_password
        }
        const response = await updateUser(editId, payload)
        toast.success('تم التحديث')
        if (response.data.requires_reauthentication) {
          await requireReauthentication()
          return
        }
        if (editId === me?.id) setUser({ ...me, ...response.data.data })
      }
      else {
        await createUser({
          name: form.name,
          email: form.email,
          password: form.password,
          role: form.role,
          is_active: form.is_active,
        })
        toast.success('تم إنشاء الحساب')
      }
      setModal(null)
      load()
    } catch (err) { toast.error(extractApiError(err, 'خطأ')) }
    finally { setSaving(false) }
  }

  const handleDelete = async (id: number, name: string) => {
    if (id === me?.id) { toast.error('لا يمكنك حذف حسابك الخاص'); return }
    if (!(await confirm(`حذف "${name}"؟`))) return
    await deleteUser(id)
    toast.success('تم الحذف')
    load()
  }

  const fieldProps = (key: UserTextField) => ({
    value: form[key],
    onChange: (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
      setForm((previous) => ({ ...previous, [key]: event.target.value })),
  })

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div className="page-header">
        <h2>إدارة المستخدمين</h2>
        <button className="btn btn-primary" onClick={openCreate}><Plus size={16} /> إضافة مستخدم</button>
      </div>

      <div className="card">
        <div className="table-wrapper">
          <table>
            <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th>تاريخ الإنشاء</th><th>الإجراءات</th></tr></thead>
            <tbody>
              {users.length === 0 ? (
                <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا يوجد مستخدمون</td></tr>
              ) : users.map((u) => (
                <tr key={u.id} style={{ opacity: !u.is_active ? 0.55 : 1 }}>
                  <td style={{ fontWeight: 600 }}>{u.name} {u.id === me?.id && <span className="badge badge-blue" style={{ fontSize: '0.7rem' }}>أنت</span>}</td>
                  <td>{u.email}</td>
                  <td><span className={`badge ${u.role === 'admin' ? 'badge-blue' : 'badge-gray'}`}>{u.role === 'admin' ? 'مدير' : 'كاشير'}</span></td>
                  <td><span className={`badge ${u.is_active ? 'badge-green' : 'badge-red'}`}>{u.is_active ? 'نشط' : 'موقوف'}</span></td>
                  <td style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>{formatDate(u.created_at)}</td>
                  <td>
                    <div style={{ display: 'flex', gap: '0.4rem' }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => openEdit(u)}><Pencil size={13} /></button>
                      {u.id !== me?.id && (
                        <button className="btn btn-danger btn-sm" onClick={() => handleDelete(u.id, u.name)}><Trash2 size={13} /></button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal === 'form' && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setModal(null)}>
          <div className="modal">
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{editId ? 'تعديل المستخدم' : 'إضافة مستخدم'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setModal(null)}><X size={18} /></button>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div>
                <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>الاسم *</label>
                <input className="input" {...fieldProps('name')} />
              </div>
              <div>
                <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>البريد الإلكتروني *</label>
                <input className="input" type="email" {...fieldProps('email')} />
              </div>
              <div>
                <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
                  كلمة المرور {editId ? '(اتركها فارغة لعدم التغيير)' : '*'}
                </label>
                <input className="input" type="password" {...fieldProps('password')} />
              </div>
              {editId === me?.id && form.password !== '' && (
                <div>
                  <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
                    كلمة المرور الحالية *
                  </label>
                  <input
                    className="input"
                    type="password"
                    autoComplete="current-password"
                    required
                    {...fieldProps('current_password')}
                  />
                </div>
              )}
              <div className="resp-2col">
                <div>
                  <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>الدور</label>
                  <select className="input" {...fieldProps('role')}>
                    <option value="cashier">كاشير</option>
                    <option value="admin">مدير</option>
                  </select>
                </div>
                <div>
                  <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>الحالة</label>
                  <select className="input" value={form.is_active} onChange={(e) => setForm((p) => ({ ...p, is_active: parseInt(e.target.value) as 0 | 1 }))}>
                    <option value={1}>نشط</option>
                    <option value={0}>موقوف</option>
                  </select>
                </div>
              </div>
            </div>
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleSave} disabled={saving}>
                {saving ? <span className="spinner" /> : null} حفظ
              </button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
