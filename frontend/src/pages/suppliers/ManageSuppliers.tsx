
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Plus, Search, X } from 'lucide-react'
import toast from 'react-hot-toast'
import { getSuppliers, createSupplier, updateSupplier, deleteSupplier } from '../../api/endpoints'
import { formatCurrency } from '../../utils/formatters'
import { useConfirmStore } from '../../store/confirmStore'
import { extractApiError } from '../../utils/apiError'
import Pagination from '../../components/Pagination'
import NumericInput from '../../components/forms/NumericInput'

interface SupplierForm {
  name: string
  phone: string
  email: string
  address: string
  initial_balance: string
  balance_direction: 'debit' | 'credit'
}

const emptySupplierForm: SupplierForm = {
  name: '',
  phone: '',
  email: '',
  address: '',
  initial_balance: '',
  balance_direction: 'debit',
}

export default function ManageSuppliers() {
  const [suppliers, setSuppliers] = useState<Supplier[]>([])
  const [loading, setLoading]     = useState(false)
  const [showForm, setShowForm]   = useState(false)
  const [editing, setEditing]     = useState<Supplier | null>(null)
  const [form, setForm]           = useState<SupplierForm>(emptySupplierForm)
  const [search, setSearch]       = useState('')
  const [serverSearch, setServerSearch] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const { confirm }               = useConfirmStore()

  const load = useCallback(async (page: number, query: string) => {
    setLoading(true)
    try {
      const response = await getSuppliers({ page, limit: 20, search: query || undefined })
      setSuppliers(Array.isArray(response.data.data) ? response.data.data : [])
      setCurrentPage(response.data.pagination?.page ?? page)
      setTotalPages(response.data.pagination?.pages ?? 1)
    }
    catch (err) { toast.error(extractApiError(err, 'فشل تحميل الموردين')) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => {
    void load(currentPage, serverSearch)
  }, [currentPage, load, serverSearch])

  useEffect(() => () => {
    if (searchTimer.current) clearTimeout(searchTimer.current)
  }, [])

  const handleSearchChange = (value: string) => {
    setSearch(value)
    if (searchTimer.current) clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => {
      setCurrentPage(1)
      setServerSearch(value.trim())
    }, 300)
  }

  const openNew  = () => { setEditing(null); setForm(emptySupplierForm); setShowForm(true) }
  const openEdit = (s: Supplier) => {
    setEditing(s);
    setForm({
      name: s.name, phone: s.phone ?? '', email: s.email ?? '', address: s.address ?? '',
      initial_balance: String(Math.abs(s.initial_balance || 0) || ''),
      balance_direction: (s.initial_balance || 0) < 0 ? 'credit' : 'debit',
    });
    setShowForm(true)
  }

  const handleSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    try {
      const rawBal = parseFloat(form.initial_balance) || 0
      const payload = {
        name: form.name,
        phone: form.phone,
        email: form.email,
        address: form.address,
        initial_balance: form.balance_direction === 'credit' ? -Math.abs(rawBal) : Math.abs(rawBal),
      }
      if (editing) await updateSupplier(editing.id, payload)
      else await createSupplier(payload)
      toast.success(editing ? 'تم التحديث' : 'تمت الإضافة')
      setShowForm(false)
      void load(currentPage, serverSearch)
    } catch (err) { toast.error(extractApiError(err, 'فشلت العملية')) }
  }

  const handleDelete = async (id: number) => {
    if (!(await confirm('حذف هذا المورد؟'))) return
    try { await deleteSupplier(id); toast.success('تم الحذف'); void load(currentPage, serverSearch) }
    catch (err) { toast.error(extractApiError(err, 'فشل الحذف')) }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
        <button onClick={openNew} className="btn btn-primary"><Plus size={16} /> إضافة مورد</button>
        <div style={{ position: 'relative', flex: '1 1 240px' }}>
          <Search size={16} style={{ position: 'absolute', right: '0.7rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input
            type="search"
            className="input"
            style={{ paddingRight: '2.2rem' }}
            value={search}
            onChange={(event) => handleSearchChange(event.target.value)}
            placeholder="ابحث باسم المورد أو الهاتف..."
          />
        </div>
      </div>

      <div className="card">
        {loading ? (
          <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : suppliers.length === 0 ? (
          <div className="empty-state">لا يوجد موردون</div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>الاسم</th>
                  <th className="hide-mobile">الهاتف</th>
                  <th className="hide-mobile">البريد</th>
                  <th className="hide-mobile">العنوان</th>
                  <th>الرصيد</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {suppliers.map(s => {
                  const bal = Number(s.balance) || 0
                  return (
                  <tr key={s.id}>
                    <td style={{ fontWeight: 600 }}>
                      {s.name}
                      {s.phone && <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', fontWeight: 400 }} className="show-mobile">{s.phone}</div>}
                    </td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.phone ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.email ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.address ?? '—'}</td>
                    <td style={{ fontWeight: 700, color: bal > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                      {bal > 0 ? formatCurrency(bal) : '✓ مُسدَّد'}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                        <button onClick={() => openEdit(s)} className="btn btn-ghost btn-sm">تعديل</button>
                        <button onClick={() => handleDelete(s.id)} className="btn btn-danger btn-sm">حذف</button>
                      </div>
                    </td>
                  </tr>
                )})}
              </tbody>
            </table>
            <Pagination current={currentPage} total={totalPages} onPage={setCurrentPage} />
          </div>
        )}
      </div>

      {showForm && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setShowForm(false)}>
          <div className="modal" style={{ maxWidth: '480px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700 }}>{editing ? 'تعديل مورد' : 'إضافة مورد'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setShowForm(false)}><X size={18} /></button>
            </div>
            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              {[
                { key: 'name',    label: 'الاسم *',            type: 'text',  required: true },
                { key: 'phone',   label: 'الهاتف',             type: 'text' },
                { key: 'email',   label: 'البريد الإلكتروني',  type: 'email' },
                { key: 'address', label: 'العنوان',            type: 'text' },
              ].map(fi => (
                <div key={fi.key}>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '0.3rem' }}>{fi.label}</label>
                  <input type={fi.type} className="input" required={fi.required}
                    value={form[fi.key as keyof Pick<SupplierForm, 'name' | 'phone' | 'email' | 'address'>]}
                    onChange={e => setForm({ ...form, [fi.key]: e.target.value })}
                  />
                </div>
              ))}
              <div style={{ background: 'rgba(59,130,246,.06)', border: '1px dashed var(--secondary)', borderRadius: 'var(--radius)', padding: '0.65rem 0.75rem' }}>
                <label className="label">📒 رصيد مبدئي (ج.م)</label>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: '0 0 0.4rem' }}>
                  حدد اتجاه الرصيد المبدئي ثم أدخل المبلغ، وإلا اتركه 0
                </p>
                <div style={{ display: 'flex', gap: '0.35rem', marginBottom: '0.5rem' }}>
                  {([
                    { id: 'debit',  label: '⬅ هو مدين لي',  color: 'var(--danger)',  bg: 'rgba(239,68,68,.1)' },
                    { id: 'credit', label: '➡ أنا مدين له', color: 'var(--secondary)', bg: 'rgba(59,130,246,.1)' },
                  ] as const).map(d => (
                    <button key={d.id} type="button" onClick={() => setForm(f => ({ ...f, balance_direction: d.id }))}
                      style={{
                        flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                        borderRadius: 'var(--radius)',
                        border: `2px solid ${form.balance_direction === d.id ? d.color : 'var(--border)'}`,
                        background: form.balance_direction === d.id ? d.bg : 'var(--surface)',
                        color: form.balance_direction === d.id ? d.color : 'var(--text-muted)',
                        cursor: 'pointer', transition: 'all .15s',
                      }}
                    >{d.label}</button>
                  ))}
                </div>
                <NumericInput className="input" min="0" step="0.01" placeholder="0.00"
                  value={form.initial_balance}
                  onChange={e => setForm(f => ({ ...f, initial_balance: e.target.value }))} />
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
                <button type="submit" className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }}>
                  {editing ? 'حفظ التعديلات' : 'إضافة'}
                </button>
                <button type="button" onClick={() => setShowForm(false)} className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }}>
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
