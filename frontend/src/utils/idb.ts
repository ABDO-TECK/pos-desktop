import { openDB } from 'idb'
import type { IDBPDatabase } from 'idb'

const DB_NAME = 'pos_offline'
const DB_VERSION = 5

export interface OfflineSaleOwner {
  ownerUserId: number
  branchId: number
}

export type PendingSaleStatus = 'pending' | 'failed' | 'conflict' | 'permanent' | 'review'

export interface PendingSaleRecord extends Record<string, unknown> {
  localId: IDBValidKey
  ownerUserId?: number
  branchId?: number
  idempotencyKey?: string
  savedAt: string
  syncStatus?: PendingSaleStatus
  reviewReason?: 'legacy_unowned'
  lastError?: string | null
  retryCount?: number
  lastAttempt?: string
  payloadBytes?: number
}

export const isPendingSaleOwnedBy = (
  sale: Pick<PendingSaleRecord, 'ownerUserId' | 'branchId'>,
  owner: OfflineSaleOwner,
): boolean => sale.ownerUserId === owner.ownerUserId && sale.branchId === owner.branchId

export const classifyPendingSaleForUpgrade = (
  sale: Record<string, unknown>,
): Record<string, unknown> => {
  if (
    Number.isInteger(sale.ownerUserId)
    && Number(sale.ownerUserId) > 0
    && Number.isInteger(sale.branchId)
    && Number(sale.branchId) > 0
  ) {
    return sale
  }

  return {
    ...sale,
    syncStatus: 'review',
    reviewReason: 'legacy_unowned',
    lastError: sale.lastError ?? 'Legacy offline sale has no owner or branch',
  }
}

interface PendingSaleUpgradeCursor {
  value: Record<string, unknown>
  update: (value: Record<string, unknown>) => Promise<unknown>
  continue: () => Promise<PendingSaleUpgradeCursor | null>
}

export const classifyLegacyPendingSales = async (
  firstCursor: PendingSaleUpgradeCursor | null,
): Promise<void> => {
  let cursor = firstCursor
  while (cursor) {
    const current = cursor.value
    const classified = classifyPendingSaleForUpgrade(current)
    if (classified !== current) {
      await cursor.update(classified)
    }
    cursor = await cursor.continue()
  }
}

let dbPromise: Promise<IDBPDatabase> | null = null

const getDB = () => {
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, DB_VERSION, {
      upgrade(db, oldVersion, _newVersion, transaction) {
        // v1 stores
        if (!db.objectStoreNames.contains('products')) {
          const store = db.createObjectStore('products', { keyPath: 'id' })
          store.createIndex('barcode', 'barcode', { unique: true })
        }
        if (!db.objectStoreNames.contains('pending_sales')) {
          db.createObjectStore('pending_sales', { keyPath: 'localId', autoIncrement: true })
        }
        // v2 — cache metadata (timestamp لمعرفة تاريخ آخر تحديث)
        if (oldVersion < 2) {
          if (!db.objectStoreNames.contains('cache_meta')) {
            db.createObjectStore('cache_meta', { keyPath: 'key' })
          }
          if (!db.objectStoreNames.contains('customers')) {
            db.createObjectStore('customers', { keyPath: 'id' })
          }
        }
        if (oldVersion < 3 && !db.objectStoreNames.contains('product_barcodes')) {
          const barcodeStore = db.createObjectStore('product_barcodes', { keyPath: 'barcode' })
          barcodeStore.createIndex('product_id', 'product_id')
        }
        if (oldVersion < 4) {
          const productStore = transaction.objectStore('products')
          if (!productStore.indexNames.contains('parent_product_id')) {
            productStore.createIndex('parent_product_id', 'parent_product_id')
          }
        }
        if (oldVersion < 5) {
          const pendingStore = transaction.objectStore('pending_sales')
          if (!pendingStore.indexNames.contains('owner_user_id')) {
            pendingStore.createIndex('owner_user_id', 'ownerUserId')
          }
          if (!pendingStore.indexNames.contains('branch_id')) {
            pendingStore.createIndex('branch_id', 'branchId')
          }
          if (!pendingStore.indexNames.contains('owner_branch')) {
            pendingStore.createIndex('owner_branch', ['ownerUserId', 'branchId'])
          }

          void pendingStore.openCursor().then((cursor) => classifyLegacyPendingSales(cursor))
        }
      },
    })
  }
  return dbPromise
}

// ── Products cache ──

export const nestCatalogProducts = (rows: Product[]): Product[] => {
  const byId = new Map<number, Product>()
  for (const row of rows) {
    byId.set(row.id, { ...row, sizes: [] })
  }

  const roots: Product[] = []
  for (const product of byId.values()) {
    if (product.parent_product_id) {
      const parent = byId.get(product.parent_product_id)
      if (parent) {
        parent.sizes = [...(parent.sizes ?? []), product]
      }
    } else {
      roots.push(product)
    }
  }
  return roots
}

export interface ProductCatalogCacheState {
  checkpoint: string | null;
  complete: boolean;
}

export const getProductCatalogState = async (expectedScope: string): Promise<ProductCatalogCacheState> => {
  const db = await getDB()
  const [scope, checkpoint, complete] = await Promise.all([
    db.get('cache_meta', 'products_catalog_scope'),
    db.get('cache_meta', 'products_catalog_checkpoint'),
    db.get('cache_meta', 'products_catalog_complete'),
  ])
  if (scope?.value !== expectedScope) {
    return { checkpoint: null, complete: false }
  }
  return {
    checkpoint: typeof checkpoint?.value === 'string' ? checkpoint.value : null,
    complete: complete?.value === true,
  }
}

interface ApplyProductCatalogPageOptions {
  products: Product[];
  scope: string;
  checkpoint: string;
  version: number;
  reset: boolean;
  snapshotComplete: boolean;
}

export const applyProductCatalogPage = async ({
  products,
  scope,
  checkpoint,
  version,
  reset,
  snapshotComplete,
}: ApplyProductCatalogPageOptions): Promise<void> => {
  const db = await getDB()
  const tx = db.transaction(['products', 'product_barcodes', 'cache_meta'], 'readwrite')
  const productStore = tx.objectStore('products')
  const barcodeStore = tx.objectStore('product_barcodes')
  const metaStore = tx.objectStore('cache_meta')

  if (reset) {
    await productStore.clear()
    await barcodeStore.clear()
    await metaStore.put({ key: 'products_catalog_complete', value: false })
  }

  const existingScope = await metaStore.get('products_catalog_scope')
  if (!reset && existingScope?.value !== scope) {
    tx.abort()
    throw new Error('Product catalog scope changed during synchronization')
  }

  const removeProduct = async (productId: number): Promise<void> => {
    const barcodeKeys = await barcodeStore.index('product_id').getAllKeys(productId)
    await Promise.all(barcodeKeys.map((key) => barcodeStore.delete(key)))
    await productStore.delete(productId)
  }

  for (const product of products) {
    if (product._deleted || product.deleted_at) {
      const childIds = await productStore.index('parent_product_id').getAllKeys(product.id)
      for (const childId of childIds) {
        await removeProduct(Number(childId))
      }
      await removeProduct(product.id)
      continue
    }

    await removeProduct(product.id)
    const storedProduct = { ...product }
    delete storedProduct._deleted
    delete storedProduct.sizes
    await productStore.put(storedProduct)
    const barcodes = [
      product.barcode,
      product.box_barcode,
      ...(product.additional_barcodes ?? []),
    ]
    for (const rawBarcode of barcodes) {
      const barcode = String(rawBarcode ?? '').trim()
      if (barcode) {
        await barcodeStore.put({ barcode, product_id: product.id })
      }
    }
  }

  await metaStore.put({ key: 'products_catalog_scope', value: scope })
  await metaStore.put({ key: 'products_catalog_checkpoint', value: checkpoint })
  await metaStore.put({ key: 'products_catalog_version', value: version })
  if (snapshotComplete) {
    await metaStore.put({ key: 'products_catalog_complete', value: true })
  }
  await metaStore.put({ key: 'products_updated_at', value: Date.now() })
  await tx.done
}

export const getProductsFromIDB = async (expectedScope?: string): Promise<Array<Product>> => {
  const db = await getDB()
  if (expectedScope) {
    const state = await getProductCatalogState(expectedScope)
    if (!state.complete) {
      return []
    }
  }
  return nestCatalogProducts(await db.getAll('products'))
}

export const getProductByBarcodeFromIDB = async (barcode: string): Promise<Product | null> => {
  const t = String(barcode).trim()
  const db = await getDB()
  const mapping = await db.get('product_barcodes', t)
  if (!mapping) return null
  return (await db.get('products', mapping.product_id)) ?? null
}

// ── Customers cache ──

export const saveCustomersToIDB = async (customers: Array<Customer>): Promise<void> => {
  const db = await getDB()
  const tx = db.transaction(['customers', 'cache_meta'], 'readwrite')
  const store = tx.objectStore('customers')
  const meta  = tx.objectStore('cache_meta')
  
  // مسح العملاء القدامى
  await store.clear()

  for (const c of customers) {
    store.put(c)
  }
  meta.put({ key: 'customers_updated_at', value: Date.now() })
  await tx.done
}

export const getCustomersFromIDB = async (): Promise<Array<Customer>> => {
  const db = await getDB()
  return db.getAll('customers')
}

// ── Cache metadata ──

/** اقرأ timestamp آخر تحديث لنوع بيانات محدد */
export const getCacheTimestamp = async (key: string): Promise<number | null> => {
  const db = await getDB()
  const entry = await db.get('cache_meta', `${key}_updated_at`)
  return entry?.value ?? null
}

/** هل الكاش قديم؟ (أقدم من maxAgeMs مللي ثانية) */
export const isCacheStale = async (key: string, maxAgeMs: number = 5 * 60 * 1000): Promise<boolean> => {
  const ts = await getCacheTimestamp(key)
  if (!ts) return true
  return (Date.now() - ts) > maxAgeMs
}

// ── Pending sales ──

const createIdempotencyKey = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `offline-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

const MAX_PENDING_SALES = 1000
const MAX_PENDING_SALE_BYTES = 512 * 1024

export const savePendingSale = async (
  saleData: Record<string, unknown>,
  owner: OfflineSaleOwner,
): Promise<IDBValidKey> => {
  if (
    !Number.isInteger(owner.ownerUserId)
    || owner.ownerUserId <= 0
    || !Number.isInteger(owner.branchId)
    || owner.branchId <= 0
  ) {
    throw new Error('An authenticated user and branch are required to queue an offline sale')
  }

  const serialized = JSON.stringify(saleData)
  if (new Blob([serialized]).size > MAX_PENDING_SALE_BYTES) {
    throw new Error('Offline sale payload is too large')
  }

  const db = await getDB()
  const tx = db.transaction('pending_sales', 'readwrite')
  const count = await tx.store
    .index('owner_branch')
    .count([owner.ownerUserId, owner.branchId])

  if (count >= MAX_PENDING_SALES) {
    tx.abort()
    await tx.done.catch(() => undefined)
    throw new Error('Offline sales queue is full')
  }

  const key = await tx.store.add({
    ...saleData,
    ownerUserId: owner.ownerUserId,
    branchId: owner.branchId,
    idempotencyKey: typeof saleData.idempotency_key === 'string'
      ? saleData.idempotency_key
      : createIdempotencyKey(),
    syncStatus: 'pending',
    retryCount: 0,
    savedAt: new Date().toISOString(),
    payloadBytes: new Blob([serialized]).size,
  })
  await tx.done
  return key
}

export const getPendingSales = async (owner: OfflineSaleOwner): Promise<PendingSaleRecord[]> => {
  const db = await getDB()
  const sales = await db.getAllFromIndex(
    'pending_sales',
    'owner_branch',
    [owner.ownerUserId, owner.branchId],
  ) as PendingSaleRecord[]
  return sales.filter((sale) => isPendingSaleOwnedBy(sale, owner))
}

export const getPendingSale = async (
  localId: IDBValidKey,
  owner: OfflineSaleOwner,
): Promise<PendingSaleRecord | null> => {
  const db = await getDB()
  const sale = await db.get('pending_sales', localId) as PendingSaleRecord | undefined
  return sale && isPendingSaleOwnedBy(sale, owner) ? sale : null
}

export const getSalesNeedingReview = async (
  owner: OfflineSaleOwner,
): Promise<PendingSaleRecord[]> => {
  const owned = await getPendingSales(owner)
  return owned.filter((sale) => (
    sale.syncStatus === 'review'
    || sale.syncStatus === 'conflict'
    || sale.syncStatus === 'permanent'
    || (sale.syncStatus === 'failed' && (sale.retryCount ?? 0) >= 3)
  ))
}

export const deletePendingSale = async (
  localId: IDBValidKey,
  owner: OfflineSaleOwner,
  isOwnerActive: () => boolean,
): Promise<boolean> => {
  const db = await getDB()
  const tx = db.transaction('pending_sales', 'readwrite')
  const store = tx.objectStore('pending_sales')
  const sale = await store.get(localId) as PendingSaleRecord | undefined
  if (!sale || !isPendingSaleOwnedBy(sale, owner) || !isOwnerActive()) {
    await tx.done
    return false
  }
  await store.delete(localId)
  await tx.done
  return true
}

/** تحديث حالة عملية بيع معلقة (failed/pending) مع سبب الخطأ */
export const updatePendingSaleStatus = async (
  localId: IDBValidKey,
  owner: OfflineSaleOwner,
  isOwnerActive: () => boolean,
  status: PendingSaleStatus,
  errorMsg?: string,
  consumeRetry = status === 'failed' || status === 'conflict' || status === 'permanent',
): Promise<boolean> => {
  const db = await getDB()
  const tx = db.transaction('pending_sales', 'readwrite')
  const store = tx.objectStore('pending_sales')
  const sale = await store.get(localId) as PendingSaleRecord | undefined
  if (!sale || !isPendingSaleOwnedBy(sale, owner) || !isOwnerActive()) {
    await tx.done
    return false
  }
  sale.syncStatus = status
  sale.lastError = errorMsg ?? null
  if (consumeRetry) {
    sale.retryCount = (sale.retryCount ?? 0) + 1
    sale.lastAttempt = new Date().toISOString()
  }
  await store.put(sale)
  await tx.done
  return true
}

/** مسح الكاش بالكامل (يستخدم عند تسجيل الخروج أو إعادة ضبط التطبيق) */
export const clearAllCache = async (): Promise<void> => {
  const db = await getDB()
  const tx = db.transaction(['products', 'product_barcodes', 'customers', 'cache_meta'], 'readwrite')
  await tx.objectStore('products').clear()
  await tx.objectStore('product_barcodes').clear()
  await tx.objectStore('customers').clear()
  await tx.objectStore('cache_meta').clear()
  await tx.done
}

