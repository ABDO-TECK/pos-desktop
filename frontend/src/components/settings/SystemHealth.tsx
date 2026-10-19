import { useState, useEffect } from 'react'
import { Activity, Database, HardDrive, Cpu, RefreshCw, CheckCircle2, AlertTriangle, XCircle } from 'lucide-react'
import { getHealthDiagnostics, type HealthDiagnostics } from '../../api/endpoints'
import styles from './SystemHealth.module.css'

type HealthData = HealthDiagnostics & { timestamp: string }

function StatusIcon({ status }: { status: string }) {
  if (status === 'ok' || status === 'connected') return <CheckCircle2 size={16} style={{ color: 'var(--primary)' }} />
  if (status === 'warning') return <AlertTriangle size={16} style={{ color: '#f59e0b' }} />
  return <XCircle size={16} style={{ color: 'var(--danger)' }} />
}

export default function SystemHealth() {
  const [data, setData] = useState<HealthData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const fetchHealth = async () => {
    setLoading(true)
    setError('')
    try {
      const res = await getHealthDiagnostics()
      const healthData = res.data
      setData({
        ...healthData,
        timestamp: new Date().toISOString(),
      })
    } catch (err) { setError('فشل الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchHealth() }, [])

  return (
    <div className={styles.container}>
      <div className={styles.header}>
        <h3 className={styles.title}>
          <Activity size={18} /> حالة النظام
        </h3>
        <button className="btn btn-ghost btn-sm" onClick={fetchHealth} disabled={loading}>
          <RefreshCw size={14} className={loading ? 'spinning' : ''} /> تحديث
        </button>
      </div>

      {error && <div style={{ color: 'var(--danger)', fontSize: '0.85rem' }}>{error}</div>}

      {data && (
        <div className={styles.grid}>
          {/* Database */}
          <div className={styles.card}>
            <div className={styles.cardTitle}>
              <Database size={16} /> قاعدة البيانات <StatusIcon status={data?.checks?.database?.status ?? 'unknown'} />
            </div>
            <div className={styles.row}><span>الحالة</span><span>{data?.checks?.database?.status ?? 'unknown'}</span></div>
            {data?.checks?.database?.latency_ms != null && (
              <div className={styles.row}><span>زمن الاستجابة</span><span>{data?.checks?.database?.latency_ms} ms</span></div>
            )}
          </div>

          {/* Disk */}
          <div className={styles.card}>
            <div className={styles.cardTitle}>
              <HardDrive size={16} /> القرص الصلب <StatusIcon status={data?.checks?.disk?.status ?? 'unknown'} />
            </div>
            <div className={styles.row}><span>المساحة المتاحة</span><span>{data?.checks?.disk?.free_gb ?? 0} GB</span></div>
            <div className={styles.row}><span>نسبة الاستخدام</span><span>{data?.checks?.disk?.used_percent ?? 0}%</span></div>
            <div style={{ height: 6, background: 'var(--border)', borderRadius: 3, overflow: 'hidden' }}>
              <div style={{
                height: '100%', borderRadius: 3,
                width: `${data?.checks?.disk?.used_percent ?? 0}%`,
                background: (data?.checks?.disk?.used_percent ?? 0) > 90 ? 'var(--danger)' : 'var(--primary)',
              }} />
            </div>
          </div>

          {/* Memory */}
          <div className={styles.card}>
            <div className={styles.cardTitle}>
              <Cpu size={16} /> الذاكرة <StatusIcon status={data?.checks?.memory?.status ?? 'unknown'} />
            </div>
            <div className={styles.row}><span>الاستخدام الحالي</span><span>{data?.checks?.memory?.usage_mb ?? 0} MB</span></div>
            <div className={styles.row}><span>الذروة</span><span>{data?.checks?.memory?.peak_mb ?? 0} MB</span></div>
            <div className={styles.row}><span>الحد الأقصى</span><span>{data?.checks?.memory?.limit ?? 'unknown'}</span></div>
          </div>

          {/* PHP */}
          <div className={styles.card}>
            <div className={styles.cardTitle}>
              ⚙️ PHP {data?.checks?.php?.version ?? 'unknown'}
            </div>
            {data?.checks?.php?.extensions && Object.entries(data.checks.php.extensions).map(([ext, loaded]) => (
              <div key={ext} className={styles.row}>
                <span>{ext}</span>
                <span style={{ color: loaded ? 'var(--primary)' : 'var(--danger)' }}>
                  {loaded ? '✓ مُفعّل' : '✗ غير مُفعّل'}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {data && (
        <div className={styles.footer}>
          آخر فحص: {data.timestamp ? new Date(data.timestamp).toLocaleString('ar-EG') : 'غير معروف'}
          {' — '}
          الحالة العامة: <strong style={{ color: data.status === 'ok' ? 'var(--primary)' : 'var(--danger)' }}>
            {data.status === 'ok' ? '✓ سليم' : '⚠ يحتاج انتباه'}
          </strong>
        </div>
      )}
    </div>
  )
}
