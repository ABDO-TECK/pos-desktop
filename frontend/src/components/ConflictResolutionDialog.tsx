import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  deletePendingSale,
  getSalesNeedingReview,
  isPendingSaleOwnedBy,
  updatePendingSaleStatus,
} from '../utils/idb'
import type { OfflineSaleOwner, PendingSaleRecord } from '../utils/idb'
import { OFFLINE_SALES_UPDATED_EVENT, syncPendingSales } from '../utils/offlineSync'
import useAuthStore from '../store/authStore'
import toast from 'react-hot-toast'

export const ConflictResolutionDialog = () => {
  const user = useAuthStore((state) => state.user)
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const [conflicts, setConflicts] = useState<PendingSaleRecord[]>([])
  const [isOpen, setIsOpen] = useState(false)

  const owner: OfflineSaleOwner | null = useMemo(
    () => user && user.id > 0 && Number.isInteger(user.branch_id) && user.branch_id > 0
      ? { ownerUserId: user.id, branchId: user.branch_id }
      : null,
    [user],
  )
  const ownerKey = owner ? `${owner.ownerUserId}:${owner.branchId}` : 'anonymous'
  const [prevOwnerKey, setPrevOwnerKey] = useState(ownerKey)
  if (prevOwnerKey !== ownerKey) {
    setPrevOwnerKey(ownerKey)
    setIsOpen(false)
    setConflicts([])
  }

  const isOwnerActive = useCallback(() => {
    if (!owner) return false
    const current = useAuthStore.getState()
    return current.isAuthenticated
      && current.user?.id === owner.ownerUserId
      && current.user.branch_id === owner.branchId
  }, [owner])

  const loadConflicts = useCallback(async () => {
    if (!isAuthenticated || !owner) {
      setConflicts([])
      return
    }
    const sales = await getSalesNeedingReview(owner)
    if (!isOwnerActive()) return
    setConflicts(sales.filter((sale) => isPendingSaleOwnedBy(sale, owner)))
  }, [isAuthenticated, isOwnerActive, owner])

  const visibleConflicts = useMemo(
    () => owner
      ? conflicts.filter((sale) => isPendingSaleOwnedBy(sale, owner))
      : [],
    [conflicts, owner],
  )

  useEffect(() => {
    let ignore = false
    const fetchCurrentConflicts = async () => {
      if (!isAuthenticated || !owner) {
        if (!ignore) setConflicts([])
        return
      }
      const sales = await getSalesNeedingReview(owner)
      if (!ignore && isOwnerActive()) {
        setConflicts(sales.filter((sale) => isPendingSaleOwnedBy(sale, owner)))
      }
    }

    void fetchCurrentConflicts()
    let refreshTimer: number | null = null
    const handleOnline = () => {
      refreshTimer = window.setTimeout(() => {
        void fetchCurrentConflicts()
      }, 5000)
    }
    const handleUpdated = () => {
      void fetchCurrentConflicts()
    }
    window.addEventListener('online', handleOnline)
    window.addEventListener(OFFLINE_SALES_UPDATED_EVENT, handleUpdated)
    return () => {
      ignore = true
      window.removeEventListener('online', handleOnline)
      window.removeEventListener(OFFLINE_SALES_UPDATED_EVENT, handleUpdated)
      if (refreshTimer !== null) window.clearTimeout(refreshTimer)
    }
  }, [isAuthenticated, isOwnerActive, owner])

  const isModalOpen = isOpen && visibleConflicts.length > 0

  if (!owner || visibleConflicts.length === 0) return null

  const handleRetryAllOwned = async () => {
    for (const conflict of visibleConflicts) {
      await updatePendingSaleStatus(conflict.localId, owner, isOwnerActive, 'pending')
    }
    toast.loading('جاري إعادة مزامنة العمليات المملوكة لهذا المستخدم والفرع...', { id: 'retryAll' })
    await syncPendingSales(owner, isOwnerActive)
    toast.success('اكتملت المحاولة', { id: 'retryAll' })
    await loadConflicts()
  }

  const handleDiscard = async (sale: PendingSaleRecord) => {
    if (!isPendingSaleOwnedBy(sale, owner)) return
    if (!window.confirm('هل أنت متأكد من حذف هذه العملية المحلية؟ لا يمكن التراجع.')) return

    await deletePendingSale(sale.localId, owner, isOwnerActive)
    toast.success('تم حذف العملية المحلية')
    await loadConflicts()
  }

  const handleRetryOne = async (sale: PendingSaleRecord) => {
    if (!isPendingSaleOwnedBy(sale, owner)) return
    await updatePendingSaleStatus(sale.localId, owner, isOwnerActive, 'pending')
    await syncPendingSales(owner, isOwnerActive)
    await loadConflicts()
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        className="fixed bottom-4 left-4 bg-red-600 text-white p-3 rounded-full shadow-lg cursor-pointer flex items-center gap-2 hover:bg-red-700 transition z-50"
        title="عمليات بيع تحتاج إلى مراجعة"
      >
        <span>⚠️</span>
        <span className="font-bold">{visibleConflicts.length} للمراجعة</span>
      </button>

      {isModalOpen && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col max-h-[90vh]">
            <div className="p-4 border-b flex justify-between items-center bg-red-50 text-red-800 rounded-t-lg">
              <h2 className="text-xl font-bold">⚠️ عمليات البيع التي تحتاج إلى مراجعة</h2>
              <button type="button" onClick={() => setIsOpen(false)} className="text-2xl font-bold">&times;</button>
            </div>

            <div className="p-4 overflow-y-auto flex-1">
              <p className="text-gray-600 mb-4">
                تظهر هنا فقط العمليات المحلية المملوكة للمستخدم والفرع الحاليين.
              </p>

              <div className="space-y-4">
                {visibleConflicts.map((sale) => {
                  const isOwned = isPendingSaleOwnedBy(sale, owner)
                  return (
                    <div key={String(sale.localId)} className="border border-red-200 rounded p-4 bg-red-50/30">
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <span className="text-sm text-gray-500 block mb-1">
                            تاريخ العملية: {new Date(sale.savedAt).toLocaleString('ar-EG')}
                          </span>
                          <span className="text-sm text-gray-600 block">
                            المالك: المستخدم {sale.ownerUserId} / الفرع {sale.branchId}
                          </span>
                          <strong className="text-red-700 text-sm block">
                            السبب: {sale.lastError || 'تعارض في المزامنة'}
                          </strong>
                        </div>
                        <span className="px-2 py-1 text-xs rounded text-white bg-orange-500">
                          تعارض
                        </span>
                      </div>

                      <div className="flex gap-2 mt-4">
                        {isOwned && (
                          <button
                            type="button"
                            onClick={() => void handleRetryOne(sale)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm transition"
                          >
                            إعادة محاولة
                          </button>
                        )}
                        {isOwned && (
                          <button
                            type="button"
                            onClick={() => void handleDiscard(sale)}
                            className="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm transition"
                          >
                            حذف العملية
                          </button>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            </div>

            <div className="p-4 border-t flex justify-end gap-3 bg-gray-50 rounded-b-lg">
              {visibleConflicts.length > 0 && (
                <button
                  type="button"
                  onClick={() => void handleRetryAllOwned()}
                  className="bg-blue-800 hover:bg-blue-900 text-white px-4 py-2 rounded transition"
                >
                  إعادة محاولة العمليات المملوكة
                </button>
              )}
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                className="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded transition"
              >
                إغلاق
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
