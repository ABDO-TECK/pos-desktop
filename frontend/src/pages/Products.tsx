
import { useState, useEffect } from 'react'

import { Plus, X, Tag } from 'lucide-react'
import {
  createProduct, updateProduct, deleteProduct,
  getCategories, createCategory, updateCategory, deleteCategory,
  getLowStock,
} from '../api/endpoints'
import { formatNumber } from '../utils/formatters'
import { formatProductApiError } from './products/ProductForm'
import toast from 'react-hot-toast'
import useProductStore from '../store/productStore'
import { useConfirmStore } from '../store/confirmStore'

import ProductsTab from './products/ProductsTab'
import CategoriesTab from './products/CategoriesTab'
import ProductForm from './products/ProductForm'
import { extractApiError } from '../utils/apiError'
import { emptyProduct, normalizeProductPayload } from './products/productNormalization'

function TabBtn({ active, onClick, children }: any) {
  return (
    <button onClick={onClick} style={{
      display: 'inline-flex', alignItems: 'center',
      padding: '0.5rem 1.25rem', background: 'none', border: 'none', cursor: 'pointer',
      fontWeight: active ? 700 : 400,
      color: active ? 'var(--primary)' : 'var(--text-muted)',
      borderBottom: `2px solid ${active ? 'var(--primary)' : 'transparent'}`,
      marginBottom: '-2px', fontSize: '0.9rem', fontFamily: 'inherit', whiteSpace: 'nowrap',
    }}>
      {children}
    </button>
  )
}

export default function Products() {
  const [tab, setTab] = useState('products')

  // ── Products ──
  const [allProducts, setAllProducts]       = useState<any[]>([])
  const [loadingProducts, setLoadingProducts] = useState(false)
  const [productModal, setProductModal]     = useState<any>(null)
  const [productForm, setProductForm]       = useState(emptyProduct)
  const [editProductId, setEditProductId]   = useState<any>(null)
  const [savingProduct, setSavingProduct]   = useState(false)
  const [lowStock, setLowStock]             = useState<any[]>([])

  // ── Categories ──
  const [categories, setCategories]             = useState<any[]>([])
  const [loadingCategories, setLoadingCategories] = useState(false)
  const [categoryModal, setCategoryModal]       = useState<any>(null)
  const [categoryForm, setCategoryForm]         = useState({ name: '' })
  const [editCategoryId, setEditCategoryId]     = useState<any>(null)
  const [savingCategory, setSavingCategory]     = useState(false)
  const { confirm }                             = useConfirmStore()

  // ── Load ──
  const loadProducts = async () => {
    setLoadingProducts(true)
    try { 
      setAllProducts(await useProductStore.getState().fetchProducts({}, true))
    }
    finally { setLoadingProducts(false) }
  }

  const loadCategories = async () => {
    setLoadingCategories(true)
    try { 
      const res = await getCategories()
      const raw = res.data.data as any; setCategories(Array.isArray(raw) ? raw : (raw?.data ?? []))
    }
    finally { setLoadingCategories(false) }
  }

  useEffect(() => {
    loadProducts()
    loadCategories()
    getLowStock().then(r => { const d = (r.data as any).data; setLowStock(Array.isArray(d) ? d : (d?.data ?? [])) })
  }, [])

  // ── Product actions ──
  const openCreateProduct = () => {
    setProductForm({ ...emptyProduct })
    setEditProductId(null)
    setProductModal('create')
  }
  const openEditProduct = (p: any) => {
    const extras = p.additional_barcodes || []
    setProductForm({
      ...p,
      category_id: p.category_id ?? '',
      units_per_box: p.units_per_box ?? 1,
      sell_by_weight: p.sell_by_weight ?? 0,
      unit_type: p.unit_type ?? (p.sell_by_weight === 1 ? 'weight' : 'piece'),
      barcodes: [p.barcode || '', ...extras],
      sizes: p.sizes || [],
    })
    setEditProductId(p.id)
    setProductModal('edit')
  }

  const handleSaveProduct = async () => {
    const payload = normalizeProductPayload(productForm)

    setSavingProduct(true)
    try {
      if (productModal === 'create') {
        await createProduct(payload as any)
        toast.success('تم إضافة المنتج')
      } else {
        await updateProduct(editProductId, payload as any)
        toast.success('تم تحديث المنتج')
      }
      setProductModal(null)
      loadProducts()
      useProductStore.getState().invalidateCache()
    } catch (err) { toast.error(formatProductApiError(err)) }
    finally { setSavingProduct(false) }
  }

  const handleDeleteProduct = async (id: any, name: string) => {
    if (!(await confirm(`هل تريد حذف "${name}"؟`))) return
    try { 
      await deleteProduct(id)
      toast.success('تم حذف المنتج')
      loadProducts()
      useProductStore.getState().invalidateCache()
    }
    catch (err) { toast.error(extractApiError(err, 'حدث خطأ أثناء الحذف')) }
  }

  // ── Category actions ──
  const openCreateCategory = () => { setCategoryForm({ name: '' }); setEditCategoryId(null); setCategoryModal('create') }
  const openEditCategory   = (c: any) => { setCategoryForm({ name: c.name }); setEditCategoryId(c.id); setCategoryModal('edit') }

  const handleSaveCategory = async () => {
    if (!categoryForm.name.trim()) { toast.error('اسم الفئة مطلوب'); return }
    setSavingCategory(true)
    try {
      if (categoryModal === 'create') { await createCategory(categoryForm); toast.success('تم إضافة الفئة') }
      else { await updateCategory(editCategoryId, categoryForm); toast.success('تم تحديث الفئة') }
      setCategoryModal(null); loadCategories()
    } catch (err) { toast.error(extractApiError(err, 'حدث خطأ')) }
    finally { setSavingCategory(false) }
  }

  const handleDeleteCategory = async (id: any, name: string) => {
    if (!(await confirm(`هل تريد حذف فئة "${name}"؟ سيتم إلغاء ربط المنتجات بها.`))) return
    try { await deleteCategory(id); toast.success('تم حذف الفئة'); loadCategories(); loadProducts() }
    catch (err) { toast.error(extractApiError(err, 'حدث خطأ أثناء الحذف')) }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>

      {/* Header */}
      <div className="page-header">
        <h2>{tab === 'products' ? 'المنتجات' : 'الفئات'}</h2>
        {tab === 'products'
          ? <button className="btn btn-primary" onClick={openCreateProduct}><Plus size={16} /> إضافة منتج</button>
          : <button className="btn btn-primary" onClick={openCreateCategory}><Plus size={16} /> إضافة فئة</button>
        }
      </div>

      {/* Tabs */}
      <div className="tabs-scroll" style={{ marginTop: '-0.5rem' }}>
        <TabBtn active={tab === 'products'} onClick={() => setTab('products')}>
          المنتجات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(allProducts.length)}</span>
        </TabBtn>
        <TabBtn active={tab === 'categories'} onClick={() => setTab('categories')}>
          <Tag size={14} style={{ marginLeft: '0.3rem' }} />
          الفئات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(categories.length)}</span>
        </TabBtn>
      </div>

      {/* ── Products Tab ── */}
      {tab === 'products' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <ProductsTab
            allProducts={allProducts}
            loadingProducts={loadingProducts}
            categories={categories}
            lowStock={lowStock}
            onEditProduct={openEditProduct}
            onDeleteProduct={handleDeleteProduct}
          />
        </div>
      )}

      {/* ── Categories Tab ── */}
      {tab === 'categories' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <CategoriesTab
            categories={categories}
            loadingCategories={loadingCategories}
            allProducts={allProducts}
            onEditCategory={openEditCategory}
            onDeleteCategory={handleDeleteCategory}
          />
        </div>
      )}

      {/* ── Product Modal ── */}
      {productModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setProductModal(null)}>
          <div className="modal" style={{ maxWidth: '900px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{productModal === 'create' ? 'إضافة منتج جديد' : 'تعديل المنتج'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setProductModal(null)}><X size={18} /></button>
            </div>
            <ProductForm
              form={productForm}
              setForm={setProductForm}
              categories={categories}
              modalKey={productModal + (editProductId ?? 'new')}
              allProducts={allProducts}
              editingProductId={productModal === 'edit' ? editProductId : null}
            />
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleSaveProduct} disabled={savingProduct}>
                {savingProduct ? <span className="spinner" /> : null} حفظ
              </button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setProductModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}

      {/* ── Category Modal ── */}
      {categoryModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setCategoryModal(null)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{categoryModal === 'create' ? 'إضافة فئة جديدة' : 'تعديل الفئة'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setCategoryModal(null)}><X size={18} /></button>
            </div>
            <div>
              <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>اسم الفئة *</label>
              <input className="input input-lg" placeholder="مثال: مشروبات، مواد غذائية..."
                value={categoryForm.name}
                onChange={(e) => setCategoryForm({ name: e.target.value })}
                onKeyDown={(e) => e.key === 'Enter' && handleSaveCategory()}
                autoFocus
              />
            </div>
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleSaveCategory} disabled={savingCategory}>
                {savingCategory ? <span className="spinner" /> : null} حفظ
              </button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setCategoryModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
