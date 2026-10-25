import { useCallback, useEffect, useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import { 
  RefreshCw, 

  List, 
  Download, 
  History, 
  RotateCcw, 
  ShieldCheck, 
  AlertTriangle, 
  CheckCircle2, 
  FileCode2, 
  Clock, 
  ExternalLink,
  Activity,
  Wrench,
  HeartPulse
} from 'lucide-react'
import toast from 'react-hot-toast'
import { 
  getUpdateJob,
  applyUpdate, 
  getUpdateHistory, 
  rollbackUpdate, 
  getUpdateSnapshots,
  setUpdateChannel,
  diagnoseUpdateRecovery,
  executeRecoveryAction,
  getRecoveryAuditLogs,
  runPostUpdateHealthCheck
} from '../../api/endpoints'

import useUpdateStore from '../../store/updateStore'
import useCartStore from '../../store/cartStore'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'
import RollbackPoints from './RollbackPoints'
import ChannelTransitionPanel from './ChannelTransitionPanel'
import { isUpdateRestarting, withUpdateRestart } from '../../utils/updateRestart'
import type { TransitionPhase } from '../../hooks/useChannelTransition'

const ACTIVE_JOB_STORAGE_KEY = 'pos.active_update_job_id'
const ADMISSION_UNKNOWN_STORAGE_KEY = 'pos.update_admission_unknown'
const HANDOFF_STORAGE_KEY_PREFIX = 'pos.update_handoff_'

function isAdmissionUnknown(): boolean {
  try {
    return sessionStorage.getItem(ADMISSION_UNKNOWN_STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}

function setAdmissionUnknown(val: boolean) {
  try {
    if (val) {
      sessionStorage.setItem(ADMISSION_UNKNOWN_STORAGE_KEY, 'true')
    } else {
      sessionStorage.removeItem(ADMISSION_UNKNOWN_STORAGE_KEY)
    }
  } catch {
    // Ignore storage errors
  }
}

function getHandoffMarker(jobId: number): 'idle' | 'running' | 'done' | 'failed' {
  try {
    const raw = sessionStorage.getItem(HANDOFF_STORAGE_KEY_PREFIX + jobId)
    if (raw === 'running' || raw === 'done' || raw === 'failed') return raw
  } catch {
    // Ignore storage errors
  }
  return 'idle'
}

function setHandoffMarker(jobId: number, marker: 'idle' | 'running' | 'done' | 'failed') {
  try {
    if (marker === 'idle') {
      sessionStorage.removeItem(HANDOFF_STORAGE_KEY_PREFIX + jobId)
    } else {
      sessionStorage.setItem(HANDOFF_STORAGE_KEY_PREFIX + jobId, marker)
    }
  } catch {
    // Ignore storage errors
  }
}

function getInitialActiveJobId(): number | null {
  try {
    const raw = sessionStorage.getItem(ACTIVE_JOB_STORAGE_KEY)
    if (!raw) return null
    const parsed = Number(raw)
    if (Number.isInteger(parsed) && parsed > 0) {
      return parsed
    }
  } catch {
    // sessionStorage unavailable
  }
  return null
}

async function hasDeltaHandoffCapability(): Promise<boolean> {
  try {
    return Boolean((await window.posRuntime?.getDeltaCapability?.())?.capable)
  } catch {
    return false
  }
}

export default function UpdateSection() {
  const { confirm } = useConfirmStore()
  const updaterApi = typeof window !== 'undefined' ? window.electronAPI?.updater : undefined
  // Electron updater methods support: updaterApi?.getStatus, updaterApi?.download, updaterApi?.install
  void updaterApi

  const { 
    hasUpdate, 
    currentVersion, 
    latestVersion, 
    changelog, 
    isChecking, 
    forceCheck, 
    hydrateLocalStatus,
    setChannelState,
    updatesDisabled, 
    updatesDisabledMessage, 
    updatesUnreachable, 
    updateErrorMessage 
  } = useUpdateStore()

  const handoffDoneRef = useRef(false)
  const handoffStatusRef = useRef<'idle' | 'running' | 'done' | 'failed'>('idle')
  const isBootstrapActiveRef = useRef(false)
  const localStatusGenerationRef = useRef(0)

  const [activeJobId, setActiveJobIdState] = useState<number | null>(() => getInitialActiveJobId())
  const [pollNonce, setPollNonce] = useState(0)

  const setActiveJobId = useCallback((id: number | null) => {
    setActiveJobIdState(id)
    try {
      if (id !== null && Number.isInteger(id) && id > 0) {
        sessionStorage.setItem(ACTIVE_JOB_STORAGE_KEY, String(id))
      } else {
        sessionStorage.removeItem(ACTIVE_JOB_STORAGE_KEY)
      }
    } catch {
      // Ignore storage errors
    }
  }, [])

  const [normalApplyingUpdate, setApplyingUpdate] = useState<boolean>(() => {
    return getInitialActiveJobId() !== null || isAdmissionUnknown()
  })
  const [transitionBusy, setTransitionBusy] = useState(false)
  const [transitionPhase, setTransitionPhase] = useState<TransitionPhase>('idle')

  const handleTransitionBusy = useCallback((busy: boolean, phase?: TransitionPhase) => {
    setTransitionBusy(busy)
    setTransitionPhase(phase ?? (busy ? 'preparing' : 'idle'))
  }, [])

  const applyingUpdate = normalApplyingUpdate || transitionBusy
  const [showChangelog, setShowChangelog] = useState(false)
  const [showHistory, setShowHistory] = useState(false)
  const [showSnapshots, setShowSnapshots] = useState(false)
  const [updateLogs, setUpdateLogs] = useState<string[]>([])
  const [bootstrapRequired, setBootstrapRequired] = useState(false)

  // Status and Real-time progress data
  const [updateStatus, setUpdateStatus] = useState<UpdateStatusData | null>(null)
  const [historyList, setHistoryList] = useState<UpdateHistoryRecord[]>([])
  const [snapshotsList, setSnapshotsList] = useState<UpdateSnapshot[]>([])
  const [rollingBack, setRollingBack] = useState(false)
  const [currentChannel, setCurrentChannel] = useState<'stable' | 'beta' | null>(null)
  const [savingChannel, setSavingChannel] = useState(false)
  const [jobProgress, setJobProgress] = useState<{ downloaded_bytes: number; total_bytes: number; download_attempt: number } | null>(null)
  const [jobState, setJobState] = useState<string | null>(() => {
    if (isAdmissionUnknown()) return 'needs_attention'
    const initialJob = getInitialActiveJobId()
    if (initialJob) {
      const marker = getHandoffMarker(initialJob)
      if (marker === 'running' || marker === 'failed') return 'needs_attention'
      if (marker === 'done') return 'completed'
      return 'processing'
    }
    return null
  })

  // Recovery & Self-Healing states
  const [recoveryDiag, setRecoveryDiag] = useState<RecoveryDiagnosisData | null>(null)
  const [recoveryLogs, setRecoveryLogs] = useState<RecoveryAuditEntry[]>([])
  const [showRecoveryModal, setShowRecoveryModal] = useState(false)
  const [runningHealthCheck, setRunningHealthCheck] = useState(false)
  const [executingRecovery, setExecutingRecovery] = useState(false)

  const activeChangelog: unknown[] = Array.isArray(changelog) && changelog.length > 0
    ? changelog
    : (Array.isArray(updateStatus?.release_info?.changelog) ? updateStatus.release_info.changelog : [])

  // Fast authoritative hydration of local status shared with global store
  const loadStatusOnly = useCallback(async (force = false) => {
    const generation = ++localStatusGenerationRef.current
    try {
      const data = await hydrateLocalStatus(force)
      if (generation < localStatusGenerationRef.current) return
      if (data) {
        setUpdateStatus(data)
        if (data.channel) {
          setCurrentChannel(data.channel)
        }
      }
    } catch {
      // Non-blocking
    }
  }, [hydrateLocalStatus])

  // Independent and lazy retrieval of history, snapshots, recovery diagnostics, and audit logs
  const loadHistory = useCallback(async () => {
    try {
      const res = await getUpdateHistory({ hideGlobalError: true })
      if (Array.isArray(res.data?.data)) {
        setHistoryList(res.data.data)
      }
    } catch { /* Non-blocking */ }
  }, [])

  const loadSnapshots = useCallback(async () => {
    try {
      const res = await getUpdateSnapshots({ hideGlobalError: true })
      if (Array.isArray(res.data?.data)) {
        setSnapshotsList(res.data.data)
      }
    } catch { /* Non-blocking */ }
  }, [])

  const loadRecoveryData = useCallback(async () => {
    try {
      const [diagRes, auditRes] = await Promise.allSettled([
        diagnoseUpdateRecovery({ hideGlobalError: true }),
        getRecoveryAuditLogs(20, { hideGlobalError: true }),
      ])
      if (diagRes.status === 'fulfilled' && diagRes.value.data?.data) {
        setRecoveryDiag(diagRes.value.data.data)
      }
      if (auditRes.status === 'fulfilled' && Array.isArray(auditRes.value.data?.data?.logs)) {
        setRecoveryLogs(auditRes.value.data.data.logs)
      }
    } catch { /* Non-blocking */ }
  }, [])

  const loadAll = useCallback(async (force = false) => {
    await loadStatusOnly(force)
  }, [loadStatusOnly])

  const loadStatusAndHistory = loadStatusOnly

  const handleHealthCheck = async () => {
    setRunningHealthCheck(true)
    try {
      const res = await runPostUpdateHealthCheck()
      if (res.data?.data?.healthy) {
        toast.success('✅ فحص سلامة النظام: جميع الملفات والجداول وقاعدة البيانات سليمة 100%')
      } else {
        toast.error('⚠️ تم رصد مشاكل في سلامة النظام: ' + (res.data?.data?.errors?.join(', ') || 'خطأ غير محدد'))
      }
      await loadAll()
      void loadRecoveryData()
    } catch {
      toast.error('فشل تنفيذ فحص سلامة النظام.')
    } finally {
      setRunningHealthCheck(false)
    }
  }

  const handleManualRecovery = async (action: string) => {
    if (action === 'rollback') {
      const snapshot = recoveryDiag?.details?.snapshot
      await handleRollback(typeof snapshot === 'string' ? snapshot : undefined)
      return
    }

    const ok = await confirm(`هل أنت متأكد من رغبتك في تنفيذ إجراء الاستعادة: (${action})؟`)
    if (!ok) return

    setExecutingRecovery(true)
    try {
      const res = await executeRecoveryAction(action)
      if (res.data?.data?.ok) {
        toast.success(res.data.data.message || 'تم تنفيذ إجراء الاستعادة بنجاح.')
      } else {
        toast.error(res.data?.data?.error || 'فشل إجراء الاستعادة.')
      }
      await loadAll()
    } catch {
      toast.error('تعذر إكمال طلب الاستعادة.')
    } finally {
      setExecutingRecovery(false)
    }
  }

  const handleChannelChange = async (newChan: 'stable' | 'beta') => {
    setSavingChannel(true)
    try {
      await setUpdateChannel(newChan)
      setChannelState(newChan)
      setCurrentChannel(newChan)
      toast.success(`تم حفظ متابعة ${newChan.toUpperCase()}. النسخة المثبتة لم تتغير.`)
    } catch {
      toast.error('فشل تغيير قناة التحديث')
    } finally {
      setSavingChannel(false)
    }
    void loadAll()
  }

  useEffect(() => {
    void loadAll(false)
  }, [loadAll])

  // Bootstrap updater API onStatus listener with cleanup disposal
  useEffect(() => {
    if (!updaterApi) return
    let cancelled = false

    const applyStatus = (status: { state: string; progress?: { transferred?: number; total?: number; percent?: number } | null; error?: string | null }) => {
      if (cancelled || !isBootstrapActiveRef.current) return
      if (status.state === 'downloading') {
        setJobState('downloading')
        if (status.progress) {
          setJobProgress({
            downloaded_bytes: status.progress.transferred ?? 0,
            total_bytes: status.progress.total ?? 0,
            download_attempt: 1,
          })
        }
      } else if (status.state === 'ready_to_install' || status.state === 'restarting') {
        setJobState('desktop_handoff_pending')
        setJobProgress(null)
      } else if (status.state === 'error') {
        setJobState('error')
        toast.error(status.error || 'فشل تحديث التطبيق.')
      }
    }

    if (updaterApi.getStatus) {
      void updaterApi.getStatus().then((initialStatus) => {
        if (initialStatus) applyStatus(initialStatus)
      }).catch(() => {})
    }

    const dispose = updaterApi.onStatus ? updaterApi.onStatus(applyStatus) : undefined
    return () => {
      cancelled = true
      if (dispose) dispose()
    }
  }, [updaterApi])

  // Single-flight awaited polling loop with bounded deadlines and safe status resume
  useEffect(() => {
    if (!applyingUpdate || transitionBusy || !activeJobId) return

    let cancelled = false
    let timerId: ReturnType<typeof setTimeout> | null = null
    const loopStartTime = Date.now()
    let consecutiveNetworkErrors = 0
    let consecutiveInvalidResponses = 0
    const MAX_PENDING_TIMEOUT_MS = 30_000 // 30s pending deadline
    const MAX_PROCESSING_TIMEOUT_MS = 15 * 60 * 1000 // 15m processing deadline
    const MAX_CONSECUTIVE_NETWORK_ERRORS = 10
    const MAX_CONSECUTIVE_INVALID_RESPONSES = 5
    const POLL_INTERVAL_MS = 2_000 // 2s polling interval (<= 30 req/min within rate limit)

    const poll = async () => {
      if (cancelled || isUpdateRestarting()) return

      // Enforce absolute loop deadline on EACH iteration regardless mixed network success/failure
      if (Date.now() - loopStartTime >= MAX_PROCESSING_TIMEOUT_MS) {
        setJobState('processing_timeout')
        toast.error('تجاوزت عملية التحديث الحد الأقصى للمهلة الزمنية (15 دقيقة). يمكنك استئناف متابعة الحالة يدوياً.')
        return
      }

      try {
        const res = await getUpdateJob(activeJobId, { timeout: 10_000, hideGlobalError: true })
        if (cancelled || isUpdateRestarting()) return
        consecutiveNetworkErrors = 0
        const job = res.data?.data

        // Validate id and status for ALL returned statuses, not just completed
        if (job && typeof job === 'object' && job.id && job.id !== activeJobId) {
          setJobState('needs_attention')
          toast.error('معرّف المهمة المستلم من الخادم لا يطابق معرّف المهمة النشطة. تم تعليق المتابعة مع الاحتفاظ بالمهمة.')
          return
        }

        if (!job || typeof job !== 'object' || !job.status) {
          consecutiveInvalidResponses++
          if (consecutiveInvalidResponses >= MAX_CONSECUTIVE_INVALID_RESPONSES) {
            setJobState('needs_attention')
            toast.error('استجابة غير صالحة من الخادم بعد عدة محاولات. تم تعليق المتابعة التلقائية مع الاحتفاظ بالمهمة.')
            return
          }
          if (!cancelled && !isUpdateRestarting()) {
            timerId = setTimeout(poll, POLL_INTERVAL_MS)
          }
          return
        }

        consecutiveInvalidResponses = 0

        if (job.status === 'pending') {
          if (Date.now() - loopStartTime >= MAX_PENDING_TIMEOUT_MS) {
            // Worker absent pending timeout: keep activeJobId retained, do NOT clear
            setJobState('pending_timeout')
            toast.error('لم يبدأ معالج التحديث في الخلفية بعد. تحقق من تشغيل CLI worker (process-jobs.php). تم تعليق المتابعة التلقائية مع الاحتفاظ بالمهمة.')
            return
          }
        } else if (job.status === 'processing') {
          if (job.progress) {
            setJobProgress(job.progress)
          }
          if (job.update_state) {
            setJobState(job.update_state)
          }
        } else if (job.status === 'needs_attention') {
          setJobState('needs_attention')
          // Keep activeJobId and applyingUpdate retained to prevent unlocking transitions/rollback
          const errorMsg = job.last_error || 'المهمة بحاجة إلى مراجعة من قبل المسؤول. يرجى فحص حالة النظام.'
          toast.error(errorMsg)
          return
        } else if (job.status === 'completed') {
          // Require matching job_id and verified result
          if (job.id !== activeJobId || !job.result || job.result.ok !== true) {
            setJobState('needs_attention')
            toast.error('اكتملت المهمة في قائمة الانتظار ولكن تعذر التحقق من نتيجة العملية المعتمدة. يرجى فحص حالة النظام.')
            return
          }

          const result = job.result
          if (result.logs && Array.isArray(result.logs)) {
            setUpdateLogs(result.logs)
          }

          if (result.desktop_handoff_completed === true && result.requires_desktop_handoff === false) {
            setHandoffMarker(activeJobId, 'done')
            handoffStatusRef.current = 'done'
          } else if (result.requires_desktop_handoff && result.handoff_version) {
            const currentMarker = getHandoffMarker(activeJobId)
            // Guard against skipped failed handoff or reattempting running handoff on status resume / remount
            if (currentMarker === 'running' || currentMarker === 'failed') {
              setJobState('needs_attention')
              toast.error('فشلت محاولة تسليم التحديث لخدمة سطح المكتب سابقاً أو أن حالتها غير مؤكدة. يرجى إعادة فحص النظام.')
              return
            }

            if (currentMarker !== 'done') {
              setHandoffMarker(activeJobId, 'running')
              handoffStatusRef.current = 'running'
              // Set installing/restarting state and jobProgress = null before awaiting desktop handoff IPC
              setJobState('desktop_handoff_pending')
              setJobProgress(null)

              const version = result.handoff_version
              const restartNotice = 'pos-update-restarting'
              toast.loading('جاري إعادة تشغيل الخدمات لإكمال التحديث...', { id: restartNotice })
              try {
                const handoff = await withUpdateRestart(async () => {
                  if (!window.posRuntime) throw new Error('خدمة تثبيت تحديث البرنامج غير متاحة.')
                  return window.posRuntime.applyStagedDelta(version)
                })
                if (!handoff.ok) {
                  throw new Error(handoff.error || 'فشل الاستبدال الآمن لملفات التحديث.')
                }
                setHandoffMarker(activeJobId, 'done')
                handoffStatusRef.current = 'done'
              } catch (handoffErr) {
                setHandoffMarker(activeJobId, 'failed')
                handoffStatusRef.current = 'failed'
                setJobState('needs_attention')
                toast.error(handoffErr instanceof Error ? handoffErr.message : 'فشل تسليم التحديث لخدمة سطح المكتب.')
                return
              } finally {
                toast.dismiss(restartNotice)
              }
            }
          }

          // Definitive safe settlement: clear active persisted job safely before reload
          setAdmissionUnknown(false)
          setActiveJobId(null)
          setApplyingUpdate(false)
          if (cancelled) return
          toast.success('🎉 تم تطبيق التحديث بنجاح! جاري تنشيط النظام...')
          await loadAll(true)
          setTimeout(() => window.location.reload(), 1500)
          return
        } else if (job.status === 'failed') {
          // Definitive failure
          setAdmissionUnknown(false)
          setActiveJobId(null)
          setApplyingUpdate(false)
          const errorMsg = job.last_error || 'فشل تطبيق التحديث في الخلفية.'
          setJobState('error')
          toast.error(errorMsg)
          if (job.result?.logs && Array.isArray(job.result.logs)) {
            setUpdateLogs(job.result.logs)
          }
          await loadAll(true)
          return
        }
      } catch {
        consecutiveNetworkErrors++
        if (consecutiveNetworkErrors >= MAX_CONSECUTIVE_NETWORK_ERRORS) {
          setJobState('network_timeout')
          toast.error('انقطع الاتصال بخادم التحديثات أثناء المتابعة. المهمة ما زالت محفوظة في النظام ويمكنك استئناف متابعتها.')
          return
        }
      }

      if (!cancelled && !isUpdateRestarting()) {
        timerId = setTimeout(poll, POLL_INTERVAL_MS)
      }
    }

    void poll()

    return () => {
      cancelled = true
      if (timerId) clearTimeout(timerId)
    }
  }, [applyingUpdate, transitionBusy, activeJobId, pollNonce, loadAll, setActiveJobId])

  const handleCheckUpdate = async () => {
    try {
      const data = await forceCheck()
      await loadAll(true)

      if (data?.updates_disabled) {
        toast.error(data.message || 'خادم التحديثات غير مهيأ.')
        return
      }
      if (data?.updates_unreachable) {
        toast.error(formatUpdateError(data))
        return
      }
      if (data?.has_update) {
        setBootstrapRequired(Boolean(data.bootstrap_required))
        toast.success(data.bootstrap_required ? 'تحديث التطبيق مطلوب لمرة واحدة.' : '🎉 تم العثور على تحديث جديد متوافق!')
        setShowChangelog(true)
      } else {
        toast.success('✅ النظام محدّث لأحدث إصدار.')
      }
    } catch (error: unknown) {
      const responseData = isAxiosError(error) ? error.response?.data : undefined
      toast.error(formatUpdateError(asRecord(responseData)?.data ?? responseData ?? error))
    }
  }

  const handleApplyUpdate = async (force = false) => {
    const activeCartItems = useCartStore.getState().items
    if (activeCartItems.length > 0) {
      toast.error('لا يمكن تطبيق التحديث أثناء وجود عملية بيع جارية أو عناصر في السلة. يرجى إتمام عملية البيع أو إفراغ السلة أولاً.')
      return
    }

    const deltaCapable = await hasDeltaHandoffCapability()
    if (!deltaCapable && updaterApi) {
      setApplyingUpdate(true)
      if (typeof isBootstrapActiveRef !== 'undefined') isBootstrapActiveRef.current = true
      setUpdateLogs(['🚀 جاري تحميل تحديث التطبيق المطلوب...'])
      try {
        const downloadStatus = await updaterApi.download()
        if (downloadStatus?.state === 'error') throw new Error(downloadStatus.error || 'فشل تحميل تحديث التطبيق.')
        if (downloadStatus?.canInstall) await updaterApi.install()
        return
      } catch (error) {
        toast.error(error instanceof Error ? error.message : 'فشل تحديث التطبيق.')
        setApplyingUpdate(false)
        if (typeof isBootstrapActiveRef !== 'undefined') isBootstrapActiveRef.current = false
        return
      }
    }

    const isDelta = updateStatus?.type === 'delta' || hasUpdate
    const updateTypeLabel = bootstrapRequired ? 'تحديث التطبيق المطلوب' : (isDelta ? 'تحديث جزئي سريع (Delta Patch)' : 'تحديث كامل (Full Package)')

    if (!force) {
      const confirmed = await confirm(
        `تأكيد تثبيت التحديث (${updateTypeLabel}):\n\n• سيتم أخذ نسخة احتياطية ذرية لقاعدة البيانات والملفات.\n• سيتم التحقق من التوقيع الرقمي RSA وسلامة الملفات.\n• في حال حدوث أي خطأ، سيتم التراجع التلقائي الفوري.\n\nهل ترغب في بدء التحديث الآن؟`
      )
      if (!confirmed) return
    }

    setApplyingUpdate(true)
    if (typeof handoffDoneRef !== 'undefined') handoffDoneRef.current = false
    if (typeof handoffStatusRef !== 'undefined') handoffStatusRef.current = 'idle'
    if (typeof setJobProgress !== 'undefined') setJobProgress(null)
    if (typeof setJobState !== 'undefined') setJobState(null)
    setUpdateLogs(['🚀 بدء عملية التحديث...', '⏳ جاري الاتصال بخادم التحديثات وتجهيز الملفات...'])

    try {
      const res = await applyUpdate(force, deltaCapable, true)
      const data = res.data?.data
      const admittedJobId = (data && typeof data === 'object' && 'job_id' in data) ? Number(data.job_id) : null

      if (admittedJobId && Number.isInteger(admittedJobId) && admittedJobId > 0) {
        if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(false)
        if (typeof setActiveJobId !== 'undefined') setActiveJobId(admittedJobId)
        if (typeof setPollNonce !== 'undefined') setPollNonce((n) => n + 1)
        setUpdateLogs((prev) => [...prev, `📋 تم إرسال مهمة التحديث إلى طابور المعالجة (Job #${admittedJobId})`])
        return
      }

      // Synchronous non-background path (if server returned verified completed result)
      if (data && typeof data === 'object' && data.ok === true && (data.verified === true || data.status === 'completed')) {
        if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(false)
        if (data.logs && Array.isArray(data.logs)) {
          setUpdateLogs(data.logs)
        }

        if (data.requires_desktop_handoff && data.handoff_version) {
          const version = data.handoff_version
          const restartNotice = 'pos-update-restarting'
          toast.loading('جاري إعادة تشغيل الخدمات لإكمال التحديث...', { id: restartNotice })
          try {
            const handoff = await withUpdateRestart(async () => {
              if (!window.posRuntime) throw new Error('خدمة تثبيت تحديث البرنامج غير متاحة.')
              return window.posRuntime.applyStagedDelta(version)
            })
            if (!handoff.ok) throw new Error(handoff.error || 'فشل الاستبدال الآمن لملفات التحديث.')
          } finally {
            toast.dismiss(restartNotice)
          }
        }

        toast.success('🎉 تم تطبيق التحديث بنجاح! جاري تنشيط النظام...')
        await loadAll(true)
        setTimeout(() => window.location.reload(), 1500)
        return
      }

      // Malformed 200 response missing positive job_id or verified result!
      // Fail closed: do NOT toast success, do NOT reload!
      if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(true)
      if (typeof setJobState !== 'undefined') setJobState('needs_attention')
      toast.error('استجابة غير صالحة من خادم التحديثات بعد إرسال الطلب (لم يتم استلام معرّف مهمة صالح). تم قفل التحديثات لمنع التكرار.')
      return
    } catch (error: unknown) {
      if (typeof isBootstrapActiveRef !== 'undefined') isBootstrapActiveRef.current = false
      const errData = isAxiosError(error)
        ? (error.response?.data as { message?: string; error?: string; errors?: { logs?: string[]; reason_code?: string }; data?: { logs?: string[]; local_changes?: unknown }; reason_code?: string })
        : undefined
      const msg = errData?.message || (error instanceof Error ? error.message : 'فشل تطبيق التحديث.')
      const logs = errData?.errors?.logs || errData?.data?.logs || [error instanceof Error ? error.message : 'Update failed']
      setUpdateLogs(logs)
      void loadStatusAndHistory()

      // Definitive HTTP 400 / 403 validation rejection can safely unlock
      if (isAxiosError(error) && (error.response?.status === 400 || error.response?.status === 403)) {
        if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(false)
        setApplyingUpdate(false)
        if (typeof setActiveJobId !== 'undefined') setActiveJobId(null)
        toast.error(msg)
        return
      }

      // HTTP 409 conflict
      if (isAxiosError(error) && error.response?.status === 409) {
        const reasonCode = errData?.errors?.reason_code || errData?.reason_code
        const isLocalModifications = reasonCode === 'local_modifications' || Boolean(errData?.data?.local_changes) || String(errData?.error || '').includes('تعديلات محلية')
        if (isLocalModifications && !force) {
          if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(false)
          setApplyingUpdate(false)
          if (typeof setActiveJobId !== 'undefined') setActiveJobId(null)
          const forceConfirm = await confirm(
            'توجد تعديلات محلية في بعض ملفات النظام. سيتم استبدالها بالنسخة الأصلية المعتمدة من التحديث. هل تريد المتابعة؟'
          )
          if (forceConfirm) {
            handleApplyUpdate(true)
          }
          return
        }
        // Background queue busy: must NOT offer replace local modifications prompt!
        if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(false)
        setApplyingUpdate(false)
        if (typeof setActiveJobId !== 'undefined') setActiveJobId(null)
        toast.error(msg || 'عملية تحديث أخرى قيد التنفيذ حالياً.')
        return
      }

      // Unknown submission (network timeout, lost connection, 5xx):
      // Server may have dispatched job before response lost.
      // Retain busy, persist admission_unknown sentinel, do NOT clear or call POST as recovery!
      if (typeof setAdmissionUnknown !== 'undefined') setAdmissionUnknown(true)
      setApplyingUpdate(true)
      if (typeof setJobState !== 'undefined') setJobState('needs_attention')
      toast.error(msg || 'انقطع الاتصال بخادم التحديثات أثناء معالجة الطلب. قد تكون المهمة بدأت في الخادم. تم تعليق العمليات لمنع التكرار حتى يتم التحقق.')
    }
  }

  const handleRollback = async (snapshotPath?: string) => {
    const selected = snapshotsList.find(snapshot => snapshot.snapshot_path === snapshotPath)
    const targetLabel = selected ? `إلى الإصدار v${selected.from_version}` : 'إلى آخر نقطة رجوع صالحة'
    const confirmed = await confirm(
      `⚠️ تحذير: التراجع عن التحديث ${targetLabel}:\n\nسيتم استرجاع ملفات البرنامج فقط مع الحفاظ على بياناتك الحالية. ستعود أيضًا واجهة الإصدار المختار وسلوكه، وقد تختفي الإصلاحات الأحدث. هل أنت متأكد؟`
    )
    if (!confirmed) return

    setRollingBack(true)
    setUpdateLogs(['🔄 جاري استرجاع الملفات من اللقطة الاحتياطية...'])
    try {
      const capable = await hasDeltaHandoffCapability()
      const res = await rollbackUpdate(snapshotPath, capable)
      const result = res.data?.data
      if (result?.requires_desktop_handoff && result.handoff_version) {
        const version = result.handoff_version
        const notice = 'pos-update-restarting'
        toast.loading('جاري إعادة تشغيل الخدمات لإكمال الاسترجاع...', { id: notice })
        try {
          const applied = await withUpdateRestart(async () => {
            if (!window.posRuntime) throw new Error('خدمة الاسترجاع الآمن غير متاحة.')
            return window.posRuntime.applyStagedDelta(version)
          })
          if (!applied.ok) throw new Error(applied.error || 'فشل استرجاع الملفات بأمان.')
        } finally { toast.dismiss(notice) }
      }
      const logs = res.data?.data?.logs || []
      setUpdateLogs(logs)
      toast.success('✅ تم التراجع عن التحديث بنجاح!')
      await loadAll()
      setTimeout(() => window.location.reload(), 1200)
    } catch (error: unknown) {
      const msg = isAxiosError(error) ? error.response?.data?.message : (error instanceof Error ? error.message : 'فشل التراجع.')
      if (isAxiosError(error) && Array.isArray(error.response?.data?.data?.logs)) setUpdateLogs(error.response.data.data.logs)
      toast.error(msg || (error instanceof Error ? error.message : 'فشل التراجع عن التحديث.'))
    } finally {
      setRollingBack(false)
    }
  }

  const activeState = transitionBusy
    ? (transitionPhase === 'installing' ? 'applying' : (transitionPhase === 'verifying' ? 'verifying' : 'preparing'))
    : (jobState || updateStatus?.update_state?.state || 'idle')
  const downloadedBytes = jobProgress?.downloaded_bytes ?? updateStatus?.update_state?.download_bytes ?? 0
  const totalBytes = jobProgress?.total_bytes ?? updateStatus?.update_state?.total_bytes ?? 0
  const hasDownloadByteCounts = activeState === 'downloading' && totalBytes > 0
  const downloadPercent = hasDownloadByteCounts
    ? Math.min(100, Math.max(0, Math.round((downloadedBytes / totalBytes) * 100)))
    : null

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.75rem' }}>
        <div>
          <SectionTitle icon={<RefreshCw size={18} className={isChecking ? 'spin' : ''} />} label="مركز إدارة التحديثات (Admin Update Center)" />
          <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: '0.25rem 0 0 0' }}>
            نظام التحديثات الجزئية المباشر عبر GitHub Releases مع التحقق من التوقيع الرقمي RSA والنسخ الاحتياطي التلقائي.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Channel Selector */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', background: 'var(--card-bg, #2a2a3e)', border: '1px solid var(--border)', borderRadius: '8px', padding: '0.2rem 0.5rem' }}>
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>قناة المتابعة:</span>
            {currentChannel === null ? (
              <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)', padding: '0.1rem 0.4rem' }}>جاري التحميل...</span>
            ) : (
              <select
                value={currentChannel}
                onChange={(e) => handleChannelChange(e.target.value as 'stable' | 'beta')}
                disabled={savingChannel || isChecking || applyingUpdate}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: currentChannel === 'beta' ? '#f59e0b' : '#4ade80',
                  fontWeight: 700,
                  fontSize: '0.8rem',
                  cursor: 'pointer',
                  outline: 'none',
                }}
              >
                <option value="stable" style={{ background: '#1e1e2e', color: '#fff' }}>Stable (مستقر)</option>
                <option value="beta" style={{ background: '#1e1e2e', color: '#fff' }}>Beta (تجريبي)</option>
              </select>
            )}
          </div>
          {updateStatus?.channel_migrated_from && (
            <p role="status" style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>تم إيقاف القناة السابقة واختيار متابعة stable. الإصدار المثبّت لم يتغير.</p>
          )}

          <span style={{ 
            fontSize: '0.8rem', 
            padding: '0.25rem 0.6rem', 
            borderRadius: '999px', 
            background: 'var(--card-bg, #2a2a3e)', 
            border: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            gap: '0.35rem'
          }}>
            <ShieldCheck size={14} style={{ color: '#4ade80' }} />
            <span>RSA-2048 Verified</span>
          </span>
        </div>
      </div>


      {currentChannel !== null && updateStatus?.installed_channel && (
        <ChannelTransitionPanel
          channel={currentChannel}
          installedVersion={updateStatus?.current_version || currentVersion || '—'}
          installedChannel={updateStatus?.installed_channel}
          disabled={savingChannel || normalApplyingUpdate || rollingBack}
          onBusy={handleTransitionBusy}
        />
      )}

      {/* Interrupted Update Recovery Banner */}
      {updateStatus?.interrupted_update?.interrupted && (
        <div style={{
          padding: '1.2rem',
          borderRadius: '8px',
          background: 'rgba(239, 68, 68, 0.12)',
          border: '1px solid rgba(239, 68, 68, 0.4)',
          display: 'flex',
          flexDirection: 'column',
          gap: '0.75rem'
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: '#f87171', fontWeight: 700, fontSize: '0.95rem' }}>
            <AlertTriangle size={20} />
            <span>⚠️ تنبيه: تم مقاطعة عملية تحديث سابقة بشكل مفاجئ (انقطاع كهرباء أو إغلاق التطبيق)</span>
          </div>

          <p style={{ margin: 0, fontSize: '0.85rem', color: '#cbd5e1', lineHeight: 1.5 }}>
            {updateStatus.interrupted_update.message || 'تم مقاطعة عملية التحديث قبل اكتمالها.'}
            {updateStatus.interrupted_update.snapshot_path && ' تتوفر لقطة نسخ احتياطي سابقة يمكن الرجوع إليها بأمان.'}
          </p>

          <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap', marginTop: '0.25rem' }}>
            {updateStatus.interrupted_update.snapshot_path && (
              <button
                type="button"
                onClick={() => handleRollback(updateStatus.interrupted_update?.snapshot_path || undefined)}
                disabled={rollingBack || applyingUpdate}
                className="btn btn-secondary btn-sm"
                style={{ background: '#ef4444', color: '#fff', border: 'none' }}
              >
                <RotateCcw size={14} /> استرجاع النسخة السابقة فوراً (Rollback)
              </button>
            )}

            <button
              type="button"
              onClick={() => handleApplyUpdate(true)}
              disabled={rollingBack || applyingUpdate}
              className="btn btn-secondary btn-sm"
            >
              <RefreshCw size={14} /> إعادة محاولة التحديث (Resume / Retry)
            </button>
          </div>
        </div>
      )}

      {/* Admission Unknown Banner */}
      {isAdmissionUnknown() && (
        <div style={{
          padding: '1rem',
          borderRadius: '8px',
          background: 'rgba(239, 68, 68, 0.1)',
          border: '1px solid rgba(239, 68, 68, 0.3)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: '1rem',
          flexWrap: 'wrap'
        }}>
          <div>
            <div style={{ fontWeight: 600, color: '#ef4444', marginBottom: '0.2rem' }}>
              حالة إرسال التحديث غير مؤكدة
            </div>
            <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
              انقطع الاتصال أو وردت استجابة غير صالحة أثناء إرسال طلب التحديث. قد تكون المهمة قيد المعالجة في الخادم. تم قفل التحديثات لمنع الإرسال المكرر.
            </div>
          </div>
          <button
            type="button"
            onClick={() => {
              setAdmissionUnknown(false)
              setApplyingUpdate(false)
              setJobState(null)
            }}
            className="btn btn-secondary btn-sm"
          >
            إلغاء القفل بعد التحقق
          </button>
        </div>
      )}

      {/* Pending / Attention Resume Banner */}
      {(jobState === 'pending_timeout' || jobState === 'processing_timeout' || jobState === 'needs_attention' || jobState === 'network_timeout') && activeJobId && (
        <div style={{
          padding: '1rem',
          borderRadius: '8px',
          background: 'rgba(234, 179, 8, 0.1)',
          border: '1px solid rgba(234, 179, 8, 0.3)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: '1rem',
          flexWrap: 'wrap'
        }}>
          <div>
            <div style={{ fontWeight: 600, color: '#eab308', marginBottom: '0.2rem' }}>
              {jobState === 'needs_attention' ? 'المهمة بحاجة لمراجعة الحالة' : 'المهمة قيد الانتظار أو المعالجة في الخلفية'}
            </div>
            <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
              رقم المهمة: #{activeJobId}. {jobState === 'needs_attention' ? 'تعذر التحقق التلقائي من نتيجة المهمة.' : 'تأخرت استجابة معالج الخلفية؛ المهمة محفوظة دون فقدان الحالة.'}
            </div>
          </div>
          <button
            type="button"
            onClick={() => {
              setApplyingUpdate(true)
              setJobState('processing')
              setPollNonce((n) => n + 1)
            }}
            className="btn btn-warning btn-sm"
          >
            <RefreshCw size={14} /> استئناف متابعة الحالة
          </button>
        </div>
      )}

      {/* Main Status Banner */}
      {updatesDisabled ? (

        <div style={{ padding: '1rem', borderRadius: '8px', background: 'rgba(239, 68, 68, 0.1)', border: '1px solid rgba(239, 68, 68, 0.3)' }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '0.95rem', color: 'var(--danger)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <AlertTriangle size={18} /> {updatesDisabledMessage || 'خادم التحديثات غير مهيأ.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : updatesUnreachable ? (
        <div style={{ padding: '1rem', borderRadius: '8px', background: 'rgba(245, 158, 11, 0.1)', border: '1px solid rgba(245, 158, 11, 0.3)' }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '0.95rem', color: 'var(--warning)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <AlertTriangle size={18} /> {updateErrorMessage || 'تعذر الاتصال بخادم التحديثات.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : (
        <div style={{
          padding: '1.2rem',
          borderRadius: '10px',
          background: hasUpdate ? 'linear-gradient(135deg, rgba(34, 197, 94, 0.12), rgba(16, 185, 129, 0.05))' : 'var(--bg)',
          border: hasUpdate ? '1px solid rgba(34, 197, 94, 0.35)' : '1px solid var(--border)',
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
          gap: '1rem',
          alignItems: 'center'
        }}>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>الإصدار الحالي المثبت</div>
            <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--text)' }}>
              {currentVersion ? `v${currentVersion}` : 'غير معروف'}
            </div>
            <div style={{ fontSize: '0.72rem', color: '#10b981', fontWeight: 600, marginTop: '0.2rem' }}>
              نظام التحديث المدمج
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>أحدث إصدار متاح</div>
            <div style={{ fontSize: '1.25rem', fontWeight: 700, color: hasUpdate ? '#22c55e' : 'var(--text)' }}>
              {latestVersion ? `v${latestVersion}` : (currentVersion ? `v${currentVersion}` : 'غير معروف')}
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>نوع التحديث</div>
            <div>
              <span style={{
                fontSize: '0.8rem',
                fontWeight: 600,
                padding: '0.2rem 0.5rem',
                borderRadius: '6px',
                background: updateStatus?.type === 'delta' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(156, 163, 175, 0.15)',
                color: updateStatus?.type === 'delta' ? '#60a5fa' : 'var(--text-muted)',
                border: '1px solid rgba(255, 255, 255, 0.1)'
              }}>
                {updateStatus?.type === 'delta' ? '📦 Delta (جزئي)' : '💿 Full (كامل)'}
              </span>
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>حالة النظام</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.9rem', fontWeight: 600, color: hasUpdate ? '#22c55e' : '#38bdf8' }}>
              {hasUpdate ? <CheckCircle2 size={16} /> : <CheckCircle2 size={16} />}
              {hasUpdate ? 'تحديث متاح للتثبيت' : 'النظام محدّث لأحدث إصدار'}
            </div>
          </div>
        </div>
      )}

      {/* Release Information Card (When update available) */}
      {hasUpdate && updateStatus?.release_info && (
        <div style={{ padding: '1rem', background: 'rgba(59, 130, 246, 0.05)', border: '1px solid rgba(59, 130, 246, 0.2)', borderRadius: '8px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <h4 style={{ margin: 0, fontSize: '0.95rem', color: '#60a5fa', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <FileCode2 size={16} /> معلومات الإصدار {updateStatus.release_info.tag_name}
            </h4>
            {updateStatus.release_info.release_url && (
              <a 
                href={updateStatus.release_info.release_url} 
                target="_blank" 
                rel="noreferrer" 
                style={{ fontSize: '0.8rem', color: '#93c5fd', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: '0.25rem' }}
              >
                GitHub Release <ExternalLink size={12} />
              </a>
            )}
          </div>

          <div style={{ display: 'flex', gap: '1.5rem', fontSize: '0.8rem', color: 'var(--text-muted)', flexWrap: 'wrap' }}>
            {updateStatus.release_info.released_at && (
              <span><Clock size={12} style={{ display: 'inline', verticalAlign: 'middle' }} /> تاريخ النشر: {new Date(updateStatus.release_info.released_at).toLocaleDateString('ar-EG')}</span>
            )}
            {updateStatus.release_info.files_count != null && (
              <span><FileCode2 size={12} style={{ display: 'inline', verticalAlign: 'middle' }} /> عدد الملفات المعدلة: {updateStatus.release_info.files_count} ملف(ات)</span>
            )}
          </div>
        </div>
      )}

      {/* Progress State Tracker Bar */}
      {applyingUpdate && (
        <div style={{ padding: '1rem', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: '8px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#38bdf8' }}>
              مرحلة التحديث الحالية: {getStageLabel(activeState)}
              {hasDownloadByteCounts && downloadPercent !== null && (
                <span style={{ marginRight: '0.5rem', color: '#94a3b8', fontSize: '0.8rem' }}>
                  ({formatBytes(downloadedBytes)} / {formatBytes(totalBytes)} - {downloadPercent}%)
                </span>
              )}
            </span>
            <span className="spinner" style={{ width: '14px', height: '14px' }} />
          </div>
          <div style={{ height: '6px', width: '100%', background: 'rgba(255,255,255,0.1)', borderRadius: '999px', overflow: 'hidden' }}>
            <div style={{
              height: '100%',
              width: activeState === 'completed' ? '100%' : (downloadPercent !== null ? `${downloadPercent}%` : '100%'),
              background: 'linear-gradient(90deg, #3b82f6, #10b981)',
              transition: downloadPercent !== null ? 'width 0.4s ease' : undefined,
              animation: downloadPercent === null && activeState !== 'completed' ? 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite' : undefined,
            }} />
          </div>
        </div>
      )}

      {/* Terminal Live Logs */}
      {(applyingUpdate || rollingBack || updateLogs.length > 0) && (
        <div style={{ background: '#12131e', borderRadius: '8px', border: '1px solid #232538', padding: '0.85rem 1rem' }}>
          <div style={{ fontSize: '0.75rem', color: '#94a3b8', marginBottom: '0.5rem', fontWeight: 600 }}>سجل العمليات المباشر (Update Live Log):</div>
          <div style={{
            fontFamily: 'Consolas, monospace',
            fontSize: '0.8rem',
            maxHeight: '220px',
            overflowY: 'auto',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.25rem',
            direction: 'ltr',
            textAlign: 'left'
          }}>
            {updateLogs.map((log, index) => (
              <div key={index} style={{
                color: log.startsWith('✅') || log.startsWith('🎉') ? '#4ade80' : log.startsWith('⚠️') ? '#facc15' : log.startsWith('❌') ? '#f87171' : '#e2e8f0',
                lineHeight: 1.4
              }}>
                {log}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Self-Healing & System Recovery Card */}
      <div style={{
        padding: '1rem 1.25rem',
        borderRadius: '8px',
        background: recoveryDiag ? (recoveryDiag.problem_detected ? 'rgba(239, 68, 68, 0.08)' : 'rgba(34, 197, 94, 0.05)') : 'rgba(255, 255, 255, 0.02)',
        border: `1px solid ${recoveryDiag ? (recoveryDiag.problem_detected ? 'rgba(239, 68, 68, 0.3)' : 'rgba(34, 197, 94, 0.2)') : 'var(--border)'}`,
        display: 'flex',
        flexDirection: 'column',
        gap: '0.75rem',
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.5rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <HeartPulse size={18} style={{ color: recoveryDiag ? (recoveryDiag.problem_detected ? '#f87171' : '#4ade80') : 'var(--text-muted)' }} />
            <h4 style={{ margin: 0, fontSize: '0.95rem', color: recoveryDiag ? (recoveryDiag.problem_detected ? '#f87171' : '#4ade80') : 'var(--text)' }}>
              نظام المعالجة الذاتية (Self-Healing & Fault Recovery)
            </h4>
          </div>
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            <button
              type="button"
              onClick={handleHealthCheck}
              disabled={runningHealthCheck}
              className="btn btn-secondary btn-sm"
            >
              <Activity size={14} className={runningHealthCheck ? 'spin' : ''} /> فحص السلامة (Health Check)
            </button>
            <button
              type="button"
              onClick={() => {
                setShowRecoveryModal(true)
                void loadRecoveryData()
              }}
              className="btn btn-secondary btn-sm"
            >
              <History size={14} /> سجل الاستعادة ({recoveryLogs.length})
            </button>
          </div>
        </div>

        <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
          {recoveryDiag?.message || 'لم يتم تشغيل فحص التشخيص بعد. انقر على فحص السلامة للتشخيص.'}
        </div>

        {recoveryDiag?.problem_detected && (
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.25rem' }}>
            {recoveryDiag.recommended_action === 'rollback' && (
              <button
                type="button"
                onClick={() => handleManualRecovery('rollback')}
                disabled={executingRecovery}
                className="btn btn-danger btn-sm"
              >
                <RotateCcw size={14} /> تنفيذ تراجع فوري (Rollback)
              </button>
            )}
            {recoveryDiag.recommended_action === 'retry_download' && (
              <button
                type="button"
                onClick={() => handleManualRecovery('retry_download')}
                disabled={executingRecovery}
                className="btn btn-primary btn-sm"
              >
                <Download size={14} /> إعادة المحاولة وتنزيل الحزمة
              </button>
            )}
            {recoveryDiag.recommended_action === 'clear' && (
              <button
                type="button"
                onClick={() => handleManualRecovery('clear')}
                disabled={executingRecovery}
                className="btn btn-secondary btn-sm"
              >
                <Wrench size={14} /> إعادة تعيين حالة التحديث
              </button>
            )}
          </div>
        )}
      </div>

      {/* Action Buttons */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.6rem', alignItems: 'center' }}>

        <button
          type="button"
          onClick={handleCheckUpdate}
          disabled={isChecking || applyingUpdate || rollingBack}
          className="btn btn-secondary"
        >
          {isChecking ? <span className="spinner" /> : <RefreshCw size={16} />}
          {isChecking ? 'جاري الفحص...' : 'التحقق من التحديثات'}
        </button>

        {hasUpdate && (
          <button
            type="button"
            onClick={() => handleApplyUpdate(false)}
            disabled={applyingUpdate || rollingBack}
            className="btn btn-primary"
            style={{ background: '#16a34a', borderColor: '#16a34a', color: '#fff' }}
          >
            {applyingUpdate ? <span className="spinner" /> : <Download size={16} />}
            {applyingUpdate ? 'جاري تثبيت التحديث...' : 'تثبيت التحديث الآن'}
          </button>
        )}

        {activeChangelog.length > 0 && (
          <button
            type="button"
            onClick={() => setShowChangelog(!showChangelog)}
            className="btn btn-ghost"
          >
            <List size={16} />
            {showChangelog ? 'إخفاء الملاحظات' : 'ملاحظات الإصدار'}
          </button>
        )}

        <button
          type="button"
          onClick={() => {
            const next = !showHistory
            setShowHistory(next)
            if (next) void loadHistory()
          }}
          className="btn btn-ghost"
        >
          <History size={16} />
          {showHistory ? 'إخفاء السجل' : 'سجل التحديثات'}
        </button>

        <button
          type="button"
          onClick={() => {
            const next = !showSnapshots
            setShowSnapshots(next)
            if (next) void loadSnapshots()
          }}
          className="btn btn-ghost"
          style={{ color: '#f59e0b' }}
        >
          <RotateCcw size={16} />
          {showSnapshots ? 'إخفاء نقاط الاسترجاع' : `نقاط الاسترجاع (${snapshotsList.length})`}
        </button>
      </div>

      {/* Changelog Dropdown */}
      {showChangelog && activeChangelog.length > 0 && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border)' }}>
          <h4 style={{ margin: '0 0 0.6rem 0', fontSize: '0.88rem' }}>سجل التغييرات والمميزات:</h4>
          <ul style={{ margin: 0, padding: '0 1.2rem', fontSize: '0.85rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            {activeChangelog.map((entry, idx) => renderChangelogEntry(entry, idx))}
          </ul>
        </div>
      )}


      {/* Available destinations are validated by the same planner used for execution. */}
      {showSnapshots && (
        <RollbackPoints currentVersion={updateStatus?.current_version || currentVersion} snapshots={snapshotsList} busy={applyingUpdate || rollingBack} onRollback={handleRollback} />
      )}

      {/* History Table */}
      {showHistory && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border)', overflowX: 'auto' }}>
          <h4 style={{ margin: '0 0 0.75rem 0', fontSize: '0.9rem' }}>سجل عمليات التحديث السابقة:</h4>
          {historyList.length === 0 ? (
            <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>لا توجد عمليات تحديث مسجلة حتى الآن.</div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.8rem', textAlign: 'right' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '0.4rem' }}>التاريخ</th>
                  <th style={{ padding: '0.4rem' }}>من إصدار</th>
                  <th style={{ padding: '0.4rem' }}>إلى إصدار</th>
                  <th style={{ padding: '0.4rem' }}>النوع</th>
                  <th style={{ padding: '0.4rem' }}>المصدر</th>
                  <th style={{ padding: '0.4rem' }}>الملفات</th>
                  <th style={{ padding: '0.4rem' }}>الحالة</th>
                </tr>
              </thead>
              <tbody>
                {historyList.map((item) => (
                  <tr key={item.id} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.created_at}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>v{item.from_version}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>v{item.to_version}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>
                      <span style={{ 
                        padding: '0.15rem 0.4rem', 
                        borderRadius: '4px', 
                        fontSize: '0.75rem',
                        background: item.type === 'delta' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(156, 163, 175, 0.15)',
                        color: item.type === 'delta' ? '#60a5fa' : 'var(--text-muted)'
                      }}>
                        {item.type}
                      </span>
                    </td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.source || 'github_release'}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.files_count}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>
                      <span style={{
                        padding: '0.15rem 0.4rem',
                        borderRadius: '4px',
                        fontSize: '0.75rem',
                        background: item.status === 'success' ? 'rgba(34, 197, 94, 0.15)' : item.status === 'rolled_back' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(239, 68, 68, 0.15)',
                        color: item.status === 'success' ? '#22c55e' : item.status === 'rolled_back' ? '#f59e0b' : '#ef4444'
                      }}>
                        {item.status === 'success' ? 'ناجح ✅' : item.status === 'rolled_back' ? 'تم التراجع 🔄' : 'فشل ❌'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* Recovery Audit Trail Modal */}

      {showRecoveryModal && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0,0,0,0.75)',
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'center',
          zIndex: 1000,
          padding: '1rem',
        }}>
          <div className="card" style={{
            width: '100%',
            maxWidth: '650px',
            maxHeight: '80vh',
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
            padding: '1.5rem',
            gap: '1rem'
          }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3 style={{ margin: 0, fontSize: '1.1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <HeartPulse size={18} style={{ color: '#4ade80' }} /> سجل عمليات المعالجة الذاتية (Self-Healing Audit)
              </h3>
              <button
                type="button"
                onClick={() => setShowRecoveryModal(false)}
                className="btn btn-secondary btn-sm"
              >
                إغلاق
              </button>
            </div>

            <div style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
              {recoveryLogs.length === 0 ? (
                <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2rem 0' }}>
                  لا توجد عمليات استعادة مسجلة؛ النظام مستقر وتعمل التحديثات بانسيابية.
                </div>
              ) : (
                recoveryLogs.map((log) => (
                  <div
                    key={log.id}
                    style={{
                      padding: '0.75rem',
                      borderRadius: '6px',
                      background: 'rgba(255,255,255,0.03)',
                      border: '1px solid rgba(255,255,255,0.05)',
                      display: 'flex',
                      flexDirection: 'column',
                      gap: '0.35rem',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span style={{
                        padding: '0.15rem 0.45rem',
                        borderRadius: '4px',
                        fontSize: '0.75rem',
                        fontWeight: 700,
                        background: log.success ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)',
                        color: log.success ? '#4ade80' : '#f87171'
                      }}>
                        الإجراء: {log.selected_action.toUpperCase()}
                      </span>
                      <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                        {new Date(log.timestamp).toLocaleString('ar-EG')}
                      </span>
                    </div>

                    <div style={{ fontSize: '0.8rem', color: '#cbd5e1' }}>
                      <strong>المشكلة المرصودة:</strong> {log.detected_problem} (الحالة السابقة: {log.previous_state})
                    </div>

                    {log.details && (
                      <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', background: 'rgba(0,0,0,0.2)', padding: '0.4rem', borderRadius: '4px' }}>
                        {JSON.stringify(log.details)}
                      </div>
                    )}
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  )
}


function renderChangelogEntry(entry: unknown, index: number) {
  if (typeof entry === 'string') {
    return (
      <li key={index} style={{ lineHeight: 1.6 }}>
        {entry}
      </li>
    )
  }

  if (entry && typeof entry === 'object') {
    const obj = entry as { version?: string; date?: string; changes?: unknown };
    const versionLabel = obj.version ? `v${obj.version}` : ''
    const dateLabel = obj.date ? ` (${obj.date})` : ''
    const title = `${versionLabel}${dateLabel}`.trim()
    const changes = Array.isArray(obj.changes)
      ? obj.changes.filter((c): c is string => typeof c === 'string')
      : []

    return (
      <li key={index} style={{ lineHeight: 1.6 }}>
        {title && <strong style={{ color: 'var(--text)' }}>{title}</strong>}
        {changes.length > 0 && (
          <span>{title ? ' — ' : ''}{changes.join(' • ')}</span>
        )}
      </li>
    )
  }

  return null
}

function getStageLabel(state: string): string {
  switch (state) {
    case 'downloading': return 'تحميل حزمة التحديث من GitHub Releases...'
    case 'verifying': return 'فحص التوقيع الرقمي RSA وشهادات الأمان...'
    case 'backing_up': return 'إنشاء لقطة النسخ الاحتياطي الذرية...'
    case 'applying': return 'استبدال ملفات النظام بالنسخ الجديدة...'
    case 'migrating': return 'تطبيق تحديثات وهيكلة قاعدة البيانات...'
    case 'desktop_handoff_pending': return 'تسليم التحديث لخدمة سطح المكتب وإعادة التشغيل...'
    case 'completed': return 'اكتمل التحديث بنجاح.'
    case 'rolled_back': return 'تم التراجع التلقائي بنجاح.'
    case 'failed': return 'حدث خطأ أثناء التحديث.'
    default: return 'تجهيز العملية...'
  }
}

function formatBytes(bytes?: number): string {
  if (!bytes || bytes <= 0) return '0 ميجابايت'
  const mb = bytes / (1024 * 1024)
  return mb >= 1 ? `${mb.toFixed(1)} ميجابايت` : `${Math.round(bytes / 1024)} كيلوبايت`
}

function formatUpdateError(value: unknown): string {
  const data = asRecord(value)
  const baseMessage = typeof data?.message === 'string'
    ? data.message
    : 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
  const errorCode = typeof data?.errorCode === 'string'
    ? data.errorCode
    : (typeof data?.status === 'string' ? data.status : null)
  const details = typeof data?.details === 'string' ? data.details : null
  const checkedUrl = typeof data?.checkedUrl === 'string' ? data.checkedUrl : null
  const technicalParts = [
    errorCode ? `code: ${errorCode}` : null,
    details ? `details: ${details}` : null,
    checkedUrl ? `url: ${checkedUrl}` : null,
  ].filter(Boolean)

  return technicalParts.length ? `${baseMessage} (${technicalParts.join(' | ')})` : baseMessage
}

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return value !== null && typeof value === 'object'
    ? value as Record<string, unknown>
    : undefined
}
