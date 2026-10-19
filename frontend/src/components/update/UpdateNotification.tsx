import React from 'react'
import { Download, X, Sparkles, AlertCircle } from 'lucide-react'

export interface UpdateNotificationProps {
  currentVersion: string
  newVersion: string
  updateSize?: number | string
  releaseNotes?: string
  onStartUpdate: () => void
  onDismiss: () => void
  isMandatory?: boolean
}

export const UpdateNotification: React.FC<UpdateNotificationProps> = ({
  currentVersion,
  newVersion,
  updateSize,
  releaseNotes,
  onStartUpdate,
  onDismiss,
  isMandatory = false,
}) => {
  const formattedSize = React.useMemo(() => {
    if (!updateSize) return null
    if (typeof updateSize === 'string') return updateSize
    const mb = updateSize / (1024 * 1024)
    return mb >= 1 ? `${mb.toFixed(1)} ميجابايت` : `${Math.round(updateSize / 1024)} كيلوبايت`
  }, [updateSize])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-emerald-500/20 dark:border-emerald-500/30 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-gradient-to-r from-emerald-600 to-teal-600 text-white p-6 relative">
          {!isMandatory && (
            <button
              onClick={onDismiss}
              className="absolute top-4 left-4 text-white/80 hover:text-white bg-black/10 hover:bg-black/20 p-1.5 rounded-full transition-colors"
              aria-label="إغلاق"
            >
              <X className="w-5 h-5" />
            </button>
          )}
          <div className="flex items-center gap-3">
            <div className="p-3 bg-white/20 rounded-xl backdrop-blur-md">
              <Sparkles className="w-6 h-6 text-emerald-100" />
            </div>
            <div>
              <h3 className="text-xl font-bold text-white">يوجد تحديث جديد لنظام نقاط البيع</h3>
              <p className="text-emerald-100 text-sm mt-0.5">
                إصدار جديد متاح لتحسين الأداء وإضافة ميزات جديدة
              </p>
            </div>
          </div>
        </div>

        {/* Body */}
        <div className="p-6 space-y-4 text-gray-800 dark:text-gray-200">
          {/* Version Pill Banner */}
          <div className="flex items-center justify-between p-3.5 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 rounded-xl">
            <div>
              <div className="text-xs text-gray-500 dark:text-gray-400">الإصدار الحالي</div>
              <div className="font-semibold text-gray-700 dark:text-gray-300">{currentVersion}</div>
            </div>
            <div className="text-emerald-600 dark:text-emerald-400 font-bold text-lg">←</div>
            <div className="text-left">
              <div className="text-xs text-emerald-600 dark:text-emerald-400 font-medium">الإصدار الجديد</div>
              <div className="font-bold text-emerald-700 dark:text-emerald-300 text-base">{newVersion}</div>
            </div>
          </div>

          {/* Size info if available */}
          {formattedSize && (
            <div className="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
              <span>حجم التحديث:</span>
              <span className="font-semibold text-gray-700 dark:text-gray-300">{formattedSize}</span>
            </div>
          )}

          {/* Release Notes */}
          {releaseNotes && (
            <div className="space-y-1.5">
              <div className="text-xs font-semibold text-gray-600 dark:text-gray-400">أبرز التحسينات والمميزات:</div>
              <div className="p-3 bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-800 rounded-xl text-sm leading-relaxed text-gray-600 dark:text-gray-300 max-h-36 overflow-y-auto whitespace-pre-line">
                {releaseNotes}
              </div>
            </div>
          )}

          {/* Safe update notice */}
          <div className="flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/30 p-2.5 rounded-lg">
            <AlertCircle className="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
            <span>يتم الاحتفاظ بجميع بيانات وفواتير وسجلات المتجر بأمان تام أثناء التحديث.</span>
          </div>
        </div>

        {/* Footer Actions */}
        <div className="p-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800 flex items-center justify-end gap-3">
          {!isMandatory && (
            <button
              onClick={onDismiss}
              className="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 text-sm font-medium transition-colors"
            >
              لاحقاً
            </button>
          )}
          <button
            onClick={onStartUpdate}
            className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/20 transition-all transform active:scale-95"
          >
            <Download className="w-4 h-4" />
            <span>تحديث الآن</span>
          </button>
        </div>
      </div>
    </div>
  )
}

export default UpdateNotification
