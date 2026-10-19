import { useState, useEffect } from 'react'
import { Plus, Edit2, Trash2 } from 'lucide-react'
import api from '../../api/axios'
import useAuthStore from '../../store/authStore'
import { useConfirmStore } from '../../store/confirmStore'

export default function ExpenseCategoriesTab() {
  const [categories, setCategories] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [editingCat, setEditingCat] = useState<any>(null)
  const [name, setName] = useState('')
  const user = useAuthStore(s => s.user)
  const { confirm } = useConfirmStore()
  const isAdmin = user?.role === 'admin'

  const loadCategories = async () => {
    setLoading(true)
    try {
      const res = await api.get('/expense-categories')
      setCategories(res.data.data ?? [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadCategories()
  }, [])

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim()) return
    try {
      if (editingCat) {
        await api.put(`/expense-categories/${editingCat.id}`, { name })
      } else {
        await api.post('/expense-categories', { name })
      }
      setShowModal(false)
      loadCategories()
    } catch (err) { // error handled globally
    }
  }

  const handleDelete = async (id: number | string) => {
    if (!(await confirm('هل أنت متأكد من حذف هذا التصنيف؟'))) return
    try {
      await api.delete(`/expense-categories/${id}`)
      loadCategories()
    } catch (err) { // error handled globally
    }
  }

  return (
    <div className="card" style={{ padding: '1rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h2 style={{ fontSize: '1rem', fontWeight: 600 }}>إدارة تصنيفات المصروفات</h2>
        {isAdmin && (
          <button className="btn btn-primary btn-sm" onClick={() => { setEditingCat(null); setName(''); setShowModal(true); }}>
            <Plus size={14}/> تصنيف جديد
          </button>
        )}
      </div>

      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>اسم التصنيف</th>
              {isAdmin && <th></th>}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={3} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
            ) : categories.length === 0 ? (
              <tr><td colSpan={3} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد تصنيفات</td></tr>
            ) : categories.map((c, i) => (
              <tr key={c.id}>
                <td style={{ color: 'var(--text-muted)', width: '50px' }}>{i + 1}</td>
                <td style={{ fontWeight: 600 }}>{c.name}</td>
                {isAdmin && (
                  <td style={{ width: '100px' }}>
                    <div style={{ display: 'flex', gap: '0.3rem' }}>
                      <button className="btn btn-ghost btn-icon" onClick={() => { setEditingCat(c); setName(c.name); setShowModal(true); }}><Edit2 size={14}/></button>
                      <button className="btn btn-ghost btn-icon" style={{ color: 'var(--danger)' }} onClick={() => handleDelete(c.id)}><Trash2 size={14}/></button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showModal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setShowModal(false)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <h2 style={{ fontSize: '1.1rem', fontWeight: 700, marginBottom: '1rem' }}>
              {editingCat ? 'تعديل التصنيف' : 'تصنيف جديد'}
            </h2>
            <form onSubmit={handleSave} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <div className="form-group">
                <label>اسم التصنيف *</label>
                <input type="text" className="input" value={name} onChange={e => setName(e.target.value)} required autoFocus />
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'center' }}>
                <button type="button" className="btn btn-ghost" onClick={() => setShowModal(false)}>إلغاء</button>
                <button type="submit" className="btn btn-primary">حفظ</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
