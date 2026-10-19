import React from 'react'
import { Loader2, ShieldCheck, HardDrive, CheckCircle2, AlertTriangle, RefreshCw } from 'lucide-react'

export type UpdateStep = 'downloading' | 'verifying' | 'preparing' | 'installing' | 'completed' | 'error'

export interface UpdateProgressProps {
  step: UpdateStep
  percent: number
  transferredBytes?: number
  totalBytes?: number
  errorMessage?: string | null
  onRetry?: () => void
  onDismiss?: () => void
}

export const UpdateProgress: React.FC<UpdateProgressProps> = ({
  step,
  percent,
  transferredBytes,
  totalBytes,
  errorMessage,
  onRetry,
  onDismiss,
}) => {
  const stepsMeta = [
    { key: 'downloading', label: 'التحميل', icon: Loader2 },
    { key: 'verifying', label: 'التحقق من الأمان', icon: ShieldCheck },
    { key: 'preparing', label: 'النسخ الاحتياطي', icon: HardDrive },
    { key: 'installing', label: 'التثبيت والتشغيل', icon: CheckCircle2 },
  ]

  const getStepIndex = (s: UpdateStep) => {
    switch (s) {
      case 'downloading': return 0
      case 'verifying': return 1
      case 'preparing': return 2
      case 'installing': return 3
      case 'completed': return 4
      default: return 0
    }
  }

  const currentIndex = getStepIndex(step)

  const formatBytes = (bytes?: number) => {
    if (!bytes || bytes <= 0) return '0 ميجابايت'
    const mb = bytes / (1024 * 1024)
    return mb >= 1 ? `${mb.toFixed(1)} ميجابايت` : `${Math.round(bytes / 1024)} كيلوبايت`
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/65 backdrop-blur-sm p-4 animate-in fade-in duration-200">
      <div className="bg-white dark:bg-gray-900 border border-emerald-500/20 dark:border-emerald-500/30 rounded-2xl shadow-2xl max-w-lg w-full p-6 space-y-6">
        {/* Title */}
        <div className="text-center space-y-1">
          {step === 'error' ? (
            <div className="flex flex-col items-center gap-2">
              <div className="p-3 bg-red-100 dark:bg-red-950/50 text-red-600 dark:text-red-400 rounded-full">
                <AlertTriangle className="w-8 h-8" />
              </div>
              <h3 className="text-xl font-bold text-gray-900 dark:text-white">تعذر إكمال التحديث</h3>
              <p className="text-sm text-gray-500 dark:text-gray-400 max-w-sm">
                {errorMessage || 'تعذر تحميل التحديث، يرجى التحقق من الاتصال بالإنترنت والمحاولة مجدداً.'}
              </p>
            </div>
          ) : step === 'completed' ? (
            <div className="flex flex-col items-center gap-2">
              <div className="p-3 bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 rounded-full">
                <CheckCircle2 className="w-8 h-8" />
              </div>
              <h3 className="text-xl font-bold text-gray-900 dark:text-white">تم تحديث النظام بنجاح!</h3>
              <p className="text-sm text-gray-500 dark:text-gray-400">
                جاري إعادة تشغيل النظام لتطبيق التحديث...
              </p>
            </div>
          ) : (
            <div className="flex flex-col items-center gap-2">
              <div className="p-3 bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 rounded-full animate-spin">
                <Loader2 className="w-8 h-8" />
              </div>
              <h3 className="text-xl font-bold text-gray-900 dark:text-white">جاري تحديث نظام نقاط البيع</h3>
              <p className="text-sm text-gray-500 dark:text-gray-400">
                يرجى الانتظار، لا تقم بإغلاق البرنامج أو فصل الكهرباء
              </p>
            </div>
          )}
        </div>

        {/* Steps Progress Checklist */}
        {step !== 'error' && (
          <div className="grid grid-cols-4 gap-2 pt-2">
            {stepsMeta.map((s, idx) => {
              const isPast = currentIndex > idx || step === 'completed'
              const isCurrent = currentIndex === idx && step !== 'completed'
              const Icon = s.icon

              return (
                <div key={s.key} className="flex flex-col items-center text-center space-y-1.5">
                  <div
                    className={`w-9 h-9 rounded-full flex items-center justify-center transition-all ${
                      isPast
                        ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/30'
                        : isCurrent
                        ? 'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 ring-2 ring-emerald-500 animate-pulse'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-400'
                    }`}
                  >
                    <Icon className="w-4 h-4" />
                  </div>
                  <span
                    className={`text-[11px] font-medium leading-tight ${
                      isPast || isCurrent
                        ? 'text-gray-900 dark:text-white font-semibold'
                        : 'text-gray-400 dark:text-gray-500'
                    }`}
                  >
                    {s.label}
                  </span>
                </div>
              )
            })}
          </div>
        )}

        {/* Progress Bar & Details */}
        {step !== 'error' && step !== 'completed' && (
          <div className="space-y-2 pt-1">
            <div className="flex justify-between text-xs font-semibold text-gray-700 dark:text-gray-300">
              <span>{step === 'downloading' ? 'جاري التحميل...' : step === 'verifying' ? 'التحقق من السلامة...' : 'تجهيز التحديث...'}</span>
              <span>{Math.round(percent)}%</span>
            </div>
            <div className="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-3.5 overflow-hidden p-0.5 border border-gray-200 dark:border-gray-700">
              <div
                className="bg-gradient-to-r from-emerald-500 to-teal-500 h-full rounded-full transition-all duration-300 shadow-sm"
                style={{ width: `${Math.max(5, Math.min(100, percent))}%` }}
              />
            </div>
            {Boolean(totalBytes && totalBytes > 0) && (
              <div className="flex justify-between text-[11px] text-gray-400 dark:text-gray-500">
                <span>تم تحميل: {formatBytes(transferredBytes)}</span>
                <span>الحجم الكلي: {formatBytes(totalBytes)}</span>
              </div>
            )}
          </div>
        )}

        {/* Actions for Error State */}
        {step === 'error' && (
          <div className="flex items-center justify-center gap-3 pt-2">
            {onDismiss && (
              <button
                onClick={onDismiss}
                className="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 text-sm font-medium transition-colors"
              >
                إلغاء
              </button>
            )}
            {onRetry && (
              <button
                onClick={onRetry}
                className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/20 transition-all transform active:scale-95"
              >
                <RefreshCw className="w-4 h-4" />
                <span>إعادة المحاولة</span>
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export default UpdateProgress
