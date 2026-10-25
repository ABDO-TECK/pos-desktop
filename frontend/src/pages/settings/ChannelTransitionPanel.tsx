import { useEffect } from 'react'
import useChannelTransition, { transitionReason, type TransitionPhase } from '../../hooks/useChannelTransition'

interface Props {
  channel: ActiveUpdateChannel
  installedVersion: string
  installedChannel: ActiveUpdateChannel | 'retired'
  disabled: boolean
  onBusy: (busy: boolean, phase?: TransitionPhase) => void
  autoPreview?: boolean
}

export default function ChannelTransitionPanel({
  channel,
  installedVersion,
  installedChannel,
  disabled,
  onBusy,
  autoPreview,
}: Props) {
  const shouldAutoPreview = autoPreview ?? (channel !== installedChannel)
  const transition = useChannelTransition(channel, { autoPreview: shouldAutoPreview })
  useEffect(() => { onBusy(transition.busy, transition.phase) }, [onBusy, transition.busy, transition.phase])
  const preview = transition.preview
  return (
    <div data-testid="channel-transition" style={{ border: '1px solid var(--border)', borderRadius: 12, padding: '1rem', display: 'grid', gap: '.75rem' }} aria-busy={transition.busy || transition.checking}>
      <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
        <strong>إصدار التطبيق المثبّت: v{installedVersion} · {installedChannel.toUpperCase()}</strong>
        <span>قناة المتابعة: {channel.toUpperCase()}</span>
      </div>
      <p style={{ margin: 0, color: 'var(--text-muted)' }}>اختيار القناة يحفظ تفضيل المتابعة فقط. تثبيت إصدارها يحتاج معاينة وتأكيدًا منفصلًا.</p>
      <div style={{ display: 'flex', gap: '.5rem', flexWrap: 'wrap' }}>
        <button className="btn btn-secondary" disabled={disabled || transition.busy || transition.checking} onClick={() => void transition.check()} data-testid="preview-channel-transition">
          {transition.checking ? 'جاري فحص إصدار القناة…' : 'معاينة آخر إصدار للقناة'}
        </button>
        {preview?.status === 'ready' && (
          <>
            <button className="btn btn-primary" disabled={disabled || transition.busy} onClick={() => void transition.apply()} data-testid="apply-channel-transition">تثبيت {preview.target?.channel.toUpperCase()} v{preview.target?.version}</button>
            <button className="btn btn-secondary" disabled={transition.busy || transition.waiting} onClick={transition.cancel}>انتظار دون تثبيت</button>
          </>
        )}
      </div>
      {preview && <div role="status" data-testid="transition-preview-result">
        {preview.target && <p style={{ margin: '0 0 .4rem' }}>الإصدار المتاح للقناة: v{preview.target.version}{preview.package_type ? ` · ${preview.package_type === 'delta' ? 'ملفات متغيرة فقط (Delta)' : 'حزمة ملفات البرنامج كاملة'}` : ''}</p>}
        {preview.status === 'ready' || preview.status === 'same_release' ? preview.message : transitionReason(preview.reason_code)}
        {transition.waiting && <p style={{ margin: '.4rem 0 0' }}>اخترت الانتظار على نسختك الحالية ومتابعة {channel.toUpperCase()}. تظل معلومات الإصدار ظاهرة؛ لن يتم تثبيته إلا بتأكيد جديد منك.</p>}
      </div>}
      {transition.busy && <p role="status">جاري تنزيل الحزمة والتحقق منها؛ سيُعاد تشغيل الخدمات تلقائيًا، ثم التحقق من الملفات والبيانات.</p>}
      {transition.error && <p role="alert" style={{ color: 'var(--danger, #ef4444)', margin: 0 }}>{transition.error}</p>}
    </div>
  )
}
