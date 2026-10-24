import { useCallback, useEffect, useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import toast from 'react-hot-toast'
import { applyChannelTransition, getUpdateStatus, previewChannelTransition } from '../api/endpoints'
import useCartStore from '../store/cartStore'
import { useConfirmStore } from '../store/confirmStore'
import { withUpdateRestart } from '../utils/updateRestart'

const waitingPreferenceKey = 'pos.channel-transition.waiting'
function retainedWaitingChoice(preview: ChannelTransitionPreview): boolean {
  try {
    const saved: unknown = JSON.parse(sessionStorage.getItem(waitingPreferenceKey) ?? 'null')
    return typeof saved === 'object' && saved !== null && 'channel' in saved && 'installed' in saved && 'target' in saved
      && saved.channel === preview.selected_channel && saved.installed === preview.installed.version && saved.target === preview.target?.version
  } catch { return false }
}

export function transitionReason(reason: string | null): string {
  const messages: Record<string, string> = {
    transition_package_unavailable: 'حزمة انتقال معتمدة لهذا الإصدار غير متاحة بعد. يمكنك الانتظار أو متابعة القناة الحالية.',
    no_target: 'لا يوجد إصدار منشور للقناة المختارة حاليًا.',
    discovery_failed: 'تعذر الاتصال بمصدر الإصدارات. حاول التحقق مجددًا.',
    metadata_download_failed: 'تعذر تنزيل معلومات الانتقال. حاول مجددًا عند استقرار الاتصال.',
    data_schema_incompatible: 'بنية البيانات الحالية غير متوافقة مع الإصدار المطلوب؛ تم منع الانتقال للحفاظ على بياناتك.',
    data_compatibility_unverifiable: 'تعذر إثبات توافق قاعدة البيانات؛ لم يتم السماح بالانتقال.',
    source_inventory_mismatch: 'ملفات الإصدار المثبّت تختلف عن النسخة المعتمدة؛ لا يمكن تجاوز هذا الفحص بحزمة كاملة.',
    signature_invalid: 'توقيع حزمة الانتقال غير صالح؛ لن يتم تثبيتها.',
    shell_mismatch: 'محرك تشغيل البرنامج غير متوافق مع هذه الحزمة.',
    immutable_shell_unavailable: 'هذا الانتقال يتطلب نسخة سطح المكتب المتوافقة.',
    preview_expired_or_changed: 'انتهت المعاينة أو تغير الإصدار/القناة. تحقق مجددًا قبل التأكيد.',
    update_operation_busy: 'هناك عملية بيع أو تحديث قيد التنفيذ. أكملها أولًا.',
    package_download_or_digest_failed: 'لم يكتمل تنزيل حزمة سليمة؛ لم يُؤكَّد تثبيت الانتقال.',
  }
  return messages[reason ?? ''] ?? 'لا تتوفر حاليًا حزمة انتقال يمكن التحقق من سلامتها وتوافقها مع بياناتك.'
}

export type TransitionPhase = 'idle' | 'preparing' | 'installing' | 'verifying'

export default function useChannelTransition(
  channel: ActiveUpdateChannel,
  options?: { autoPreview?: boolean }
) {
  const autoPreview = options?.autoPreview ?? true
  const [preview, setPreview] = useState<ChannelTransitionPreview | null>(null)
  const [checking, setChecking] = useState(false)
  const [busy, setBusy] = useState(false)
  const [phase, setPhase] = useState<TransitionPhase>('idle')
  const [error, setError] = useState<string | null>(null)
  const [waiting, setWaiting] = useState(false)
  const generation = useRef(0)
  const { confirm } = useConfirmStore()

  const check = useCallback(async () => {
    const request = ++generation.current
    setChecking(true)
    setError(null)
    setPreview(null)
    setWaiting(false)
    try {
      const result = await previewChannelTransition()
      if (request === generation.current && result.data.data?.selected_channel === channel) {
        setPreview(result.data.data)
        setWaiting(retainedWaitingChoice(result.data.data))
      }
    } catch {
      if (request === generation.current) setError('تعذر التحقق من انتقال القناة. لم تتغير النسخة المثبتة.')
    } finally {
      if (request === generation.current) setChecking(false)
    }
  }, [channel])

  useEffect(() => {
    if (autoPreview) {
      void check()
    } else {
      generation.current += 1
      setPreview(null)
      setError(null)
      setWaiting(false)
      setChecking(false)
    }
    return () => { generation.current += 1 }
  }, [check, autoPreview])

  const wait = () => {
    if (!preview?.target) return
    setWaiting(true)
    try {
      sessionStorage.setItem(waitingPreferenceKey, JSON.stringify({ channel, installed: preview.installed.version, target: preview.target.version }))
    } catch { /* The choice still remains visible when browser storage is unavailable. */ }
  }

  const apply = async () => {
    if (!preview?.preview_id || !preview.target || busy) return
    if (useCartStore.getState().items.length) {
      toast.error('أكمل عملية البيع أو أفرغ السلة قبل الانتقال إلى قناة أخرى.')
      return
    }
    const snapshot = preview
    const target = preview.target
    const previewId = preview.preview_id
    const request = generation.current
    const accepted = await confirm(`تثبيت ${target.channel.toUpperCase()} v${target.version} بدلًا من v${snapshot.installed.version}؟\n\nسيتم استبدال ملفات البرنامج مع الحفاظ على بيانات العمل. عند الرجوع إلى إصدار أقدم تعود واجهته وسلوكه القديمان. لن تُسترجع قاعدة بيانات قديمة فوق مبيعاتك الحالية.`)
    if (!accepted || request !== generation.current) return
    setBusy(true)
    setPhase('preparing')
    setWaiting(false)
    try { sessionStorage.removeItem(waitingPreferenceKey) } catch { /* Optional UI preference. */ }
    setError(null)
    try {
      const capability = Boolean((await window.posRuntime?.getDeltaCapability?.())?.capable)
      const result = (await applyChannelTransition(previewId, capability)).data.data
      if (!result?.ok || !result.requires_desktop_handoff) throw new Error('تعذر تجهيز الانتقال الآمن.')
      setPhase('installing')
      await withUpdateRestart(async () => {
        if (!window.posRuntime) throw new Error('خدمة تثبيت تحديث البرنامج غير متاحة.')
        const handoff = await window.posRuntime.applyStagedDelta(result.handoff_version)
        if (!handoff.ok) throw new Error(handoff.error || 'فشل استبدال ملفات الانتقال.')
        setPhase('verifying')
        const deadline = Date.now() + 100_000
        while (Date.now() < deadline) {
          const state = (await getUpdateStatus(true, { hideGlobalError: true })).data.data?.update_state
          if (state?.state === 'failed' || state?.state === 'rolled_back') throw new Error(state.error || 'فشل التحقق النهائي من الانتقال.')
          if (state?.transition_receipt === result.receipt_id && state.transition_verified) return
          await new Promise(resolve => setTimeout(resolve, 1000))
        }
        throw new Error('لم يكتمل التحقق النهائي بعد. راجع حالة التحديث قبل إعادة المحاولة.')
      })
      toast.success('تم الانتقال والتحقق من ملفات الإصدار وتوافق البيانات.')
      window.location.reload()
    } catch (failure: unknown) {
      const reason: unknown = isAxiosError(failure) ? failure.response?.data?.errors?.reason_code : undefined
      const message = typeof reason === 'string' ? transitionReason(reason) : failure instanceof Error ? failure.message : 'تعذر إكمال الانتقال.'
      setError(message)
      setPreview(null)
      toast.error(message)
    } finally {
      setBusy(false)
      setPhase('idle')
    }
  }

  return { preview, checking, busy, phase, error, waiting, check, apply, cancel: wait }
}
