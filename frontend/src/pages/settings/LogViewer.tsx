import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Bug, CheckCircle2, Clock3, Copy, FileWarning, Info, RefreshCw, Server, X } from 'lucide-react'
import api from '../../api/axios'
import styles from './LogViewer.module.css'

interface LogEntry {
  id: string
  level: string
  message: string
  context: unknown
  created_at: string | null
  source?: 'client' | 'server' | string
}

interface LogPagination {
  page: number
  limit: number
  next_cursor: string | null
  has_more: boolean
}

interface LogResponse {
  data?: LogEntry[]
  pagination?: LogPagination
}

const LOG_PAGE_SIZE = 10

function formatLogValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value

  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

function levelClass(level: string): string {
  const normalized = level.toLowerCase()
  if (normalized.includes('critical') || normalized.includes('error')) return styles.levelDanger
  if (normalized.includes('warn')) return styles.levelWarning
  if (normalized.includes('debug')) return styles.levelDebug
  return styles.levelInfo
}

function LevelIcon({ level }: { level: string }) {
  const normalized = level.toLowerCase()
  if (normalized.includes('critical') || normalized.includes('error')) return <Bug size={15} aria-hidden="true" />
  if (normalized.includes('warn')) return <AlertTriangle size={15} aria-hidden="true" />
  if (normalized.includes('debug')) return <FileWarning size={15} aria-hidden="true" />
  return <Info size={15} aria-hidden="true" />
}

export default function LogViewer() {
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [level, setLevel] = useState('all')
  const [cursorStack, setCursorStack] = useState<Array<string | null>>([null])
  const [pagination, setPagination] = useState<LogPagination>({
    page: 1,
    limit: LOG_PAGE_SIZE,
    next_cursor: null,
    has_more: false,
  })
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selectedLog, setSelectedLog] = useState<LogEntry | null>(null)
  const [copyState, setCopyState] = useState(false)
  const cursor = cursorStack[cursorStack.length - 1]

  const loadLogs = useCallback((signal: AbortSignal) => {
    setLoading(true)
    return api.get<LogResponse>('/admin/error-logs', {
      params: { level, limit: LOG_PAGE_SIZE, ...(cursor ? { cursor } : {}) },
      signal,
    })
      .then(response => {
        // Keep the rendered page bounded even if an older backend or cached
        // response ignores the requested page size.
        setLogs((response.data.data ?? []).slice(0, LOG_PAGE_SIZE))
        setLoadError(null)
        setPagination(response.data.pagination ?? {
          page: cursorStack.length,
          limit: LOG_PAGE_SIZE,
          next_cursor: null,
          has_more: false,
        })
      })
      .catch(() => {
        if (!signal.aborted) {
          setLogs([])
          setLoadError('تعذر تحميل سجل الأخطاء. تحقق من الصلاحية واتصال الخادم.')
          setPagination(previous => ({ ...previous, next_cursor: null, has_more: false }))
        }
      })
      .finally(() => {
        if (!signal.aborted) setLoading(false)
      })
  }, [cursor, cursorStack.length, level])

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => { void loadLogs(controller.signal) }, 0)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [loadLogs])

  useEffect(() => {
    if (!selectedLog) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setSelectedLog(null)
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [selectedLog])

  const summary = useMemo(() => ({
    total: logs.length,
    critical: logs.filter(log => ['critical', 'error'].includes(log.level.toLowerCase())).length,
    warnings: logs.filter(log => log.level.toLowerCase().includes('warn')).length,
    server: logs.filter(log => log.source === 'server').length,
  }), [logs])

  const changeLevel = (nextLevel: string) => {
    setLevel(nextLevel)
    setCursorStack([null])
  }

  const refresh = () => {
    const controller = new AbortController()
    void loadLogs(controller.signal).finally(() => controller.abort())
  }

  const showNextPage = () => {
    if (pagination.next_cursor) setCursorStack(previous => [...previous, pagination.next_cursor])
  }

  const showPreviousPage = () => {
    setCursorStack(previous => previous.length > 1 ? previous.slice(0, -1) : previous)
  }

  const copyDetails = async () => {
    if (!selectedLog || !navigator.clipboard) return
    const details = [
      `Time: ${selectedLog.created_at ?? '—'}`,
      `Source: ${selectedLog.source ?? 'server'}`,
      `Level: ${selectedLog.level}`,
      `Message: ${selectedLog.message}`,
      `Context:\n${formatLogValue(selectedLog.context) || '{}'}`,
    ].join('\n')
    try {
      await navigator.clipboard.writeText(details)
      setCopyState(true)
      window.setTimeout(() => setCopyState(false), 1500)
    } catch {
      setCopyState(false)
    }
  }

  return (
    <div className={styles.shell}>
      <div className={styles.toolbar}>
        <div className={styles.titleGroup}>
          <div className={styles.titleLine}>
            <FileWarning size={22} aria-hidden="true" />
            <h2 className={styles.title}>سجل الأخطاء والأحداث</h2>
          </div>
          <p className={styles.subtitle}>سجل موحّد لأخطاء النظام والواجهة مع تفاصيل قابلة للبحث والمراجعة.</p>
        </div>
        <div className={styles.actions}>
          <label className={styles.filterLabel}>
            <span className={styles.srOnly}>تصفية حسب المستوى</span>
            <select className="input" value={level} onChange={event => changeLevel(event.target.value)} aria-label="تصفية حسب المستوى">
              <option value="all">الكل</option>
              <option value="critical">حرج</option>
              <option value="error">أخطاء</option>
              <option value="warning">تحذيرات</option>
              <option value="info">معلومات</option>
              <option value="debug">تشخيص</option>
            </select>
          </label>
          <button className="btn btn-secondary" type="button" onClick={refresh} disabled={loading} aria-label="تحديث سجل الأخطاء" data-testid="refresh-logs">
            <RefreshCw size={16} className={loading ? styles.spin : undefined} aria-hidden="true" />
            تحديث
          </button>
        </div>
      </div>

      <div className={styles.summaryGrid} aria-label="ملخص سجل الأخطاء">
        <div className={styles.summaryCard}><span className={styles.summaryIcon}><Clock3 size={17} /></span><span><strong className={styles.summaryValue}>{summary.total}</strong><small>إجمالي السجلات</small></span></div>
        <div className={styles.summaryCard}><span className={`${styles.summaryIcon} ${styles.dangerIcon}`}><Bug size={17} /></span><span><strong className={styles.summaryValue}>{summary.critical}</strong><small>أخطاء وحرجة</small></span></div>
        <div className={styles.summaryCard}><span className={`${styles.summaryIcon} ${styles.warningIcon}`}><AlertTriangle size={17} /></span><span><strong className={styles.summaryValue}>{summary.warnings}</strong><small>تحذيرات</small></span></div>
        <div className={styles.summaryCard}><span className={`${styles.summaryIcon} ${styles.serverIcon}`}><Server size={17} /></span><span><strong className={styles.summaryValue}>{summary.server}</strong><small>من الخادم</small></span></div>
      </div>

      <div className={styles.tableWrap} aria-live="polite" aria-busy={loading}>
        <table className={styles.table}>
          <thead>
            <tr><th>الوقت</th><th>المصدر</th><th>المستوى</th><th>الرسالة</th><th>السياق</th></tr>
          </thead>
          <tbody>
            {logs.map(log => {
              const message = formatLogValue(log.message)
              const context = formatLogValue(log.context)
              const source = log.source === 'client' ? 'الواجهة' : 'الخادم'
              return (
                <tr
                  key={log.id}
                  className={styles.clickable}
                  onClick={() => setSelectedLog(log)}
                  onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') setSelectedLog(log) }}
                  tabIndex={0}
                  role="button"
                  aria-label={`عرض تفاصيل الخطأ: ${message}`}
                >
                  <td className={styles.time}>{log.created_at ?? '—'}</td>
                  <td><span className={`${styles.sourceBadge} ${log.source === 'client' ? styles.clientSource : styles.serverSource}`}>{log.source === 'client' ? <CheckCircle2 size={13} /> : <Server size={13} />}{source}</span></td>
                  <td><span className={`${styles.levelBadge} ${levelClass(log.level)}`}><LevelIcon level={log.level} />{log.level}</span></td>
                  <td className={styles.message} title={message}>{message}</td>
                  <td className={styles.contextPreview} title={context}>{context || '—'}</td>
                </tr>
              )
            })}
            {logs.length === 0 && <tr><td colSpan={5} className={styles.empty}>{loading ? 'جارٍ تحميل السجل…' : loadError ?? 'لا توجد سجلات مطابقة'}</td></tr>}
          </tbody>
        </table>
      </div>

      <div className={styles.pagination}>
        <button className="btn btn-secondary" type="button" onClick={showPreviousPage} disabled={loading || cursorStack.length === 1} data-testid="previous-log-page">السابق</button>
        <span>الصفحة {pagination.page} · {LOG_PAGE_SIZE} سجلات</span>
        <button className="btn btn-secondary" type="button" onClick={showNextPage} disabled={loading || !pagination.has_more || !pagination.next_cursor} data-testid="next-log-page">التالي</button>
      </div>

      {selectedLog && (
        <div className={styles.modalOverlay} role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) setSelectedLog(null) }}>
          <section className={styles.detailModal} role="dialog" aria-modal="true" aria-labelledby="log-detail-title">
            <header className={styles.modalHeader}>
              <div><p className={styles.modalEyebrow}>تفاصيل السجل</p><h3 id="log-detail-title">{selectedLog.level} · {selectedLog.source === 'client' ? 'الواجهة' : 'الخادم'}</h3></div>
              <button type="button" className={styles.iconButton} onClick={() => setSelectedLog(null)} aria-label="إغلاق التفاصيل"><X size={19} /></button>
            </header>
            <div className={styles.modalBody}>
              <div className={styles.detailGrid}>
                <div className={styles.detailCard}><small>الوقت</small><span dir="ltr">{selectedLog.created_at ?? '—'}</span></div>
                <div className={styles.detailCard}><small>المصدر</small><span>{selectedLog.source === 'client' ? 'الواجهة' : 'الخادم'}</span></div>
                <div className={styles.detailCard}><small>المستوى</small><span className={`${styles.levelBadge} ${levelClass(selectedLog.level)}`}><LevelIcon level={selectedLog.level} />{selectedLog.level}</span></div>
                <div className={styles.detailCard}><small>معرّف السجل</small><span dir="ltr" title={selectedLog.id}>{selectedLog.id}</span></div>
              </div>
              <div className={styles.detailSection}><h4>الرسالة</h4><pre className={styles.pre}>{formatLogValue(selectedLog.message)}</pre></div>
              <div className={styles.detailSection}><h4>السياق الكامل</h4><pre className={styles.pre}>{formatLogValue(selectedLog.context) || '{}'}</pre></div>
            </div>
            <footer className={styles.modalFooter}>
              <button type="button" className="btn btn-secondary" onClick={() => void copyDetails()} disabled={!navigator.clipboard}><Copy size={15} />{copyState ? 'تم النسخ' : 'نسخ التفاصيل'}</button>
              <button type="button" className="btn btn-primary" onClick={() => setSelectedLog(null)}>إغلاق</button>
            </footer>
          </section>
        </div>
      )}
    </div>
  )
}
