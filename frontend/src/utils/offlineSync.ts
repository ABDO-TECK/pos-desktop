import {
  deletePendingSale,
  getPendingSale,
  getPendingSales,
  isPendingSaleOwnedBy,
  updatePendingSaleStatus,
} from './idb'
import type { OfflineSaleOwner, PendingSaleRecord } from './idb'
import { createSale } from '../api/endpoints'
import toast from 'react-hot-toast'

let syncing = false
const MAX_RETRIES = 3
const SYNC_CONCURRENCY = 4
export const OFFLINE_SALES_UPDATED_EVENT = 'offline-sales-updated'

type SyncOutcome = 'synced' | 'failed' | 'conflict' | 'auth' | 'stopped' | 'skipped'

function isSaleCreatePayload(value: unknown): value is SaleCreatePayload {
  if (!value || typeof value !== 'object') return false
  const payload = value as Record<string, unknown>
  return typeof payload.idempotency_key === 'string'
    && Array.isArray(payload.items)
    && typeof payload.discount === 'number'
    && typeof payload.payment_method === 'string'
    && typeof payload.amount_paid === 'number'
    && (payload.status === 'completed' || payload.status === 'reserved')
    && typeof payload.shipping_cost === 'number'
}

/** ØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹ Ø§Ù„Ø®Ø·Ø£ Ù„Ù…Ø¹Ø±ÙØ© Ù‡Ù„ Ù†Ø¹ÙŠØ¯ Ø§Ù„Ù…Ø­Ø§ÙˆÙ„Ø© Ø£Ù… Ù„Ø§ */
export const classifyError = (err: unknown): 'auth' | 'retry' | 'conflict' | 'permanent' => {
  if (!err || typeof err !== 'object') return 'retry'
  const status = (err as { response?: { status?: number } })?.response?.status
  if (status === 401 || status === 403) return 'auth'
  // 409 = conflict, 422 = validation error, 404 = not found
  if (status === 409 || status === 422 || status === 404) return 'conflict'
  // 400 = bad request â€” Ù„Ù† ØªÙ†Ø¬Ø­ Ø£Ø¨Ø¯Ø§Ù‹
  if (status === 400) return 'permanent'
  // Ø£ÙŠ Ø®Ø·Ø£ Ø¢Ø®Ø± (500, network) â€” Ø£Ø¹Ø¯ Ø§Ù„Ù…Ø­Ø§ÙˆÙ„Ø©
  return 'retry'
}

const processPendingSale = async (
  queuedSale: PendingSaleRecord,
  owner: OfflineSaleOwner,
  isOwnerActive: () => boolean,
): Promise<SyncOutcome> => {
  const sale = await getPendingSale(queuedSale.localId, owner)
  if (!sale || !isPendingSaleOwnedBy(sale, owner) || !isOwnerActive()) return 'skipped'

  const {
    localId,
    savedAt: _savedAt,
    syncStatus,
    lastError: _lastError,
    retryCount,
    lastAttempt: _lastAttempt,
    ownerUserId: _ownerUserId,
    branchId: _branchId,
    reviewReason: _reviewReason,
    payloadBytes: _payloadBytes,
    idempotencyKey,
    ...storedSaleData
  } = sale
  const saleData: Record<string, unknown> = {
    ...storedSaleData,
    ...(typeof idempotencyKey === 'string' && storedSaleData.idempotency_key === undefined
      ? { idempotency_key: idempotencyKey }
      : {}),
  }

  if (syncStatus === 'permanent' || syncStatus === 'review') return 'skipped'
  if (((retryCount as number) ?? 0) >= MAX_RETRIES && syncStatus === 'failed') {
    console.warn(`[OfflineSync] Sale ${String(localId)} exceeded max retries, skipping.`)
    return 'skipped'
  }

  try {
    if (!isSaleCreatePayload(saleData)) {
      throw new Error('Pending sale payload is incomplete')
    }
    if (!isOwnerActive()) return 'stopped'
    await createSale(saleData)
    if (!isOwnerActive()) return 'stopped'
    const deleted = await deletePendingSale(localId, owner, isOwnerActive)
    if (!deleted) return isOwnerActive() ? 'skipped' : 'stopped'
    console.log(`[OfflineSync] Sale ${String(localId)} synced and removed.`)
    return 'synced'
  } catch (err) {
    if (!isOwnerActive()) return 'stopped'

    const errType = classifyError(err)
    const errMsg = (err as Error).message || 'Unknown error'

    if (errType === 'auth') {
      console.warn(`[OfflineSync] Authentication paused sale ${String(localId)}; queue preserved.`)
      return 'auth'
    }
    if (errType === 'conflict') {
      const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'conflict', errMsg)
      if (!updated) return isOwnerActive() ? 'skipped' : 'stopped'
      console.warn(`[OfflineSync] Sale ${String(localId)}: conflict â€” ${errMsg}`)
      return 'conflict'
    }
    if (errType === 'permanent') {
      const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'permanent', errMsg)
      if (!updated) return isOwnerActive() ? 'skipped' : 'stopped'
      console.error(`[OfflineSync] Sale ${String(localId)}: permanent failure â€” ${errMsg}`)
      return 'failed'
    }

    const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'failed', errMsg)
    if (!updated) return isOwnerActive() ? 'skipped' : 'stopped'
    console.error(`[OfflineSync] Sale ${String(localId)}: temporary failure â€” will retry`)
    return 'failed'
  }
}

export const syncPendingSales = async (
  owner: OfflineSaleOwner,
  isOwnerActive: () => boolean = () => true,
): Promise<void> => {
  if (syncing || !navigator.onLine) return
  syncing = true

  try {
    const pending = (await getPendingSales(owner))
      .filter((sale) => isPendingSaleOwnedBy(sale, owner))
    if (pending.length === 0 || !isOwnerActive()) return

    console.log(`[OfflineSync] Syncing ${pending.length} pending sale(s)...`)
    let syncedCount = 0
    let failedCount = 0
    let conflictCount = 0

    let nextIndex = 0
    let halted = false
    const worker = async (): Promise<void> => {
      while (!halted) {
        if (!isOwnerActive()) {
          halted = true
          return
        }
        const index = nextIndex++
        if (index >= pending.length) return

        const outcome = await processPendingSale(pending[index], owner, isOwnerActive)
        if (outcome === 'auth' || outcome === 'stopped') {
          halted = true
          return
        }
        if (outcome === 'synced') syncedCount++
        if (outcome === 'failed') failedCount++
        if (outcome === 'conflict') conflictCount++
      }
    }

    const workerCount = Math.min(SYNC_CONCURRENCY, pending.length)
    await Promise.all(Array.from({ length: workerCount }, () => worker()))

    if (syncedCount > 0) {
      toast.success(`Offline sales synced: ${syncedCount}`)
    }
    if (conflictCount > 0) {
      toast.error(`Offline sale conflicts: ${conflictCount}`)
    }
    if (failedCount > 0) {
      toast.error(`Offline sales failed: ${failedCount}. They will be retried later.`)
    }
    if (syncedCount > 0 || failedCount > 0 || conflictCount > 0) {
      window.dispatchEvent(new Event(OFFLINE_SALES_UPDATED_EVENT))
    }
  } finally {
    syncing = false
  }
}

// Auto-sync when coming back online
export const initOfflineSync = (
  owner: OfflineSaleOwner | null,
  isOwnerActive: () => boolean = () => true,
): (() => void) => {
  if (!owner) return () => undefined

  const handleOnline = () => {
    console.log('[OfflineSync] Back online, syncing...')
    void syncPendingSales(owner, isOwnerActive)
  }
  window.addEventListener('online', handleOnline)

  // Also try on startup
  const startupTimer = navigator.onLine
    ? window.setTimeout(() => void syncPendingSales(owner, isOwnerActive), 3000)
    : null

  return () => {
    window.removeEventListener('online', handleOnline)
    if (startupTimer !== null) window.clearTimeout(startupTimer)
  }
}
