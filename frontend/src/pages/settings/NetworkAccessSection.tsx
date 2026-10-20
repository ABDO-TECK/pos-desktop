import { useState, useEffect } from 'react'
import { Wifi, Copy, Check, HelpCircle, QrCode, LoaderCircle, X } from 'lucide-react'
import toast from 'react-hot-toast'
import { getNetworkInfo } from '../../api/endpoints'
import SectionTitle from '../../components/common/SectionTitle'
import LocalQrCode from '../../components/LocalQrCode'
import useNetworkAccessStore from '../../store/networkAccessStore'
import { useConfirmStore } from '../../store/confirmStore'
import styles from './NetworkAccessSection.module.css'

interface NetworkInfoData {
  ips: string[]
  port: string
  protocol: string
}

export default function NetworkAccessSection() {
  const [loading, setLoading] = useState(true)
  const [networkInfo, setNetworkInfo] = useState<NetworkInfoData | null>(null)
  const [copiedUrl, setCopiedUrl] = useState<string | null>(null)
  const lanAccess = useNetworkAccessStore(state => state.access)
  const lanAccessLoading = useNetworkAccessStore(state => state.loading)
  const preferredEnabled = useNetworkAccessStore(state => state.preferredEnabled)
  const setEnabled = useNetworkAccessStore(state => state.setEnabled)
  const confirm = useConfirmStore(state => state.confirm)
  const [selectedIdx, setSelectedIdx] = useState(0)

  useEffect(() => {
    getNetworkInfo()
      .then(res => {
        if (res.data && res.data.success) {
          setNetworkInfo(res.data.data)
        } else {
          // Fallback if data wrapper is different
          const data = (res.data as any).data || res.data
          if (data && data.ips) {
            setNetworkInfo(data)
          }
        }
      })
      .catch(err => {
        console.error('Failed to load network info:', err)
      })
      .finally(() => {
        setLoading(false)
      })
  }, [])

  const handleToggleLanAccess = async (enabled = !lanAccess.enabled) => {
    if (lanAccessLoading) return
    const accepted = await confirm(enabled
      ? 'تفعيل وصول الهاتف والأجهزة المتصلة بنفس الشبكة إلى النظام عبر HTTPS؟'
      : 'إيقاف وصول الهاتف والأجهزة المتصلة بالشبكة إلى النظام؟')
    if (accepted) await setEnabled(enabled)
  }

  const handleCopy = (url: string) => {
    navigator.clipboard.writeText(url)
      .then(() => {
        setCopiedUrl(url)
        toast.success('تم نسخ الرابط بنجاح')
        setTimeout(() => setCopiedUrl(null), 2000)
      })
      .catch(() => {
        toast.error('فشل نسخ الرابط')
      })
  }

  if (loading) {
    return (
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<Wifi size={16} />} label="الاتصال الشبكي (الهاتف والتابلت)" />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100px' }}>
          <span className="spinner" />
          <span style={{ marginRight: '0.5rem', color: 'var(--text-muted)' }}>جاري تحميل عناوين الاتصال الشبكي…</span>
        </div>
      </section>
    )
  }

  // Fallback / standard formatting depending on current browser URL
  const currentPort = window.location.port
  const currentPath = window.location.pathname.replace(/\/$/, '') // remove trailing slash

  // Generate connection options based on local IPs
  const ips = networkInfo?.ips || []
  const connectionOptions: Array<{ label: string; url: string; note?: string; type: 'web' | 'electron-http' | 'electron-https' }> = []
  const isElectron = window.location.protocol === 'app:'

  ips.forEach(ip => {
    if (isElectron) {
      const lanPort = lanAccess?.port || 8443
      connectionOptions.push({
        label: 'رابط النظام الآمن للهاتف (HTTPS)',
        url: `https://${ip}:${lanPort}/`,
        type: 'electron-https',
        note: lanAccess?.enabled
          ? 'افتح هذا الرابط من هاتف متصل بنفس شبكة Wi‑Fi. قد تحتاج إلى قبول الشهادة المحلية في أول زيارة.'
          : 'فعّل الوصول من المفتاح أعلاه لاستخدام هذا الرابط من الهاتف.',
      })
    } else if (currentPort === '5173') {
      // Vite development server
      connectionOptions.push({
        label: `عنوان الويب (Vite Dev)`,
        url: `${window.location.protocol}//${ip}:5173${currentPath}/`,
        type: 'web',
        note: 'مناسب للاختبار والتطوير من الجوال مباشرة.'
      })
    } else if (currentPort === '8080' || currentPort === '8443' || currentPort === '80' || currentPort === '443') {
      // Electron or standard ports
      const httpPort = networkInfo?.port || '8080'
      connectionOptions.push({
        label: 'رابط النظام اللاسلكي (HTTP)',
        url: `http://${ip}:${httpPort}${currentPath}/`,
        type: 'electron-http',
        note: 'رابط مباشر سريع متوافق مع كافة الهواتف بدون تحذيرات أمان.'
      })
      connectionOptions.push({
        label: 'رابط النظام الآمن (HTTPS)',
        url: `https://${ip}:8443${currentPath}/`,
        type: 'electron-https',
        note: 'رابط مشفر آمن. قد يتطلب متصفح هاتفك تأكيد استثناء شهادة الأمان الذاتية عند فتحه لأول مرة.'
      })
    } else {
      // Fallback relative to current access URL
      const portSuffix = currentPort ? `:${currentPort}` : ''
      connectionOptions.push({
        label: 'رابط الاتصال الشبكي المباشر',
        url: `${window.location.protocol}//${ip}${portSuffix}${currentPath}/`,
        type: 'web',
        note: 'رابط مخصص للوصول من متصفح الهواتف المتصلة بنفس الشبكة.'
      })
    }
  })

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <SectionTitle icon={<Wifi size={16} />} label="الاتصال الشبكي (الهاتف والتابلت)" />
      
      <p style={{ fontSize: '0.88rem', color: 'var(--text-muted)', lineHeight: '1.5', margin: 0 }}>
        يمكنك تشغيل وإدارة نظام المبيعات والمخازن مباشرة من هاتفك المحمول أو جهاز التابلت. 
        تأكد فقط من اتصال هاتفك <strong>بنفس شبكة الـ Wi-Fi</strong> المتصل بها هذا الكمبيوتر، ثم استخدم أحد الخيارات أدناه:
      </p>

      {isElectron && (
        <div className={styles.accessPanel}>
          <div className={styles.accessControl}>
            <span className={styles.accessIcon}><Wifi size={21} /></span>
            <div className={styles.accessText}>
              <strong id="lan-access-label">الوصول من الهاتف</strong>
              <p id="lan-access-description">يستمر في كل صفحات النظام، ويُحفظ اختيارك عند إعادة تشغيل البرنامج.</p>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={lanAccess.enabled}
              aria-labelledby="lan-access-label"
              aria-describedby="lan-access-description"
              aria-busy={lanAccessLoading}
              disabled={lanAccessLoading}
              className={styles.accessSwitch}
              onClick={() => void handleToggleLanAccess()}
            >
              <span className={styles.switchThumb}>
                {lanAccessLoading ? <LoaderCircle size={13} className={styles.loadingIcon} /> : lanAccess.enabled ? <Check size={13} /> : <X size={13} />}
              </span>
            </button>
          </div>
          <p className={styles.accessStatus} role="status">
            <span className={styles.statusDot} data-enabled={lanAccess.enabled} />
            {lanAccessLoading ? 'جاري تحديث حالة الوصول…' : lanAccess.enabled
              ? `الوصول مفعّل عبر HTTPS على المنفذ ${lanAccess.port}`
              : 'الوصول متوقف — فعّله عند الحاجة للاتصال من الهاتف'}
          </p>
          {(lanAccess.error || lanAccess.firewallRequired) && (
            <p className={styles.accessWarning} role="alert">
              {lanAccess.error || (lanAccess.enabled
                ? 'خدمة الوصول تعمل، لكن إعداد جدار الحماية لم يكتمل. اسمح للتطبيق على الشبكة الخاصة.'
                : 'تم إيقاف الوصول، لكن تعذرت إزالة قاعدة جدار الحماية.')}
            </p>
          )}
          {!lanAccess.enabled && preferredEnabled && !lanAccessLoading && (
            <button type="button" className="btn btn-ghost" onClick={() => void handleToggleLanAccess(false)}>
              إلغاء التفعيل التلقائي المحفوظ
            </button>
          )}
        </div>
      )}

      {ips.length === 0 ? (
        <div style={{ 
          padding: '1rem', 
          backgroundColor: 'rgba(245, 158, 11, 0.1)', 
          border: '1px solid rgba(245, 158, 11, 0.25)', 
          borderRadius: '8px',
          color: 'var(--warning, #f59e0b)',
          fontSize: '0.85rem'
        }}>
          ⚠️ لم يتم العثور على عناوين IP شبكية نشطة. يرجى التأكد من أن هذا الكمبيوتر متصل بالراوتر أو شبكة Wi-Fi محلية.
        </div>
      ) : (
        <div style={{ display: 'flex', gap: '1.5rem', flexWrap: 'wrap', marginTop: '0.5rem' }}>
          {/* قائمة عناوين الاتصال */}
          <div style={{ flex: '1.2 1 300px', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            {connectionOptions.map((opt, idx) => {
              const isActive = selectedIdx === idx
              return (
                <div 
                  key={idx} 
                  onClick={() => setSelectedIdx(idx)}
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '0.5rem',
                    padding: '1rem 1.25rem',
                    backgroundColor: isActive ? 'var(--surface)' : 'var(--bg)',
                    border: isActive ? '1px solid var(--primary)' : '1px solid var(--border)',
                    borderRadius: '12px',
                    cursor: 'pointer',
                    transition: 'all 0.2s ease',
                    boxShadow: isActive ? 'var(--shadow-md)' : 'none',
                    transform: isActive ? 'scale(1.01)' : 'none'
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <span 
                      style={{ 
                        fontSize: '0.72rem', 
                        fontWeight: 'bold', 
                        padding: '0.2rem 0.6rem', 
                        borderRadius: '20px',
                        backgroundColor: opt.type === 'electron-https' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(34, 197, 94, 0.15)',
                        color: opt.type === 'electron-https' ? 'var(--secondary, #3b82f6)' : 'var(--primary, #22c55e)',
                        border: `1px solid ${opt.type === 'electron-https' ? 'rgba(59, 130, 246, 0.25)' : 'rgba(34, 197, 94, 0.25)'}`
                      }}
                    >
                      {opt.label}
                    </span>
                    {isActive && (
                      <span style={{ fontSize: '0.7rem', color: 'var(--primary)', fontWeight: 'bold', marginRight: 'auto' }}>
                        النشط حالياً
                      </span>
                    )}
                  </div>

                  <div 
                    style={{ 
                      fontFamily: 'monospace', 
                      fontSize: '0.88rem', 
                      color: isActive ? 'var(--primary)' : 'var(--text)', 
                      wordBreak: 'break-all',
                      backgroundColor: isActive ? 'var(--bg)' : 'var(--surface)',
                      padding: '0.4rem 0.6rem',
                      borderRadius: '6px',
                      border: '1px dashed var(--border)',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      gap: '0.5rem'
                    }}
                    onClick={(e) => e.stopPropagation()} // Prevent card selection when clicking link container
                  >
                    <span>{opt.url}</span>
                    <button 
                      type="button" 
                      onClick={() => handleCopy(opt.url)}
                      style={{
                        background: 'none',
                        border: 'none',
                        color: copiedUrl === opt.url ? 'var(--primary)' : 'var(--text-muted)',
                        cursor: 'pointer',
                        padding: '0.2rem',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center'
                      }}
                      title="نسخ الرابط"
                    >
                      {copiedUrl === opt.url ? <Check size={16} /> : <Copy size={16} />}
                    </button>
                  </div>

                  {opt.note && (
                    <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: 0, lineHeight: '1.4' }}>
                      💡 {opt.note}
                    </p>
                  )}
                </div>
              )
            })}
          </div>

          {/* معاينة رمز الـ QR Code النشط */}
          {connectionOptions[selectedIdx] && (
            <div 
              style={{ 
                flex: '1 1 240px', 
                display: 'flex', 
                flexDirection: 'column', 
                gap: '0.75rem', 
                padding: '1.25rem', 
                backgroundColor: 'var(--bg)', 
                border: '1px solid var(--border)', 
                borderRadius: '12px',
                alignItems: 'center', 
                justifyContent: 'center',
                textAlign: 'center',
                minHeight: '260px'
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', color: 'var(--text)', fontWeight: 600, fontSize: '0.85rem' }}>
                <QrCode size={16} style={{ color: 'var(--primary)' }} />
                <span>رمز الاستجابة السريعة (QR Code)</span>
              </div>
              
              <div style={{ 
                display: 'flex', 
                flexDirection: 'column',
                alignItems: 'center', 
                justifyContent: 'center', 
                width: '140px', 
                height: '140px', 
                backgroundColor: '#ffffff', // Required for QR reader scan contrast
                border: '1px solid var(--border)', 
                borderRadius: '8px',
                overflow: 'hidden',
                position: 'relative',
                boxShadow: 'var(--shadow)'
              }}>
                <LocalQrCode
                  value={connectionOptions[selectedIdx].url}
                  size={120}
                  title="QR Code"
                />
              </div>
              
              <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: 0, lineHeight: '1.4', maxWidth: '200px' }}>
                وجه كاميرا الهاتف أو الجهاز اللوحي نحو الرمز لفتح العنوان مباشرة دون كتابته.
              </p>
            </div>
          )}
        </div>
      )}

      <div style={{ 
        display: 'flex', 
        alignItems: 'center', 
        gap: '0.5rem', 
        marginTop: '0.5rem',
        padding: '0.75rem 1rem',
        backgroundColor: 'var(--bg)',
        borderRadius: '8px',
        borderLeft: '4px solid var(--primary, #22c55e)'
      }}>
        <HelpCircle size={16} style={{ color: 'var(--primary)', flexShrink: 0 }} />
        <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
          تأكد من فتح شبكة Wi-Fi وجعلها <strong>شبكة خاصة (Private Network)</strong> في إعدادات Windows لتسمح للأجهزة الأخرى بالوصول للكمبيوتر.
        </span>
      </div>
    </section>
  )
}
