import { useState, useMemo } from 'react'
import { Pencil, Trash2, Search, Tag } from 'lucide-react'
import { formatNumber } from '../../utils/formatters'
import Pagination from '../../components/Pagination'

interface CategoriesTabProps {
  categories: Category[]
  loadingCategories: boolean
  allProducts: Product[]
  onEditCategory: (category: Category) => void
  onDeleteCategory: (id: number, name: string) => void
}

export default function CategoriesTab({
  categories,
  loadingCategories,
  allProducts,
  onEditCategory,
  onDeleteCategory,
}: CategoriesTabProps) {
  const [categoryTabSearch, setCategoryTabSearch] = useState('')

  const categoryTabQ = categoryTabSearch.trim().toLowerCase()
  const [currentPage, setCurrentPage] = useState(1)

  const filteredCategoriesTab = useMemo(() => {
    if (!categoryTabQ) return categories
    return categories.filter((c) => (c.name || '').toLowerCase().includes(categoryTabQ))
  }, [categories, categoryTabQ])

  const totalPages = Math.ceil(filteredCategoriesTab.length / 15) || 1
  const paginatedCategories = filteredCategoriesTab.slice((currentPage - 1) * 15, currentPage * 15)

  return (
    <>
      <div className="card" style={{ padding: '0.75rem' }}>
        <div style={{ position: 'relative' }}>
          <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)', pointerEvents: 'none' }} />
          <input
            className="input"
            style={{ paddingRight: '2.5rem' }}
            placeholder="بحث في أسماء الفئات…"
            value={categoryTabSearch}
            onChange={(e) => {
              setCategoryTabSearch(e.target.value)
              setCurrentPage(1)
            }}
          />
        </div>
        {categories.length > 0 && (
          <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '0.5rem' }}>
            معروض {formatNumber(filteredCategoriesTab.length)} من {formatNumber(categories.length)} فئة
          </div>
        )}
      </div>
      <div className="card">
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>اسم الفئة</th>
                <th>عدد المنتجات</th>
                <th>الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              {loadingCategories ? (
                <tr><td colSpan={4} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
              ) : categories.length === 0 ? (
                <tr>
                  <td colSpan={4} style={{ textAlign: 'center', padding: '2.5rem', color: 'var(--text-muted)' }}>
                    <div style={{ fontSize: '2rem', marginBottom: '0.5rem' }}>🏷️</div>
                    لا توجد فئات — أضف فئة جديدة للبدء
                  </td>
                </tr>
              ) : paginatedCategories.length === 0 ? (
                <tr>
                  <td colSpan={4} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                    لا توجد فئات تطابق البحث
                  </td>
                </tr>
              ) : paginatedCategories.map((c, i) => {
                const count = allProducts.filter((p) => p.category_id === c.id).length
                return (
                  <tr key={c.id}>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatNumber(((currentPage - 1) * 15) + i + 1)}</td>
                    <td style={{ fontWeight: 600 }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}>
                        <Tag size={14} color="var(--secondary)" />{c.name}
                      </span>
                    </td>
                    <td><span className="badge badge-gray">{formatNumber(count)} منتج</span></td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.4rem' }}>
                        <button className="btn btn-ghost btn-sm" onClick={() => onEditCategory(c)}><Pencil size={13} /> تعديل</button>
                        <button className="btn btn-danger btn-sm" onClick={() => onDeleteCategory(c.id, c.name)}><Trash2 size={13} /> حذف</button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
      <Pagination 
        current={currentPage} 
        total={totalPages} 
        onPage={setCurrentPage} 
      />
    </>
  )
}
