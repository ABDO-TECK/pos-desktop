import { useState, useEffect } from 'react'
import { Store, Plus, Edit2, Trash2 } from 'lucide-react'
import api from '../api/axios'
import toast from 'react-hot-toast'
import { extractApiError } from '../utils/apiError'

export default function Branches() {
  const [branches, setBranches] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState<'create' | 'edit' | null>(null)
  const [form, setForm] = useState({ id: '', name: '', address: '', phone: '' })
  const [saving, setSaving] = useState(false)

  const loadBranches = async () => {
    setLoading(true)
    try {
      const res = await api.get('/branches')
      setBranches(res.data.data)
    } catch (err) {
      toast.error(extractApiError(err, 'فشل تحميل الفروع'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { loadBranches() }, [])

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.name) return toast.error('اسم الفرع مطلوب')
    setSaving(true)
    try {
      if (modal === 'create') {
        await api.post('/branches', form)
        toast.success('تم إنشاء الفرع')
      } else {
        await api.put(`/branches/${form.id}`, form)
        toast.success('تم تحديث الفرع')
      }
      setModal(null)
      loadBranches()
    } catch (err) {
      toast.error(extractApiError(err, 'فشل الحفظ'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ padding: '1rem', maxWidth: '800px', margin: '0 auto' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <h1 style={{ fontSize: '1.25rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <Store size={20} /> إدارة الفروع
        </h1>
        <button className="btn btn-primary btn-sm" onClick={() => { setForm({ id: '', name: '', address: '', phone: '' }); setModal('create') }}>
          <Plus size={16} /> فرع جديد
        </button>
      </div>

      <div className="card" style={{ padding: '1rem' }}>
        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem' }}>جاري التحميل...</div>
        ) : (
          <table className="table" style={{ width: '100%', textAlign: 'right' }}>
            <thead>
              <tr>
                <th>الاسم</th>
                <th>العنوان</th>
                <th>الهاتف</th>
                <th style={{ width: '100px' }}>الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              {branches.map(b => (
                <tr key={b.id}>
                  <td>{b.name}</td>
                  <td>{b.address || '-'}</td>
                  <td>{b.phone || '-'}</td>
                  <td>
                    <button className="btn btn-ghost btn-sm" onClick={() => { setForm(b); setModal('edit') }}>
                      <Edit2 size={14} />
                    </button>
                  </td>
                </tr>
              ))}
              {branches.length === 0 && (
                <tr>
                  <td colSpan={4} style={{ textAlign: 'center', padding: '2rem' }}>لا توجد فروع</td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {modal && (
        <div className="modal-overlay">
          <div className="modal-content" style={{ maxWidth: '400px' }}>
            <h2 style={{ marginTop: 0 }}>{modal === 'create' ? 'إضافة فرع' : 'تعديل فرع'}</h2>
            <form onSubmit={handleSave} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem' }}>اسم الفرع *</label>
                <input className="input" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} required />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem' }}>العنوان</label>
                <input className="input" value={form.address} onChange={e => setForm({ ...form, address: e.target.value })} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem' }}>الهاتف</label>
                <input className="input" value={form.phone} onChange={e => setForm({ ...form, phone: e.target.value })} />
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem' }}>
                <button type="submit" className="btn btn-primary" disabled={saving}>
                  {saving ? 'جاري الحفظ...' : 'حفظ'}
                </button>
                <button type="button" className="btn btn-secondary" onClick={() => setModal(null)}>إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
