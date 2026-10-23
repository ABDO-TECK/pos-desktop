import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const root = path.resolve(process.argv[2] || '.');
const require = createRequire(path.join(root, 'frontend/package.json'));
const ts = require('typescript');
const axios = require('axios');
const { create: createZustandStore } = require('zustand');

console.log('=== POS Desktop Catalog Performance Verification ===');
console.log('Environment: Node.js VM + Real Transpiled Store Source + Controlled Stubs');
console.log('Mode: SIMULATED in-memory test harness (deterministic request/read counts)\n');

// ── Deterministic ff12418-compatible baseline fixture ──
const baselineSource = `import { create } from 'zustand'
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
  const combined = \`\${message} \${errText}\`.toLowerCase()
  return combined.includes('checkpoint')
}

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

  fetchProducts: async (params = {}, forceRefresh = false) => {
    const state = get()

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
        const expectedScope = branchId === undefined ? '' : \`branch:\${branchId}\`
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
      try {
        const branchId = useAuthStore.getState().user?.branch_id
        const cached = branchId === undefined
          ? []
          : await getProductsFromIDB(\`branch:\${branchId}\`)
        if (cached && cached.length > 0) {
          set({ products: cached, loading: false })
          return cached
        }
      } catch {
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
  invalidateCache: () => set({ lastFetched: null }),
}))

export default useProductStore
`;

// ── Read current (fixed) real store source ──
const fixedSourcePath = path.join(root, 'frontend/src/store/productStore.ts');
const fixedSource = fs.readFileSync(fixedSourcePath, 'utf8');

// ── Harness Setup Helper ──
function createHarness(sourceText, options = {}) {
  let simulatedTime = options.initialTime || 1_000_000;
  class Clock extends Date {
    static now() {
      return simulatedTime;
    }
  }

  const initialUser = options.user !== undefined
    ? options.user
    : { id: 1, name: 'Cashier 1', email: 'c1@test.com', role: 'cashier', branch_id: 1 };
  const initialAuth = options.isAuthenticated !== undefined
    ? options.isAuthenticated
    : Boolean(initialUser);

  const authStore = createZustandStore((set) => ({
    user: initialUser,
    isAuthenticated: initialAuth,
    setUser: (user) => set({ user }),
    logout: () => set({ user: null, isAuthenticated: false }),
    login: (user) => set({ user, isAuthenticated: true }),
  }));

  const counts = {
    getProductCatalogPage: 0,
    getProducts: 0,
    getCategories: 0,
    getProductByBarcode: 0,
    applyProductCatalogPage: 0,
    getProductCatalogState: 0,
    getProductsFromIDB: 0,
    getProductByBarcodeFromIDB: 0,
    toastErrors: 0,
  };

  const idbData = options.idbData || {
    products: new Map(), // productId -> Product
    productBarcodes: new Map(), // barcode -> productId
    cacheState: new Map(), // scope -> { scope, checkpoint, version, complete }
  };

  const defaultEndpoints = {
    getProductCatalogPage: async (checkpoint) => ({
      products: [{ id: 101, name: 'Simulated Product 1', price: 10, cost: 5, quantity: 20 }],
      scope: 'branch:1',
      version: 1,
      pagination: {
        type: 'cursor',
        mode: 'snapshot',
        limit: 500,
        hasMore: false,
        truncated: false,
        reset: false,
        nextCheckpoint: 'cp_page_1',
      },
    }),

    getProducts: async (params) => ({
      data: {
        data: [{ id: 999, name: 'Filtered Query Result', price: 15, cost: 8, quantity: 5 }],
      },
    }),

    getCategories: async () => ({
      data: { data: [{ id: 1, name: 'Beverages' }] },
    }),

    getProductByBarcode: async () => ({
      data: { data: null },
    }),
  };

  // Faithful IDB helper implementation matching frontend/src/utils/idb.ts
  const defaultIdb = {
    applyProductCatalogPage: async ({ products, scope, checkpoint, version, reset, snapshotComplete }) => {
      if (reset) {
        idbData.products.clear();
        idbData.productBarcodes.clear();
        const existingMeta = idbData.cacheState.get(scope) || {};
        idbData.cacheState.set(scope, {
          ...existingMeta,
          scope,
          checkpoint,
          version: version || 1,
          complete: false,
        });
      }

      const removeProduct = (productId) => {
        for (const [bc, pId] of idbData.productBarcodes.entries()) {
          if (pId === productId) {
            idbData.productBarcodes.delete(bc);
          }
        }
        idbData.products.delete(productId);
      };

      for (const p of products) {
        if (p._deleted || p.deleted_at) {
          for (const [childId, child] of idbData.products.entries()) {
            if (child.parent_product_id === p.id) {
              removeProduct(childId);
            }
          }
          removeProduct(p.id);
          continue;
        }

        removeProduct(p.id);
        const stored = { ...p };
        delete stored._deleted;
        delete stored.sizes;
        idbData.products.set(p.id, stored);

        const barcodes = [
          p.barcode,
          p.box_barcode,
          ...(p.additional_barcodes || []),
        ];
        for (const rawBarcode of barcodes) {
          const barcode = String(rawBarcode ?? '').trim();
          if (barcode) {
            idbData.productBarcodes.set(barcode, p.id);
          }
        }
      }

      const prevMeta = idbData.cacheState.get(scope) || { checkpoint: null, complete: false };
      // Snapshot complete sets complete=true; reset sets false; delta preserves existing complete state
      const isComplete = snapshotComplete === true ? true : (reset ? false : (prevMeta.complete === true));
      idbData.cacheState.set(scope, {
        scope,
        checkpoint,
        version: version || 1,
        complete: isComplete,
      });
    },

    getProductCatalogState: async (scope) => {
      return idbData.cacheState.get(scope) || { checkpoint: null, complete: false };
    },

    getProductsFromIDB: async (expectedScope) => {
      if (expectedScope) {
        const meta = idbData.cacheState.get(expectedScope) || { checkpoint: null, complete: false };
        if (!meta.complete) {
          return [];
        }
      }
      return Array.from(idbData.products.values());
    },

    getProductByBarcodeFromIDB: async (barcode) => {
      const pId = idbData.productBarcodes.get(barcode);
      if (pId !== undefined) {
        return idbData.products.get(pId) || null;
      }
      return null;
    },
  };

  const handlers = {
    getProductCatalogPage: options.getProductCatalogPage || defaultEndpoints.getProductCatalogPage,
    getProducts: options.getProducts || defaultEndpoints.getProducts,
    getCategories: options.getCategories || defaultEndpoints.getCategories,
    getProductByBarcode: options.getProductByBarcode || defaultEndpoints.getProductByBarcode,
    applyProductCatalogPage: options.applyProductCatalogPage || defaultIdb.applyProductCatalogPage,
    getProductCatalogState: options.getProductCatalogState || defaultIdb.getProductCatalogState,
    getProductsFromIDB: options.getProductsFromIDB || defaultIdb.getProductsFromIDB,
    getProductByBarcodeFromIDB: options.getProductByBarcodeFromIDB || defaultIdb.getProductByBarcodeFromIDB,
  };

  const endpoints = {
    get getProductCatalogPage() {
      return async (...args) => {
        counts.getProductCatalogPage++;
        return handlers.getProductCatalogPage(...args);
      };
    },
    set getProductCatalogPage(fn) {
      handlers.getProductCatalogPage = fn;
    },
    get getProducts() {
      return async (...args) => {
        counts.getProducts++;
        return handlers.getProducts(...args);
      };
    },
    set getProducts(fn) {
      handlers.getProducts = fn;
    },
    get getCategories() {
      return async (...args) => {
        counts.getCategories++;
        return handlers.getCategories(...args);
      };
    },
    set getCategories(fn) {
      handlers.getCategories = fn;
    },
    get getProductByBarcode() {
      return async (...args) => {
        counts.getProductByBarcode++;
        return handlers.getProductByBarcode(...args);
      };
    },
    set getProductByBarcode(fn) {
      handlers.getProductByBarcode = fn;
    },
  };

  const idb = {
    get applyProductCatalogPage() {
      return async (...args) => {
        counts.applyProductCatalogPage++;
        return handlers.applyProductCatalogPage(...args);
      };
    },
    set applyProductCatalogPage(fn) {
      handlers.applyProductCatalogPage = fn;
    },
    get getProductCatalogState() {
      return async (...args) => {
        counts.getProductCatalogState++;
        return handlers.getProductCatalogState(...args);
      };
    },
    set getProductCatalogState(fn) {
      handlers.getProductCatalogState = fn;
    },
    get getProductsFromIDB() {
      return async (...args) => {
        counts.getProductsFromIDB++;
        return handlers.getProductsFromIDB(...args);
      };
    },
    set getProductsFromIDB(fn) {
      handlers.getProductsFromIDB = fn;
    },
    get getProductByBarcodeFromIDB() {
      return async (...args) => {
        counts.getProductByBarcodeFromIDB++;
        return handlers.getProductByBarcodeFromIDB(...args);
      };
    },
    set getProductByBarcodeFromIDB(fn) {
      handlers.getProductByBarcodeFromIDB = fn;
    },
  };

  const toastMessages = [];
  const toast = {
    error: (msg) => {
      counts.toastErrors++;
      toastMessages.push(msg);
    },
  };

  const transpiled = ts.transpileModule(sourceText, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      esModuleInterop: true,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  const sandboxExports = {};
  vm.runInNewContext(transpiled, {
    exports: sandboxExports,
    console,
    Date: Clock,
    Symbol,
    Promise,
    Error,
    Set,
    Map,
    Number,
    Object,
    Array,
    Math,
    Boolean,
    String,
    parseInt,
    parseFloat,
    setTimeout,
    clearTimeout,
    require: (id) => {
      if (id === 'zustand') return { create: createZustandStore };
      if (id === 'axios') return axios;
      if (id === 'react-hot-toast') return { __esModule: true, default: toast };
      if (id.includes('apiError')) {
        return {
          extractApiError: (err, fallback) => (err instanceof Error ? err.message : fallback),
        };
      }
      if (id.includes('endpoints')) return endpoints;
      if (id.includes('idb')) return idb;
      if (id.includes('authStore')) return { __esModule: true, default: authStore };
      return require(id);
    },
  });

  const store = sandboxExports.default;

  return {
    store,
    authStore,
    endpoints,
    idb,
    idbData,
    counts,
    toastMessages,
    setSimulatedTime: (t) => { simulatedTime = t; },
    advanceSimulatedTime: (ms) => { simulatedTime += ms; },
    getSimulatedTime: () => simulatedTime,
  };
}

// ── Test 0: Baseline vs Fixed In-flight Deduplication ──
console.log('--- Test 0: Baseline vs Fixed In-flight Deduplication ---');
{
  let baselineResolve;
  const baselineGate = new Promise((r) => { baselineResolve = r; });
  const baselineHarness = createHarness(baselineSource, {
    getProductCatalogPage: async () => {
      await baselineGate;
      return {
        products: [{ id: 1, name: 'P1' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const bP1 = baselineHarness.store.getState().fetchProducts();
  const bP2 = baselineHarness.store.getState().fetchProducts({}, true);
  const bP3 = baselineHarness.store.getState().fetchProducts();

  baselineResolve();
  await Promise.all([bP1, bP2, bP3]);

  console.log(`[SIMULATED] Baseline 3 overlapping calls -> getProductCatalogPage requests: ${baselineHarness.counts.getProductCatalogPage}`);
  console.log(`[SIMULATED] Baseline 3 overlapping calls -> applyProductCatalogPage writes:  ${baselineHarness.counts.applyProductCatalogPage}`);
  assert.equal(baselineHarness.counts.getProductCatalogPage, 3, 'Baseline must produce 3 network requests without deduplication');

  let fixedResolve;
  const fixedGate = new Promise((r) => { fixedResolve = r; });
  const fixedHarness = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await fixedGate;
      return {
        products: [{ id: 1, name: 'P1' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const fP1 = fixedHarness.store.getState().fetchProducts();
  const fP2 = fixedHarness.store.getState().fetchProducts({}, true);
  const fP3 = fixedHarness.store.getState().fetchProducts();

  fixedResolve();
  const [fRes1, fRes2, fRes3] = await Promise.all([fP1, fP2, fP3]);

  console.log(`[SIMULATED] Fixed 3 overlapping calls    -> getProductCatalogPage requests: ${fixedHarness.counts.getProductCatalogPage}`);
  console.log(`[SIMULATED] Fixed 3 overlapping calls    -> applyProductCatalogPage writes:  ${fixedHarness.counts.applyProductCatalogPage}`);
  assert.equal(fixedHarness.counts.getProductCatalogPage, 1, 'Fixed store MUST collapse 3 overlapping calls into 1 network request');
  assert.equal(fixedHarness.counts.applyProductCatalogPage, 1, 'Fixed store MUST apply to IDB exactly once for shared page');
  assert.equal(fRes1, fRes2, 'Shared in-flight calls must receive identical result references');
  assert.equal(fRes2, fRes3, 'Shared in-flight calls must receive identical result references');
  console.log('✔ Test 0 passed: In-flight deduplication reduced requests from 3 to 1.\n');
}

// ── Test 1: Multipage Sync & Checkpoint Recovery ──
console.log('--- Test 1: Multipage Sync & Checkpoint Recovery ---');
{
  let pageCallCount = 0;
  const harness = createHarness(fixedSource, {
    getProductCatalogPage: async (checkpoint) => {
      pageCallCount++;
      if (checkpoint === undefined) {
        return {
          products: [{ id: 1, name: 'Page 1 Product' }],
          scope: 'branch:1',
          version: 1,
          pagination: { type: 'cursor', mode: 'snapshot', limit: 1, hasMore: true, nextCheckpoint: 'cp_page_2' },
        };
      }
      if (checkpoint === 'cp_page_2') {
        return {
          products: [{ id: 2, name: 'Page 2 Product' }],
          scope: 'branch:1',
          version: 1,
          pagination: { type: 'cursor', mode: 'snapshot', limit: 1, hasMore: false, nextCheckpoint: 'cp_page_3' },
        };
      }
      throw new Error(`Unexpected checkpoint: ${checkpoint}`);
    },
  });

  const result = await harness.store.getState().fetchProducts();
  console.log(`[SIMULATED] Multipage sync pages fetched: ${pageCallCount}, IDB pages applied: ${harness.counts.applyProductCatalogPage}`);
  assert.equal(pageCallCount, 2, 'Must fetch all pages until hasMore is false');
  assert.equal(harness.counts.applyProductCatalogPage, 2, 'Must apply each page to IDB in order');
  assert.equal(result.length, 2, 'Final store products must contain both pages');

  let recoveryCalls = 0;
  const recoveryHarness = createHarness(fixedSource, {
    getProductCatalogState: async () => ({ checkpoint: 'stale_cp', complete: false }),
    getProductCatalogPage: async (checkpoint) => {
      recoveryCalls++;
      if (checkpoint === 'stale_cp') {
        const error = new axios.AxiosError('Stale checkpoint', 'ERR_BAD_REQUEST', {}, {}, {
          status: 422,
          data: { message: 'Invalid or expired checkpoint', errors: { checkpoint: ['Checkpoint expired'] } },
        });
        throw error;
      }
      return {
        products: [{ id: 3, name: 'Recovered Product' }],
        scope: 'branch:1',
        version: 2,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'fresh_cp' },
      };
    },
  });

  const recResult = await recoveryHarness.store.getState().fetchProducts();
  console.log(`[SIMULATED] Checkpoint recovery requests: ${recoveryCalls}`);
  assert.equal(recoveryCalls, 2, 'Must retry without checkpoint upon 422 checkpoint error');
  assert.equal(recResult.length, 1);
  assert.equal(recResult[0].name, 'Recovered Product');
  console.log('✔ Test 1 passed: Multipage sync and checkpoint recovery verified.\n');
}

// ── Test 2: Filtered queries, low_stock, page no full TTL ──
console.log('--- Test 2: Filtered Queries, low_stock, and Pagination Never Take Full TTL ---');
{
  const harness = createHarness(fixedSource);

  await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 1, 'Initial full fetch uses sync endpoint');
  assert.equal(harness.counts.getProducts, 0);

  await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 1, 'Full catalog within TTL must not make new network request');

  await harness.store.getState().fetchProducts({ low_stock: true });
  assert.equal(harness.counts.getProducts, 1, 'low_stock must call getProducts');

  await harness.store.getState().fetchProducts({ low_stock: true });
  assert.equal(harness.counts.getProducts, 2, 'second low_stock must call getProducts again without TTL bypass');

  await harness.store.getState().fetchProducts({ page: 2 });
  assert.equal(harness.counts.getProducts, 3, 'page param must call getProducts');

  await harness.store.getState().fetchProducts({ search: 'apple' });
  assert.equal(harness.counts.getProducts, 4, 'search query must call getProducts');

  await harness.store.getState().fetchProducts({ custom_filter: 'custom_value' });
  assert.equal(harness.counts.getProducts, 5, 'unknown filter must call getProducts');

  console.log(`[SIMULATED] getProducts queries handled: ${harness.counts.getProducts} (all bypassed full catalog TTL)`);
  console.log('✔ Test 2 passed: Filtered queries bypass full catalog TTL.\n');
}

// ── Test 3: Full after filtered restores complete data ──
console.log('--- Test 3: Full After Filtered Restores Complete Catalog Data ---');
{
  const fullProducts = [
    { id: 1, name: 'Item 1', barcode: 'B1', price: 10, cost: 5, quantity: 20 },
    { id: 2, name: 'Item 2', barcode: 'B2', price: 20, cost: 10, quantity: 2 },
    { id: 3, name: 'Item 3', barcode: 'B3', price: 30, cost: 15, quantity: 50 },
  ];
  const lowStockProducts = [
    { id: 2, name: 'Item 2', barcode: 'B2', price: 20, cost: 10, quantity: 2 },
  ];

  const harness = createHarness(fixedSource, {
    getProductCatalogPage: async () => ({
      products: fullProducts,
      scope: 'branch:1',
      version: 1,
      pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_full' },
    }),
    getProducts: async () => ({
      data: { data: lowStockProducts },
    }),
  });

  const full1 = await harness.store.getState().fetchProducts();
  assert.equal(full1.length, 3, 'Initial full fetch returns 3 items');
  assert.equal(harness.store.getState().products.length, 3);

  const filtered = await harness.store.getState().fetchProducts({ low_stock: true });
  assert.equal(filtered.length, 1, 'Filtered query returns 1 item');
  assert.equal(harness.store.getState().products.length, 1, 'Store products updated to filtered subset');
  assert.equal(harness.store.getState().lastFetched, null, 'Filtered response must clear lastFetched full TTL');

  const full2 = await harness.store.getState().fetchProducts();
  assert.equal(full2.length, 3, 'Full fetch after filtered must restore complete catalog');
  assert.equal(harness.store.getState().products.length, 3, 'Store products restored to 3 items');
  assert.notEqual(harness.store.getState().lastFetched, null, 'Full catalog restored fresh TTL timestamp');

  console.log(`[SIMULATED] Catalog count transitioned: 3 (full) -> 1 (filtered) -> 3 (full restored)`);
  console.log('✔ Test 3 passed: Full after filtered restores complete data.\n');
}

// ── Test 4: Concurrency & Forced Refresh Always Actually Syncs ──
console.log('--- Test 4: Concurrency & Forced Refresh Always Actually Syncs ---');
{
  const harness = createHarness(fixedSource);

  const p1 = harness.store.getState().fetchProducts();
  const p2 = harness.store.getState().fetchProducts({}, true);
  await Promise.all([p1, p2]);
  assert.equal(harness.counts.getProductCatalogPage, 1, 'Shared initial and forced call used 1 request');

  await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 1, 'Unforced within TTL hit cache');

  await harness.store.getState().fetchProducts({}, true);
  assert.equal(harness.counts.getProductCatalogPage, 2, 'Forced refresh alone must execute actual sync');

  console.log(`[SIMULATED] Total getProductCatalogPage requests: ${harness.counts.getProductCatalogPage}`);
  console.log('✔ Test 4 passed: Concurrency and forced refresh behavior verified.\n');
}

// ── Test 5: Failure Clears Flight Registry ──
console.log('--- Test 5: Failure Clears Flight Registry ---');
{
  let shouldFail = true;
  const harness = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      if (shouldFail) {
        throw new Error('Simulated upstream server error 500');
      }
      return {
        products: [{ id: 1, name: 'Recovered' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  await assert.rejects(
    harness.store.getState().fetchProducts(),
    /Simulated upstream server error 500/,
    'Must rethrow failure'
  );
  assert.equal(harness.counts.getProductCatalogPage, 1);
  assert.equal(harness.store.getState().loading, false, 'Loading must be false after error');

  shouldFail = false;
  const res = await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 2, 'Next call after error must start fresh flight');
  assert.equal(res.length, 1);
  assert.equal(res[0].name, 'Recovered');

  console.log(`[SIMULATED] Failed request count: 1, recovery request count: 1`);
  console.log('✔ Test 5 passed: Failure clears flight registry.\n');
}

// ── Test 6: Invalidation During Flight ──
console.log('--- Test 6: Invalidation During Flight ---');
{
  let flightResolve;
  const flightGate = new Promise((r) => { flightResolve = r; });
  const harness = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await flightGate;
      return {
        products: [{ id: 1, name: 'P1' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const flightPromise = harness.store.getState().fetchProducts();

  harness.store.getState().invalidateCache();
  assert.equal(harness.store.getState().lastFetched, null);

  flightResolve();
  await flightPromise;

  assert.equal(
    harness.store.getState().lastFetched,
    null,
    'Settling flight must NOT overwrite lastFetched when invalidated during flight'
  );

  harness.endpoints.getProductCatalogPage = async () => ({
    products: [{ id: 1, name: 'P1' }],
    scope: 'branch:1',
    version: 1,
    pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp2' },
  });

  await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 2, 'Subsequent fetch must sync due to invalidated cache');

  console.log(`[SIMULATED] getProductCatalogPage requests: ${harness.counts.getProductCatalogPage}`);
  console.log('✔ Test 6 passed: Invalidation during flight is preserved.\n');
}

// ── Test 7: Auth / Branch / Logout / Relogin Stale Responses (Full Sync Variant) ──
console.log('--- Test 7: Auth / Branch / Logout / Relogin Stale Response Defense (Full Sync) ---');
{
  // 7a: Logout during flight
  let logoutResolve;
  const logoutGate = new Promise((r) => { logoutResolve = r; });
  const harnessA = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await logoutGate;
      return {
        products: [{ id: 1, name: 'P1' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const pendingA = harnessA.store.getState().fetchProducts().catch((e) => e);

  harnessA.authStore.getState().logout();
  assert.equal(harnessA.store.getState().products.length, 0, 'Store products cleared on logout');

  logoutResolve();
  await pendingA;

  assert.equal(harnessA.counts.applyProductCatalogPage, 0, 'Must NOT apply pages to IDB after logout');
  assert.equal(harnessA.store.getState().products.length, 0, 'Must NOT publish products to store after logout');
  console.log('[SIMULATED] 7a: Stale full sync response after logout safely dropped.');

  // 7b: User switch during flight
  let switchResolve;
  const switchGate = new Promise((r) => { switchResolve = r; });
  const harnessB = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await switchGate;
      return {
        products: [{ id: 1, name: 'User1 Product' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const pendingB = harnessB.store.getState().fetchProducts().catch((e) => e);

  harnessB.authStore.getState().setUser({ id: 2, name: 'User 2', role: 'cashier', branch_id: 2 });
  switchResolve();
  await pendingB;

  assert.equal(harnessB.counts.applyProductCatalogPage, 0, 'Must NOT apply User 1 pages to IDB after user switch');
  assert.equal(harnessB.store.getState().products.length, 0, 'Must NOT publish User 1 data into User 2 store');
  console.log('[SIMULATED] 7b: Stale full sync response after user switch safely dropped.');

  // 7c: Branch switch during flight
  let branchResolve;
  const branchGate = new Promise((r) => { branchResolve = r; });
  const harnessC = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await branchGate;
      return {
        products: [{ id: 1, name: 'Branch1 Product' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const pendingC = harnessC.store.getState().fetchProducts().catch((e) => e);

  const currentUser = harnessC.authStore.getState().user;
  harnessC.authStore.getState().setUser({ ...currentUser, branch_id: 2 });
  branchResolve();
  await pendingC;

  assert.equal(harnessC.counts.applyProductCatalogPage, 0, 'Must NOT apply Branch 1 pages after switching to Branch 2');
  assert.equal(harnessC.store.getState().products.length, 0, 'Must NOT publish Branch 1 products into Branch 2 store');
  console.log('[SIMULATED] 7c: Stale full sync response after branch switch safely dropped.');

  // 7d: SAME user logout and relogin during flight
  let reloginResolve;
  const reloginGate = new Promise((r) => { reloginResolve = r; });
  const harnessD = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      await reloginGate;
      return {
        products: [{ id: 1, name: 'Old Session Product' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp1' },
      };
    },
  });

  const pendingD = harnessD.store.getState().fetchProducts().catch((e) => e);

  harnessD.authStore.getState().logout();
  harnessD.authStore.getState().login({ id: 1, name: 'Cashier 1', email: 'c1@test.com', role: 'cashier', branch_id: 1 });

  reloginResolve();
  await pendingD;

  assert.equal(harnessD.counts.applyProductCatalogPage, 0, 'Must NOT apply previous session page after relogin');
  assert.equal(harnessD.store.getState().products.length, 0, 'Must NOT publish previous session data into new session');
  console.log('[SIMULATED] 7d: Same user logout/relogin epoch defense verified for full sync.');

  console.log('✔ Test 7 passed: All auth/branch/session stale defenses verified for full sync.\n');
}

// ── Test 8: Missing Branch Resolution Safe ──
console.log('--- Test 8: Missing Branch Resolution Safe ---');
{
  const harness = createHarness(fixedSource, {
    user: { id: 5, name: 'Unassigned Cashier', role: 'cashier', branch_id: undefined },
    getProductCatalogPage: async () => ({
      products: [{ id: 55, name: 'Resolved Branch Product' }],
      scope: 'branch:3',
      version: 1,
      pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_b3' },
    }),
  });

  const result = await harness.store.getState().fetchProducts();
  assert.equal(harness.counts.getProductCatalogPage, 1);
  assert.equal(harness.counts.applyProductCatalogPage, 1);
  assert.equal(harness.authStore.getState().user.branch_id, 3, 'User branch_id must be resolved from server scope');
  assert.equal(result.length, 1);
  assert.equal(result[0].name, 'Resolved Branch Product');
  assert.equal(harness.store.getState().products.length, 1);

  console.log(`[SIMULATED] Resolved branch_id: 3, store products count: ${result.length}`);
  console.log('✔ Test 8 passed: Missing branch resolution safe without self-invalidation.\n');
}

// ── Test 9: Reject Wrong Branch Response & Scope Change ──
console.log('--- Test 9: Reject Wrong Branch Response & Page Scope Change ---');
{
  const harnessWrong = createHarness(fixedSource, {
    user: { id: 1, name: 'User 1', role: 'cashier', branch_id: 1 },
    getProductCatalogPage: async () => ({
      products: [{ id: 99, name: 'Wrong Branch Product' }],
      scope: 'branch:9',
      version: 1,
      pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_wrong' },
    }),
  });

  await assert.rejects(
    harnessWrong.store.getState().fetchProducts(),
    /Catalog page scope mismatch/,
    'Must reject wrong branch response'
  );
  assert.equal(harnessWrong.counts.applyProductCatalogPage, 0, 'Must NOT apply wrong branch pages to IDB');

  let step = 0;
  const harnessScopeChange = createHarness(fixedSource, {
    user: { id: 1, name: 'User 1', role: 'cashier', branch_id: undefined },
    getProductCatalogPage: async () => {
      step++;
      if (step === 1) {
        return {
          products: [{ id: 10, name: 'P10' }],
          scope: 'branch:2',
          version: 1,
          pagination: { type: 'cursor', mode: 'snapshot', limit: 1, hasMore: true, nextCheckpoint: 'cp_mid' },
        };
      }
      return {
        products: [{ id: 20, name: 'P20' }],
        scope: 'branch:4',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 1, hasMore: false, nextCheckpoint: 'cp_end' },
      };
    },
  });

  await assert.rejects(
    harnessScopeChange.store.getState().fetchProducts(),
    /Catalog page scope changed during synchronization/,
    'Must reject page scope change across pages'
  );
  assert.equal(harnessScopeChange.counts.applyProductCatalogPage, 1, 'Only page 1 was applied before scope change aborted');

  console.log('[SIMULATED] Scope mismatch and change-during-sync safely rejected.');
  console.log('✔ Test 9 passed: Wrong branch and scope changes correctly rejected.\n');
}

// ── Test 10: IDB Hydration, Request-Generation Guard & Delta Refreshes ──
console.log('--- Test 10: IDB Hydration, Request-Generation Guard & Delta Refreshes ---');
{
  // 10a: Incomplete snapshots must NOT be hydrated
  const partialHarness = createHarness(fixedSource, {
    idbData: {
      products: new Map([[1, { id: 1, name: 'Partial Product' }]]),
      productBarcodes: new Map(),
      cacheState: new Map([['branch:1', { scope: 'branch:1', checkpoint: 'cp_partial', complete: false }]]),
    },
    getProductCatalogPage: async () => ({
      products: [{ id: 1, name: 'Partial Product' }, { id: 2, name: 'Finished Product' }],
      scope: 'branch:1',
      version: 1,
      pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_done' },
    }),
  });

  assert.equal(partialHarness.store.getState().products.length, 0, 'Store starts empty');
  await partialHarness.store.getState().fetchProducts();
  assert.equal(partialHarness.store.getState().products.length, 2, 'Full sync completes both products');
  console.log('[SIMULATED] 10a: Incomplete snapshot verified NOT hydrated before completion.');

  // 10b: Hydration Request-Generation Guard (Deterministic Gate, No Sleeps)
  // Full sync #1 starts; IDB hydration is held; newer filtered fetch #2 completes and publishes;
  // older hydration resolves -> must NOT overwrite newer filtered data or mark catalog complete!
  let idbGateResolve;
  const idbGate = new Promise((r) => { idbGateResolve = r; });
  let netGateResolve;
  const netGate = new Promise((r) => { netGateResolve = r; });
  let idbCallCount = 0;

  const raceHarness = createHarness(fixedSource, {
    idbData: {
      products: new Map([[100, { id: 100, name: 'Cached Full Product 100' }]]),
      productBarcodes: new Map(),
      cacheState: new Map([['branch:1', { scope: 'branch:1', checkpoint: 'cp_ready', complete: true }]]),
    },
    getProductsFromIDB: async (scope) => {
      idbCallCount++;
      if (idbCallCount === 1) {
        await idbGate;
        return [{ id: 100, name: 'Cached Full Product 100' }];
      }
      return [{ id: 100, name: 'Network Full Product 100' }];
    },
    getProductCatalogPage: async () => {
      await netGate;
      return {
        products: [{ id: 100, name: 'Network Full Product 100' }],
        scope: 'branch:1',
        version: 1,
        pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_net' },
      };
    },
    getProducts: async () => ({
      data: { data: [{ id: 200, name: 'Filtered Product 200' }] },
    }),
  });

  // Start older full sync #1
  const fullSyncPromise = raceHarness.store.getState().fetchProducts();

  // Run newer filtered fetch #2 immediately to completion
  const filteredResult = await raceHarness.store.getState().fetchProducts({ search: 'filtered' });
  assert.equal(filteredResult[0].name, 'Filtered Product 200');
  assert.equal(raceHarness.store.getState().products[0].name, 'Filtered Product 200');
  assert.equal(raceHarness.store.getState().lastFetched, null, 'Filtered response cleared lastFetched');

  // Now resolve older hydration
  idbGateResolve();
  await Promise.resolve(); // flush microtasks for hydration resolution

  // Store must STILL have filtered product 200, NOT clobbered by older hydration 100!
  assert.equal(
    raceHarness.store.getState().products[0].name,
    'Filtered Product 200',
    'Older hydration MUST NOT clobber newer filtered store products'
  );
  assert.equal(raceHarness.store.getState().lastFetched, null, 'Older hydration MUST NOT mark catalog as fresh');

  // Settle older full sync network flight
  netGateResolve();
  const fullResult = await fullSyncPromise;
  assert.equal(fullResult[0].name, 'Network Full Product 100');

  // Store products must STILL remain newer filtered product 200!
  assert.equal(
    raceHarness.store.getState().products[0].name,
    'Filtered Product 200',
    'Older full network result MUST NOT overwrite newer filtered query in store'
  );
  assert.equal(raceHarness.store.getState().lastFetched, null, 'Must NOT relabel newer filtered data as full/fresh');
  console.log('[SIMULATED] 10b: Hydration request-generation guard verified without sleeps.');

  // 10c: Empty delta no-op optimization
  const deltaHarness = createHarness(fixedSource, {
    idbData: {
      products: new Map([[1, { id: 1, name: 'P1' }]]),
      productBarcodes: new Map(),
      cacheState: new Map([['branch:1', { scope: 'branch:1', checkpoint: 'cp_1', complete: true }]]),
    },
    getProductCatalogPage: async () => ({
      products: [],
      scope: 'branch:1',
      version: 1,
      pagination: { type: 'cursor', mode: 'delta', limit: 500, hasMore: false, nextCheckpoint: 'cp_2' },
    }),
  });

  await deltaHarness.store.getState().fetchProducts(); // full catalog established
  const refBefore = deltaHarness.store.getState().products;
  deltaHarness.counts.getProductsFromIDB = 0;

  const deltaResult = await deltaHarness.store.getState().fetchProducts({}, true);
  assert.equal(deltaResult, refBefore, 'Empty delta retains store array reference identity');
  assert.equal(deltaHarness.counts.getProductsFromIDB, 0, 'Empty delta avoids IDB re-read');
  console.log('[SIMULATED] 10c: Empty delta no-op optimization verified.');

  // 10d: Changed and deleted delta refreshes
  let deltaStep = 0;
  const changeHarness = createHarness(fixedSource, {
    getProductCatalogPage: async () => {
      deltaStep++;
      if (deltaStep === 1) {
        return {
          products: [
            { id: 10, name: 'Product 10', price: 100 },
            { id: 20, name: 'Product 20 To Be Deleted', price: 200 },
          ],
          scope: 'branch:1',
          version: 1,
          pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_snap' },
        };
      }
      return {
        products: [
          { id: 10, name: 'Product 10 Updated', price: 150 }, // updated price
          { id: 20, name: 'Product 20', _deleted: true },     // tombstone
        ],
        scope: 'branch:1',
        version: 2,
        pagination: { type: 'cursor', mode: 'delta', limit: 500, hasMore: false, nextCheckpoint: 'cp_delta' },
      };
    },
  });

  const snapRes = await changeHarness.store.getState().fetchProducts();
  assert.equal(snapRes.length, 2);

  const deltaRes = await changeHarness.store.getState().fetchProducts({}, true);
  assert.equal(deltaRes.length, 1, 'Deleted product removed from catalog');
  assert.equal(deltaRes[0].id, 10);
  assert.equal(deltaRes[0].price, 150, 'Updated product price reflected');
  console.log('[SIMULATED] 10d: Changed/deleted delta refresh verified.');

  // 10e: Empty complete catalog works
  const emptyHarness = createHarness(fixedSource, {
    getProductCatalogPage: async () => ({
      products: [],
      scope: 'branch:1',
      version: 1,
      pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_empty' },
    }),
  });

  const emptyRes = await emptyHarness.store.getState().fetchProducts();
  assert.equal(emptyRes.length, 0);
  assert.equal(emptyHarness.store.getState().products.length, 0);
  assert.notEqual(emptyHarness.store.getState().lastFetched, null, 'Empty complete catalog has valid lastFetched');
  console.log('[SIMULATED] 10e: Empty complete catalog works correctly.');

  console.log('✔ Test 10 passed: All hydration, generation guard & delta scenarios verified.\n');
}

// ── Test 11: Loading State, Query Races & Filtered Auth Regressions ──
console.log('--- Test 11: Loading State, Query Races & Filtered Auth Regressions ---');
{
  // 11a: Loading state & older filtered query cannot overwrite newer query
  let slowResolve;
  const slowGate = new Promise((r) => { slowResolve = r; });
  let fastResolve;
  const fastGate = new Promise((r) => { fastResolve = r; });

  const raceHarness = createHarness(fixedSource, {
    getProducts: async (params) => {
      if (params.search === 'slow') {
        await slowGate;
        return { data: { data: [{ id: 1, name: 'Slow Result' }] } };
      }
      if (params.search === 'fast') {
        await fastGate;
        return { data: { data: [{ id: 2, name: 'Fast Result' }] } };
      }
      return { data: { data: [] } };
    },
  });

  const pSlow = raceHarness.store.getState().fetchProducts({ search: 'slow' });
  const pFast = raceHarness.store.getState().fetchProducts({ search: 'fast' });

  assert.equal(raceHarness.store.getState().loading, true, 'Loading must be true while requests are in flight');

  fastResolve();
  const fastRes = await pFast;
  assert.equal(fastRes[0].name, 'Fast Result');
  assert.equal(raceHarness.store.getState().products[0].name, 'Fast Result');
  assert.equal(raceHarness.store.getState().loading, true, 'Loading stays true while slow request is still active');

  slowResolve();
  const slowRes = await pSlow;
  assert.equal(slowRes[0].name, 'Slow Result');

  assert.equal(
    raceHarness.store.getState().products[0].name,
    'Fast Result',
    'Older slow request must NOT overwrite newer fast request in store'
  );
  assert.equal(raceHarness.store.getState().loading, false, 'Loading must become false when all requests finish');
  console.log('[SIMULATED] 11a: Filtered query race defense verified.');

  // 11b: Regression for filtered queries across logout / user switch / branch switch / relogin
  // Verify: rejects with cancellation/error, NEVER returns user1 products, no misleading toast error
  const authBoundaries = [
    { name: 'logout', action: (h) => h.authStore.getState().logout() },
    { name: 'user switch', action: (h) => h.authStore.getState().setUser({ id: 2, name: 'User 2', branch_id: 2 }) },
    { name: 'branch switch', action: (h) => h.authStore.getState().setUser({ id: 1, name: 'User 1', branch_id: 2 }) },
    { name: 'same-user relogin', action: (h) => {
      h.authStore.getState().logout();
      h.authStore.getState().login({ id: 1, name: 'User 1', branch_id: 1 });
    }},
  ];

  for (const b of authBoundaries) {
    let gateResolve;
    const gate = new Promise((r) => { gateResolve = r; });
    const harness = createHarness(fixedSource, {
      getProducts: async () => {
        await gate;
        return { data: { data: [{ id: 999, name: 'User 1 Private Filtered Item' }] } };
      },
    });

    const pending = harness.store.getState().fetchProducts({ search: 'private' });
    b.action(harness);
    gateResolve();

    await assert.rejects(
      pending,
      /Filtered query aborted: auth context changed/,
      `Filtered query across ${b.name} must reject with cancellation error`
    );
    assert.equal(harness.store.getState().products.length, 0, `Store must NOT contain old data after ${b.name}`);
    assert.equal(harness.counts.toastErrors, 0, `Cancellation across ${b.name} must NOT trigger toast error`);
  }
  console.log('[SIMULATED] 11b: Filtered query auth cancellation regression passed (no old data, no toast).');

  // 11c: Exact 1-old + 2-new sequence for loading counts (filtered old request)
  {
    let oldResolve;
    const oldGate = new Promise((r) => { oldResolve = r; });
    let new1Resolve;
    const new1Gate = new Promise((r) => { new1Resolve = r; });
    let new2Resolve;
    const new2Gate = new Promise((r) => { new2Resolve = r; });

    const harness = createHarness(fixedSource, {
      getProducts: async (params) => {
        if (params.search === 'old') {
          await oldGate;
          return { data: { data: [{ id: 1, name: 'Old' }] } };
        }
        if (params.search === 'new1') {
          await new1Gate;
          return { data: { data: [{ id: 2, name: 'New 1' }] } };
        }
        if (params.search === 'new2') {
          await new2Gate;
          return { data: { data: [{ id: 3, name: 'New 2' }] } };
        }
        return { data: { data: [] } };
      },
    });

    // 1 old request active in session 1
    const pOld = harness.store.getState().fetchProducts({ search: 'old' }).catch(() => []);
    assert.equal(harness.store.getState().loading, true);

    // Auth boundary to session 2
    harness.authStore.getState().logout();
    harness.authStore.getState().login({ id: 2, name: 'User 2', branch_id: 1 });
    assert.equal(harness.store.getState().loading, false, 'Loading reset on login');

    // 2 new requests active in session 2
    const pNew1 = harness.store.getState().fetchProducts({ search: 'new1' });
    const pNew2 = harness.store.getState().fetchProducts({ search: 'new2' });
    assert.equal(harness.store.getState().loading, true, 'Loading true for session 2');

    // Old request finishes -> MUST NOT decrement session 2 count or clear loading!
    oldResolve();
    await pOld;
    assert.equal(harness.store.getState().loading, true, 'Loading MUST stay true when old session request finishes');

    // First new request finishes -> 1 still pending, loading MUST stay true!
    new1Resolve();
    await pNew1;
    assert.equal(harness.store.getState().loading, true, 'Loading MUST stay true while second new request is pending');

    // Second new request finishes -> count becomes 0, loading becomes false!
    new2Resolve();
    await pNew2;
    assert.equal(harness.store.getState().loading, false, 'Loading becomes false when all session 2 requests finish');
  }
  console.log('[SIMULATED] 11c: Exact 1-old + 2-new loading count sequence passed.');

  // 11d: Full sync variant of 1-old + 2-new sequence
  {
    let oldFullResolve;
    const oldFullGate = new Promise((r) => { oldFullResolve = r; });
    let newFullResolve;
    const newFullGate = new Promise((r) => { newFullResolve = r; });
    let oldReachResolve;
    const oldReachPromise = new Promise((r) => { oldReachResolve = r; });
    let oldReached = false;

    const harness = createHarness(fixedSource, {
      getProductCatalogPage: async () => {
        if (!oldReached) {
          oldReached = true;
          oldReachResolve();
          await oldFullGate;
          return {
            products: [{ id: 1, name: 'Old Full' }],
            scope: 'branch:1',
            version: 1,
            pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_o' },
          };
        }
        await newFullGate;
        return {
          products: [{ id: 2, name: 'New Full' }],
          scope: 'branch:2',
          version: 1,
          pagination: { type: 'cursor', mode: 'snapshot', limit: 500, hasMore: false, nextCheckpoint: 'cp_n' },
        };
      },
    });

    // 1 old full sync active in session 1
    const pOldFull = harness.store.getState().fetchProducts().catch(() => []);
    await oldReachPromise; // ensure old request is actively in-flight

    // Logout and login to session 2
    harness.authStore.getState().logout();
    harness.authStore.getState().login({ id: 2, name: 'User 2', branch_id: 2 });

    // 1 new full sync active in session 2
    const pNewFull = harness.store.getState().fetchProducts();
    assert.equal(harness.store.getState().loading, true);

    // Old full sync settles -> MUST NOT clear session 2's activeFlight or mutate loading
    oldFullResolve();
    await pOldFull;
    assert.equal(harness.store.getState().loading, true, 'Old full sync completion must not clear session 2 loading');

    // New full sync settles
    newFullResolve();
    const newRes = await pNewFull;
    assert.equal(newRes[0].name, 'New Full');
    assert.equal(harness.store.getState().products[0].name, 'New Full');
    assert.equal(harness.store.getState().loading, false);
  }
  console.log('[SIMULATED] 11d: Full sync variant of 1-old + 2-new loading sequence passed.');

  console.log('✔ Test 11 passed: All race defenses, auth cancellations & loading sequences verified.\n');
}

console.log('======================================================');
console.log('ALL CATALOG PERFORMANCE & CORRECTNESS TESTS PASSED');
console.log('======================================================');
