import { useCallback, useEffect, useState } from 'react'
import { 
  Server, 
  Activity, 
  AlertTriangle, 
  ShieldCheck, 
  RefreshCw, 
  CheckCircle2, 
  XCircle, 
  RotateCcw, 
  Search, 
  Clock, 
  Radio, 
  Trash2,
  ChevronRight,
  X
} from 'lucide-react'
import toast from 'react-hot-toast'
import { getFleetStats, getFleetDevices, getDeviceDetails, purgeTelemetry } from '../../api/endpoints'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'

export default function FleetDashboard() {
  const { confirm } = useConfirmStore()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [stats, setStats] = useState<FleetStatsData | null>(null)
  const [devices, setDevices] = useState<FleetDeviceRecord[]>([])
  const [search, setSearch] = useState('')
  
  // Device detail modal
  const [selectedDevice, setSelectedDevice] = useState<DeviceDetailsData | null>(null)
  const [purging, setPurging] = useState(false)


  const loadData = useCallback(async () => {
    try {
      const [statsRes, devRes] = await Promise.allSettled([
        getFleetStats(),
        getFleetDevices({ limit: 100, search: search || undefined }),
      ])

      if (statsRes.status === 'fulfilled' && statsRes.value.data?.data) {
        setStats(statsRes.value.data.data)
      }
      if (devRes.status === 'fulfilled' && Array.isArray(devRes.value.data?.data?.devices)) {
        setDevices(devRes.value.data.data.devices)
      }
    } catch {
      toast.error('فشل تحميل بيانات أسطول التحديثات.')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [search])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleRefresh = () => {
    setRefreshing(true)
    loadData()
  }

  const handleViewDevice = async (deviceId: string) => {
    try {
      const res = await getDeviceDetails(deviceId)
      if (res.data?.data) {
        setSelectedDevice(res.data.data)
      }
    } catch {
      toast.error('تعذر جلب سجلات الجهاز.')
    }
  }


  const handlePurge = async () => {
    const ok = await confirm('هل ترغب في أرشفة وحذف سجلات التتبع الأقدم من 90 يومًا لتوفير المساحة؟')
    if (!ok) return

    setPurging(true)
    try {
      const res = await purgeTelemetry(90)
      const count = res.data?.data?.deleted_count ?? 0
      toast.success(`تم حذف ${count} سجل قديم بنجاح.`)
      loadData()
    } catch {
      toast.error('فشل تنظيف السجلات القديمة.')
    } finally {
      setPurging(false)
    }
  }

  const getEventBadge = (eventType: string, success: boolean | number) => {
    const isSuccess = !!success
    switch (eventType) {
      case 'update_applied':
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: 'rgba(34, 197, 94, 0.15)', color: '#4ade80', fontWeight: 600 }}>تطبيق ناجح</span>
      case 'update_failed':
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: 'rgba(239, 68, 68, 0.15)', color: '#f87171', fontWeight: 600 }}>فشل التحديث</span>
      case 'rollback_completed':
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: 'rgba(245, 158, 11, 0.15)', color: '#fbbf24', fontWeight: 600 }}>تم التراجع</span>
      case 'update_available':
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: 'rgba(56, 189, 248, 0.15)', color: '#38bdf8', fontWeight: 600 }}>تحديث متاح</span>
      case 'update_check_started':
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: 'rgba(148, 163, 184, 0.15)', color: '#cbd5e1' }}>فحص التحديث</span>
      default:
        return <span style={{ padding: '0.2rem 0.5rem', borderRadius: '4px', fontSize: '0.75rem', background: isSuccess ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)', color: isSuccess ? '#4ade80' : '#f87171' }}>{eventType}</span>
    }
  }

  if (loading) {
    return (
      <div style={{ padding: '3rem', textAlign: 'center', color: 'var(--text-muted)' }}>
        <RefreshCw size={24} className="spin" style={{ margin: '0 auto 1rem auto' }} />
        <p>جاري تحميل تحليلات أسطول نقاط البيع (Fleet Analytics)...</p>
      </div>
    )
  }

  const health = stats?.update_health
  const totalDevs = stats?.total_devices ?? devices.length

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '1rem' }}>
        <div>
          <SectionTitle icon={<Server size={20} />} label="لوحة متابعة أسطول التحديثات (Fleet Telemetry)" />
          <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: '0.25rem 0 0 0' }}>
            مراقبة حية لصحة التحديثات وتوزيع الإصدارات عبر جميع نقاط البيع المفعلة بدون جمع أي بيانات تجارية أو شخصية.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <button
            type="button"
            onClick={handlePurge}
            disabled={purging}
            className="btn btn-secondary btn-sm"
            title="تنظيف السجلات الأقدم من 90 يوماً"
          >
            <Trash2 size={14} /> تنظيف السجلات
          </button>
          <button
            type="button"
            onClick={handleRefresh}
            disabled={refreshing}
            className="btn btn-secondary btn-sm"
          >
            <RefreshCw size={14} className={refreshing ? 'spin' : ''} /> تحديث البيانات
          </button>
        </div>
      </div>

      {/* Active Alerts Banner */}
      {Array.isArray(stats?.alerts) && stats.alerts.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          {stats.alerts.map((alert, idx) => (
            <div
              key={idx}
              style={{
                padding: '0.85rem 1.2rem',
                borderRadius: '8px',
                background: alert.severity === 'critical' ? 'rgba(239, 68, 68, 0.12)' : alert.severity === 'warning' ? 'rgba(245, 158, 11, 0.12)' : 'rgba(56, 189, 248, 0.12)',
                border: `1px solid ${alert.severity === 'critical' ? 'rgba(239, 68, 68, 0.4)' : alert.severity === 'warning' ? 'rgba(245, 158, 11, 0.4)' : 'rgba(56, 189, 248, 0.4)'}`,
                display: 'flex',
                alignItems: 'center',
                gap: '0.75rem',
              }}
            >
              <AlertTriangle size={20} style={{ color: alert.severity === 'critical' ? '#f87171' : alert.severity === 'warning' ? '#fbbf24' : '#38bdf8', flexShrink: 0 }} />
              <div>
                <strong style={{ fontSize: '0.9rem', color: alert.severity === 'critical' ? '#f87171' : alert.severity === 'warning' ? '#fbbf24' : '#38bdf8' }}>{alert.title}: </strong>
                <span style={{ fontSize: '0.85rem', color: '#e2e8f0' }}>{alert.message}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* KPI Cards Grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '1rem' }}>
        {/* Total Devices */}
        <div className="card" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
            <span>إجمالي أجهزة الأسطول</span>
            <Radio size={16} style={{ color: '#38bdf8' }} />
          </div>
          <div style={{ fontSize: '1.75rem', fontWeight: 700, color: 'var(--text)' }}>
            {totalDevs} <span style={{ fontSize: '0.85rem', fontWeight: 400, color: 'var(--text-muted)' }}>أجهزة</span>
          </div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>نقاط البيع النشطة خلال 30 يوماً</div>
        </div>

        {/* Update Success Rate */}
        <div className="card" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
            <span>نسبة نجاح التحديثات</span>
            <CheckCircle2 size={16} style={{ color: '#4ade80' }} />
          </div>
          <div style={{ fontSize: '1.75rem', fontWeight: 700, color: '#4ade80' }}>
            {health?.success_rate ?? 100}%
          </div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
            {health?.successful ?? 0} عملية ناجحة من إجمالي {health?.total_events ?? 0}
          </div>
        </div>

        {/* Failure Rate */}
        <div className="card" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
            <span>معدل الفشل</span>
            <XCircle size={16} style={{ color: (health?.failure_rate ?? 0) > 10 ? '#f87171' : 'var(--text-muted)' }} />
          </div>
          <div style={{ fontSize: '1.75rem', fontWeight: 700, color: (health?.failure_rate ?? 0) > 10 ? '#f87171' : 'var(--text)' }}>
            {health?.failure_rate ?? 0}%
          </div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
            {health?.failed ?? 0} عملية فاشلة
          </div>
        </div>

        {/* Rollbacks */}
        <div className="card" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '0.85rem' }}>
            <span>عمليات التراجع الذري</span>
            <RotateCcw size={16} style={{ color: '#fbbf24' }} />
          </div>
          <div style={{ fontSize: '1.75rem', fontWeight: 700, color: '#fbbf24' }}>
            {health?.rollbacks ?? 0}
          </div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>حماية الاستقرار والبيانات</div>
        </div>
      </div>

      {/* Distributions (Versions & Channels) */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '1.25rem' }}>
        {/* Version Distribution */}
        <div className="card" style={{ padding: '1.25rem' }}>
          <h4 style={{ margin: '0 0 1rem 0', fontSize: '0.95rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <Activity size={16} style={{ color: '#38bdf8' }} /> توزيع الإصدارات الحالية (Versions)
          </h4>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            {stats?.version_distribution && Object.keys(stats.version_distribution).length > 0 ? (
              Object.entries(stats.version_distribution).map(([ver, count]) => {
                const pct = totalDevs > 0 ? Math.round((count / totalDevs) * 100) : 0
                return (
                  <div key={ver}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.25rem' }}>
                      <strong>v{ver}</strong>
                      <span style={{ color: 'var(--text-muted)' }}>{count} أجهزة ({pct}%)</span>
                    </div>
                    <div style={{ width: '100%', height: '6px', background: 'var(--border)', borderRadius: '3px', overflow: 'hidden' }}>
                      <div style={{ width: `${pct}%`, height: '100%', background: '#38bdf8', borderRadius: '3px' }} />
                    </div>
                  </div>
                )
              })
            ) : (
              <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: 0 }}>لا توجد بيانات توزيع إصدارات مسجلة بعد.</p>
            )}
          </div>
        </div>

        {/* Channel Distribution */}
        <div className="card" style={{ padding: '1.25rem' }}>
          <h4 style={{ margin: '0 0 1rem 0', fontSize: '0.95rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <ShieldCheck size={16} style={{ color: '#4ade80' }} /> توزيع القنوات (Update Channels)
          </h4>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            {/* Stable */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.25rem' }}>
                <span style={{ color: '#4ade80', fontWeight: 600 }}>قناة Stable (الإنتاج)</span>
                <span style={{ color: 'var(--text-muted)' }}>{stats?.channel_distribution?.stable ?? 0} أجهزة</span>
              </div>
              <div style={{ width: '100%', height: '6px', background: 'var(--border)', borderRadius: '3px', overflow: 'hidden' }}>
                <div style={{ width: `${totalDevs > 0 ? ((stats?.channel_distribution?.stable ?? 0) / totalDevs) * 100 : 0}%`, height: '100%', background: '#4ade80', borderRadius: '3px' }} />
              </div>
            </div>

            {/* Beta */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.25rem' }}>
                <span style={{ color: '#f59e0b', fontWeight: 600 }}>قناة Beta (التجريبية)</span>
                <span style={{ color: 'var(--text-muted)' }}>{stats?.channel_distribution?.beta ?? 0} أجهزة</span>
              </div>
              <div style={{ width: '100%', height: '6px', background: 'var(--border)', borderRadius: '3px', overflow: 'hidden' }}>
                <div style={{ width: `${totalDevs > 0 ? ((stats?.channel_distribution?.beta ?? 0) / totalDevs) * 100 : 0}%`, height: '100%', background: '#f59e0b', borderRadius: '3px' }} />
              </div>
            </div>

            {/* RC */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.25rem' }}>
                <span style={{ color: '#38bdf8', fontWeight: 600 }}>قناة RC (المرشحة)</span>
                <span style={{ color: 'var(--text-muted)' }}>{stats?.channel_distribution?.rc ?? 0} أجهزة</span>
              </div>
              <div style={{ width: '100%', height: '6px', background: 'var(--border)', borderRadius: '3px', overflow: 'hidden' }}>
                <div style={{ width: `${totalDevs > 0 ? ((stats?.channel_distribution?.rc ?? 0) / totalDevs) * 100 : 0}%`, height: '100%', background: '#38bdf8', borderRadius: '3px' }} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Fleet Devices Table */}
      <div className="card" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.75rem' }}>
          <h4 style={{ margin: 0, fontSize: '0.95rem' }}>أجهزة الأسطول النشطة ({devices.length})</h4>

          {/* Search Box */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', background: 'var(--bg-input, #1e1e2e)', padding: '0.35rem 0.75rem', borderRadius: '6px', border: '1px solid var(--border)' }}>
            <Search size={14} style={{ color: 'var(--text-muted)' }} />
            <input
              type="text"
              placeholder="بحث بالمعرّف أو الإصدار..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{ background: 'transparent', border: 'none', color: '#fff', fontSize: '0.85rem', outline: 'none', width: '180px' }}
            />
          </div>
        </div>

        {devices.length === 0 ? (
          <p style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '2rem 0', margin: 0 }}>
            لم يتم تسجيل أي أجهزة في الأسطول بعد.
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'right', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '0.6rem 0.5rem' }}>معرّف الجهاز المجهول (UUID)</th>
                  <th style={{ padding: '0.6rem 0.5rem' }}>الإصدار الحالي</th>
                  <th style={{ padding: '0.6rem 0.5rem' }}>القناة</th>
                  <th style={{ padding: '0.6rem 0.5rem' }}>آخر حدث تحديث</th>
                  <th style={{ padding: '0.6rem 0.5rem' }}>آخر ظهور</th>
                  <th style={{ padding: '0.6rem 0.5rem' }}>الإجراءات</th>
                </tr>
              </thead>
              <tbody>
                {devices.map((d) => (
                  <tr key={d.device_id} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <td style={{ padding: '0.6rem 0.5rem', fontFamily: 'monospace', color: '#38bdf8' }}>
                      {d.device_id.substring(0, 8)}...{d.device_id.substring(d.device_id.length - 4)}
                    </td>
                    <td style={{ padding: '0.6rem 0.5rem', fontWeight: 600 }}>v{d.current_version}</td>
                    <td style={{ padding: '0.6rem 0.5rem' }}>
                      <span style={{
                        padding: '0.15rem 0.45rem',
                        borderRadius: '4px',
                        fontSize: '0.75rem',
                        fontWeight: 700,
                        background: d.channel === 'beta' ? 'rgba(245, 158, 11, 0.15)' : d.channel === 'rc' ? 'rgba(56, 189, 248, 0.15)' : 'rgba(34, 197, 94, 0.15)',
                        color: d.channel === 'beta' ? '#f59e0b' : d.channel === 'rc' ? '#38bdf8' : '#4ade80'
                      }}>
                        {d.channel.toUpperCase()}
                      </span>
                    </td>
                    <td style={{ padding: '0.6rem 0.5rem' }}>
                      {getEventBadge(d.last_event, d.last_event_success)}
                    </td>
                    <td style={{ padding: '0.6rem 0.5rem', color: 'var(--text-muted)' }}>
                      {d.last_seen_at ? new Date(d.last_seen_at).toLocaleString('ar-EG') : '—'}
                    </td>
                    <td style={{ padding: '0.6rem 0.5rem' }}>
                      <button
                        type="button"
                        onClick={() => handleViewDevice(d.device_id)}
                        className="btn btn-secondary btn-sm"
                        style={{ padding: '0.2rem 0.5rem', fontSize: '0.75rem' }}
                      >
                        عرض السجل <ChevronRight size={12} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Device Details Modal */}
      {selectedDevice && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0,0,0,0.7)',
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
                <Clock size={18} style={{ color: '#38bdf8' }} /> سجل أحداث الجهاز (Device Timeline)
              </h3>
              <button
                type="button"
                onClick={() => setSelectedDevice(null)}
                style={{ background: 'transparent', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}
              >
                <X size={20} />
              </button>
            </div>

            <div style={{ background: 'var(--card-bg, #2a2a3e)', padding: '0.75rem', borderRadius: '6px', fontSize: '0.85rem', display: 'flex', flexWrap: 'wrap', gap: '1rem' }}>
              <div><strong>المعرّف:</strong> <code style={{ color: '#38bdf8' }}>{selectedDevice.device_id}</code></div>
              <div><strong>الإصدار:</strong> v{selectedDevice.current_version}</div>
              <div><strong>القناة:</strong> {selectedDevice.channel.toUpperCase()}</div>
            </div>

            <div style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
              {selectedDevice.events.map((ev) => (
                <div
                  key={ev.id}
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
                    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                      {getEventBadge(ev.event_type, ev.success)}
                      {ev.duration_ms && (
                        <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>({ev.duration_ms}ms)</span>
                      )}
                    </div>
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                      {new Date(ev.created_at).toLocaleString('ar-EG')}
                    </span>
                  </div>

                  {ev.error_code && (
                    <div style={{ fontSize: '0.8rem', color: '#f87171' }}>
                      كود الخطأ: {ev.error_code}
                    </div>
                  )}

                  {ev.metadata && Object.keys(ev.metadata).length > 0 && (
                    <div style={{ fontSize: '0.75rem', color: '#cbd5e1', background: 'rgba(0,0,0,0.2)', padding: '0.4rem', borderRadius: '4px' }}>
                      {JSON.stringify(ev.metadata)}
                    </div>
                  )}
                </div>
              ))}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button
                type="button"
                onClick={() => setSelectedDevice(null)}
                className="btn btn-secondary btn-sm"
              >
                إغلاق
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
