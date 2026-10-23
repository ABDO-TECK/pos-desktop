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

/** مدة صلاحية الكاش: 30 ثانية */
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

function isFullCatalogRequest(params?: FetchParams): boolean {
  if (!params) return true
  for (const key of Object.keys(params)) {
    if (params[key] !== undefined) {
      return false
    }
  }
  return true
}

interface InFlightFullCatalogSync {
  id: number;
  epoch: number;
  userId?: number;
  branchId?: number;
  promise: Promise<Product[]>;
  invalidated: boolean;
}

let activeFlight: InFlightFullCatalogSync | null = null
let flightCounter = 0
let sessionEpoch = 1
let latestRequestId = 0
let latestPublishedRequestId = 0
let activeRequestCount = 0
let isResolvingBranch = false

let storeHasFullCatalog = false
let storeFullCatalogScope: string | null = null

interface AuthSnapshot {
  userId?: number;
  branchId?: number;
  isAuthenticated: boolean;
}

function getAuthSnapshot(): AuthSnapshot {
  const auth = useAuthStore.getState()
  return {
    userId: auth.user?.id,
    branchId: auth.user?.branch_id,
    isAuthenticated: auth.isAuthenticated && Boolean(auth.user),
  }
}

let lastAuthSnapshot: AuthSnapshot = getAuthSnapshot()

const useProductStore = create<ProductState>((set, get) => ({
  products: [],
  categories: [],
  loading: false,
  lastFetched: null,

  fetchProducts: async (params = {}, forceRefresh = false) => {
    // ── Handle Filtered Queries ──
    if (!isFullCatalogRequest(params)) {
      const requestEpoch = sessionEpoch
      const requestId = ++latestRequestId
      activeRequestCount++
      set({ loading: true })

      try {
        const res = await getProducts({ page: 1, limit: 500, ...params })
        if (sessionEpoch !== requestEpoch) {
          throw new Error('Filtered query aborted: auth context changed')
        }
        const products = Array.isArray(res.data.data) ? res.data.data : []

        if (requestId >= latestPublishedRequestId) {
          latestPublishedRequestId = requestId
          storeHasFullCatalog = false
          storeFullCatalogScope = null
          set({
            products,
            lastFetched: null,
          })
        }
        return products
      } catch (err: unknown) {
        if (sessionEpoch === requestEpoch) {
          toast.error(extractApiError(err, 'فشل تحديث قائمة المنتجات'))
        }
        throw err
      } finally {
        if (sessionEpoch === requestEpoch) {
          activeRequestCount = Math.max(0, activeRequestCount - 1)
          set({ loading: activeRequestCount > 0 })
        }
      }
    }

    // ── Handle Full Catalog Request ──
    const user = useAuthStore.getState().user
    const branchId = user?.branch_id
    const expectedScope = branchId !== undefined ? `branch:${branchId}` : ''

    // TTL guard applies ONLY to true full catalog AND state known to hold full current-context catalog
    const canUseCachedCatalog = (
      !forceRefresh
      && storeHasFullCatalog
      && storeFullCatalogScope === expectedScope
      && get().lastFetched !== null
      && (Date.now() - (get().lastFetched ?? 0)) < CACHE_TTL_MS
    )

    if (canUseCachedCatalog) {
      return get().products
    }

    // Deduplicate concurrent full sync requests for the same scope
    if (activeFlight) {
      const isSameScope = (
        activeFlight.epoch === sessionEpoch
        && activeFlight.userId === user?.id
        && (
          activeFlight.branchId === branchId
          || activeFlight.branchId === undefined
          || branchId === undefined
        )
      )
      if (isSameScope) {
        return activeFlight.promise
      }
    }

    const requestEpoch = sessionEpoch
    const requestUserId = user?.id
    const requestId = ++latestRequestId
    const flightId = ++flightCounter
    activeRequestCount++
    set({ loading: true })

    const flightState = {
      branchId,
      invalidated: false,
    }

    const syncPromise = (async (): Promise<Product[]> => {
      let resolvedScope = expectedScope
      try {
        // Optional: Hydrate COMPLETE same-scope IDB catalog before network settles
        if (expectedScope && !storeHasFullCatalog) {
          try {
            const cacheState = await getProductCatalogState(expectedScope)
            if (cacheState.complete && sessionEpoch === requestEpoch && requestId >= latestPublishedRequestId) {
              const cached = await getProductsFromIDB(expectedScope)
              if (
                sessionEpoch === requestEpoch
                && requestId >= latestPublishedRequestId
                && !storeHasFullCatalog
                && cached
                && cached.length > 0
              ) {
                latestPublishedRequestId = requestId
                storeHasFullCatalog = true
                storeFullCatalogScope = expectedScope
                set({ products: cached })
              }
            }
          } catch {
            // Best-effort hydration
          }
        }

        const cacheState = expectedScope
          ? await getProductCatalogState(expectedScope)
          : { checkpoint: null, complete: false }

        let checkpoint = cacheState.checkpoint ?? undefined
        let retriedWithoutCheckpoint = false
        let hasMore = true
        const seenCheckpoints = new Set<string>()
        let isFirstPage = true
        let allPagesEmptyDelta = true

        while (hasMore) {
          if (sessionEpoch !== requestEpoch) {
            throw new Error('Sync aborted: auth context changed')
          }

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

          if (sessionEpoch !== requestEpoch) {
            throw new Error('Sync aborted: auth context changed')
          }

          // Scope validation
          if (expectedScope) {
            if (page.scope !== expectedScope) {
              throw new Error(`Catalog page scope mismatch: expected "${expectedScope}", received "${page.scope}"`)
            }
            resolvedScope = page.scope
          } else if (isFirstPage) {
            if (!page.scope.startsWith('branch:')) {
              throw new Error(`Invalid catalog page scope: "${page.scope}"`)
            }
            resolvedScope = page.scope
            const resolvedBranchId = Number(page.scope.slice('branch:'.length))
            if (!Number.isInteger(resolvedBranchId) || resolvedBranchId <= 0) {
              throw new Error(`Invalid branch id in page scope: "${page.scope}"`)
            }
            const currentUser = useAuthStore.getState().user
            if (currentUser && currentUser.id === requestUserId) {
              isResolvingBranch = true
              try {
                useAuthStore.getState().setUser({ ...currentUser, branch_id: resolvedBranchId })
                flightState.branchId = resolvedBranchId
              } finally {
                isResolvingBranch = false
              }
            }
          } else {
            if (page.scope !== resolvedScope) {
              throw new Error(`Catalog page scope changed during synchronization: expected "${resolvedScope}", received "${page.scope}"`)
            }
          }

          const nextCheckpoint = page.pagination.nextCheckpoint
          if (!nextCheckpoint) {
            throw new Error('Product catalog sync response is missing its next checkpoint')
          }
          if (hasMore && seenCheckpoints.has(nextCheckpoint)) {
            throw new Error('Product catalog sync response repeated a checkpoint')
          }
          seenCheckpoints.add(nextCheckpoint)

          if (page.pagination.mode !== 'delta' || page.products.length > 0 || page.pagination.reset) {
            allPagesEmptyDelta = false
          }

          if (sessionEpoch !== requestEpoch) {
            throw new Error('Sync aborted before applying page: auth context changed')
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
          isFirstPage = false
        }

        if (sessionEpoch !== requestEpoch) {
          throw new Error('Sync aborted: auth context changed')
        }

        let resultProducts: Product[]
        const currentProducts = get().products

        if (allPagesEmptyDelta && storeHasFullCatalog && storeFullCatalogScope === resolvedScope && currentProducts.length > 0) {
          resultProducts = currentProducts
        } else {
          resultProducts = await getProductsFromIDB(resolvedScope)
        }

        if (sessionEpoch !== requestEpoch) {
          throw new Error('Sync aborted before publish: auth context changed')
        }

        if (requestId >= latestPublishedRequestId) {
          latestPublishedRequestId = requestId
          storeHasFullCatalog = true
          storeFullCatalogScope = resolvedScope
          set({
            products: resultProducts,
            lastFetched: flightState.invalidated ? null : Date.now(),
          })
        }

        return resultProducts
      } catch (err: unknown) {
        if (sessionEpoch === requestEpoch) {
          toast.error(extractApiError(err, 'فشل تحديث قائمة المنتجات'))
          try {
            const authUser = useAuthStore.getState().user
            const bId = authUser?.branch_id
            const fallbackScope = bId !== undefined ? `branch:${bId}` : resolvedScope
            if (fallbackScope && sessionEpoch === requestEpoch) {
              const cacheState = await getProductCatalogState(fallbackScope)
              if (cacheState.complete && sessionEpoch === requestEpoch) {
                const cached = await getProductsFromIDB(fallbackScope)
                if (sessionEpoch === requestEpoch && cached && cached.length > 0 && requestId >= latestPublishedRequestId) {
                  latestPublishedRequestId = requestId
                  storeHasFullCatalog = true
                  storeFullCatalogScope = fallbackScope
                  set({ products: cached, lastFetched: null })
                  return cached
                }
              }
            }
          } catch {
            // IDB fallback failed
          }
        }
        throw err
      } finally {
        if (sessionEpoch === requestEpoch) {
          activeRequestCount = Math.max(0, activeRequestCount - 1)
          if (activeFlight?.id === flightId) {
            activeFlight = null
          }
          set({ loading: activeRequestCount > 0 })
        }
      }
    })()

    const flight: InFlightFullCatalogSync = {
      id: flightId,
      epoch: requestEpoch,
      userId: requestUserId,
      get branchId() { return flightState.branchId },
      set branchId(val: number | undefined) { flightState.branchId = val },
      get invalidated() { return flightState.invalidated },
      set invalidated(val: boolean) { flightState.invalidated = val },
      promise: syncPromise,
    }

    activeFlight = flight
    return flight.promise
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
  invalidateCache: () => {
    if (activeFlight) {
      activeFlight.invalidated = true
    }
    storeHasFullCatalog = false
    storeFullCatalogScope = null
    set({ lastFetched: null })
  },
}))

useAuthStore.subscribe((state) => {
  const nextSnapshot: AuthSnapshot = {
    userId: state.user?.id,
    branchId: state.user?.branch_id,
    isAuthenticated: state.isAuthenticated && Boolean(state.user),
  }

  // Preserve own setUser branch resolution
  if (
    isResolvingBranch
    && lastAuthSnapshot.isAuthenticated
    && nextSnapshot.isAuthenticated
    && lastAuthSnapshot.userId === nextSnapshot.userId
    && lastAuthSnapshot.branchId === undefined
    && nextSnapshot.branchId !== undefined
  ) {
    lastAuthSnapshot = nextSnapshot
    return
  }

  const authChanged = (
    lastAuthSnapshot.isAuthenticated !== nextSnapshot.isAuthenticated
    || lastAuthSnapshot.userId !== nextSnapshot.userId
    || lastAuthSnapshot.branchId !== nextSnapshot.branchId
  )

  if (authChanged) {
    sessionEpoch++
    lastAuthSnapshot = nextSnapshot
    activeFlight = null
    storeHasFullCatalog = false
    storeFullCatalogScope = null
    activeRequestCount = 0
    useProductStore.setState({
      products: [],
      loading: false,
      lastFetched: null,
    })
  }
})

export default useProductStore
