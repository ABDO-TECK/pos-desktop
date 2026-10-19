import { create } from 'zustand'
import axios from 'axios'
import toast from 'react-hot-toast'
import { extractApiError } from '../utils/apiError'
import { getProducts, getProductCatalogPage, getCategories, getProductByBarcode } from '../api/endpoints'
import {
  applyProductCatalogPage,
  getProductCatalogState,
  getProductsFromIDB,
  getProductByBarcodeFromIDB,
} from '../utils/idb'
import useAuthStore from './authStore'

function isRecoverableCheckpointError(error: unknown): boolean {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) {
    return false
  }
  const data = error.response.data as Record<string, unknown> | undefined
  if (data?.errors && typeof data.errors === 'object') {
    const errorKeys = Object.keys(data.errors)
    if (errorKeys.length > 0 && !errorKeys.includes('checkpoint')) {
      return false
    }
  }
  const message = typeof data?.message === 'string' ? data.message : ''
  const errText = typeof data?.error === 'string' ? data.error : ''
  const combined = `${message} ${errText}`.toLowerCase()
  return combined.includes('checkpoint')
}

/** مدة صلاحية الكاش: 5 ثواني (بدلاً من 5 دقائق لتحديث المخزون بسرعة) */
const CACHE_TTL_MS = 30 * 1000

interface FetchParams {
  search?: string;
  category_id?: number | string;
  low_stock?: boolean;
  [key: string]: string | number | boolean | undefined;
}

interface ProductState {
  products: Product[];
  categories: Category[];
  loading: boolean;
  lastFetched: number | null;
  fetchProducts: (params?: FetchParams, forceRefresh?: boolean) => Promise<Product[]>;
  fetchCategories: () => Promise<void>;
  findByBarcode: (barcode: string) => Promise<(Product & { scanned_as_box?: boolean }) | null>;
  setProducts: (products: Product[]) => void;
  invalidateCache: () => void;
}

const useProductStore = create<ProductState>((set, get) => ({
  products: [],
  categories: [],
  loading: false,
  lastFetched: null,

  /**
   * تحميل المنتجات:
   * 1. يحاول من API أولاً ويحفظ في IDB
   * 2. عند فشل الشبكة → يستخدم IDB كـ fallback
   * 3. يتخطى التحميل إذا كان الكاش حديثاً (أقل من 5 دقائق)
   */
  fetchProducts: async (params = {}, forceRefresh = false) => {
    const state = get()

    // تخطي إذا كان الكاش حديثاً (إلا أن يكون force أو بحث)
    if (!forceRefresh && !params.search && !params.category_id) {
      if (state.lastFetched && (Date.now() - state.lastFetched) < CACHE_TTL_MS) {
        return state.products
      }
    }

    set({ loading: true })
    try {
      const isFullCatalogSync = !params.search && !params.category_id && !params.low_stock && params.page === undefined
      let products: Product[]
      if (isFullCatalogSync) {
        const branchId = useAuthStore.getState().user?.branch_id
        const expectedScope = branchId === undefined ? '' : `branch:${branchId}`
        const cacheState = expectedScope
          ? await getProductCatalogState(expectedScope)
          : { checkpoint: null, complete: false }
        let checkpoint = cacheState.checkpoint ?? undefined
        let resolvedScope = expectedScope
        let retriedWithoutCheckpoint = false
        let hasMore = true
        const seenCheckpoints = new Set<string>()

        while (hasMore) {
          let page: ProductCatalogPage
          try {
            page = await getProductCatalogPage(checkpoint)
          } catch (error: unknown) {
            if (
              checkpoint
              && !retriedWithoutCheckpoint
              && isRecoverableCheckpointError(error)
            ) {
              checkpoint = undefined
              retriedWithoutCheckpoint = true
              continue
            }
            throw error
          }

          const nextCheckpoint = page.pagination.nextCheckpoint
          if (!nextCheckpoint) {
            throw new Error('Product catalog sync response is missing its next checkpoint')
          }
          if (hasMore && seenCheckpoints.has(nextCheckpoint)) {
            throw new Error('Product catalog sync response repeated a checkpoint')
          }
          seenCheckpoints.add(nextCheckpoint)
          resolvedScope = page.scope
          if (branchId === undefined && page.scope.startsWith('branch:')) {
            const resolvedBranchId = Number(page.scope.slice('branch:'.length))
            const currentUser = useAuthStore.getState().user
            if (currentUser && Number.isInteger(resolvedBranchId) && resolvedBranchId > 0) {
              useAuthStore.getState().setUser({ ...currentUser, branch_id: resolvedBranchId })
            }
          }
          await applyProductCatalogPage({
            products: page.products,
            scope: page.scope,
            checkpoint: nextCheckpoint,
            version: page.version,
            reset: page.pagination.reset,
            snapshotComplete: page.pagination.mode === 'snapshot' && !page.pagination.hasMore,
          })
          checkpoint = nextCheckpoint
          hasMore = page.pagination.hasMore
        }
        products = await getProductsFromIDB(resolvedScope)
      } else {
        const res = await getProducts({ page: 1, limit: 500, ...params })
        products = Array.isArray(res.data.data) ? res.data.data : []
      }
      set({ products, loading: false, lastFetched: Date.now() })

      return products
    } catch (err: unknown) {
      toast.error(extractApiError(err, 'فشل تحديث قائمة المنتجات'))
      // Fallback إلى IndexedDB عند فقد الشبكة
      try {
        const branchId = useAuthStore.getState().user?.branch_id
        const cached = branchId === undefined
          ? []
          : await getProductsFromIDB(`branch:${branchId}`)
        if (cached && cached.length > 0) {
          set({ products: cached, loading: false })
          console.info('[ProductStore] Loaded from offline cache:', cached.length, 'products')
          return cached
        }
      } catch { // IDB أيضاً فشلت
      }
      set({ loading: false })
      throw err
    }
  },

  fetchCategories: async () => {
    const res = await getCategories({ limit: 999 })
    const raw = res.data.data
    const list: Category[] = Array.isArray(raw) ? (raw as Category[]) : ((raw as { data?: Category[] })?.data ?? [])
    set({ categories: list })
  },

  findByBarcode: async (barcode) => {
    const t = String(barcode).trim()
    const checkBox = (p: Product | null) => {
      if (!p) return null
      if (String(p.box_barcode) === t) {
        return { ...p, scanned_as_box: true } as Product & { scanned_as_box: boolean }
      }
      return p
    }

    const match = (p: Product) =>
      p.barcode === t || String(p.box_barcode) === t || (p.additional_barcodes || []).includes(t)
    
    const found = get().products.find(match)
    if (found) return checkBox(found)
    
    const idbResult = await getProductByBarcodeFromIDB(t)
    if (idbResult) return checkBox(idbResult)
    
    try {
      const res = await getProductByBarcode(t)
      return checkBox((res.data.data as Product) ?? null)
    } catch {
      return null
    }
  },

  setProducts: (products) => set({ products }),

  /** إجبار تحديث الكاش من API */
  invalidateCache: () => set({ lastFetched: null }),
}))

export default useProductStore
