import { RotateCcw, ShieldCheck } from 'lucide-react'

interface RollbackPointsProps {
  currentVersion: string | null | undefined
  snapshots: UpdateSnapshot[]
  busy: boolean
  onRollback: (snapshotPath: string) => void
}

export default function RollbackPoints({ currentVersion, snapshots, busy, onRollback }: RollbackPointsProps) {
  return (
    <div className="card" style={{ padding: '1rem', border: '1px solid var(--border)' }} data-testid="rollback-points">
      <h4 style={{ margin: '0 0 0.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
        <RotateCcw size={18} /> الرجوع إلى إصدار سابق
      </h4>
      <p style={{ margin: '0 0 0.5rem', fontSize: '0.85rem' }}>
        الإصدار الحالي: <strong dir="ltr">v{currentVersion || '—'}</strong>
      </p>
      <p style={{ color: 'var(--text-muted)', fontSize: '0.8rem', margin: '0 0 1rem' }}>
        تظهر الإصدارات الأقدم التي لها نقطة سليمة وصالحة لنسختك الحالية فقط. نسخ أمان العمليات محفوظة داخليًا.
      </p>
      {snapshots.length === 0 ? (
        <p role="status" style={{ color: 'var(--text-muted)', margin: 0 }}>لا توجد نقطة رجوع صالحة لهذا الإصدار.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          {snapshots.map((snapshot) => (
            <div key={snapshot.snapshot_name} data-testid="rollback-destination" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.75rem', padding: '0.85rem', border: '1px solid var(--border)', borderRadius: '8px', background: 'var(--bg)' }}>
              <div>
                <strong dir="ltr">v{snapshot.from_version}</strong>
                <div style={{ color: 'var(--text-muted)', fontSize: '0.75rem', marginTop: '0.3rem' }}>
                  حُفظت في {new Date(snapshot.timestamp).toLocaleString('ar-EG')} · {snapshot.files_count} ملفًا للاسترجاع
                </div>
              </div>
              <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => onRollback(snapshot.snapshot_path)}>
                <RotateCcw size={14} /> الرجوع إلى <bdi>v{snapshot.from_version}</bdi>
              </button>
            </div>
          ))}
        </div>
      )}
      <p style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', color: 'var(--text-muted)', fontSize: '0.8rem', margin: '1rem 0 0' }}>
        <ShieldCheck size={16} /> الاسترجاع يعيد ملفات البرنامج وواجهة الإصدار المختار، ويحافظ على بياناتك الحالية.
      </p>
    </div>
  )
}
