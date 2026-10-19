import React, { useEffect } from 'react'
import ReactDOM from 'react-dom/client'
import './store/themeStore'
import App from './App'
import './i18n'
import './styles/_index.css'
import { initOfflineSync } from './utils/offlineSync.js'
import { logError, flushNow } from './utils/clientLogger'
import useAuthStore from './store/authStore'

export function OfflineSyncManager() {
  const hasHydrated = useAuthStore((state) => state._hasHydrated)
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const user = useAuthStore((state) => state.user)

  useEffect(() => {
    if (
      !hasHydrated
      || !isAuthenticated
      || !user
      || user.id <= 0
      || !Number.isInteger(user.branch_id)
      || user.branch_id <= 0
    ) {
      return
    }

    const owner = { ownerUserId: user.id, branchId: user.branch_id }
    return initOfflineSync(owner, () => {
      const current = useAuthStore.getState()
      return current.isAuthenticated
        && current.user?.id === owner.ownerUserId
        && current.user.branch_id === owner.branchId
    })
  }, [hasHydrated, isAuthenticated, user])

  return null
}

// ── تسجيل أخطاء JavaScript غير المعالجة ────────────────────────
window.addEventListener('error', (event) => {
  const isAppProtocol = window.location.protocol === 'app:'
  const msg = event.message || ''
  if (isAppProtocol && (msg.includes('ServiceWorker') || msg.includes('registerSW.js'))) {
    return
  }
  logError(msg || 'Unhandled error', {
    stack: event.error?.stack,
    url: event.filename,
    extra: `line: ${event.lineno}, col: ${event.colno}`,
  })
})

// ── تسجيل Promise rejections غير المعالجة ─────────────────────
window.addEventListener('unhandledrejection', (event) => {
  const isAppProtocol = window.location.protocol === 'app:'
  const reason = event.reason
  const msg = reason?.message || ''
  if (isAppProtocol && (msg.includes('ServiceWorker') || msg.includes('registerSW.js'))) {
    return
  }
  logError(msg || 'Unhandled promise rejection', {
    stack: reason?.stack,
  })
})

// ── إرسال اللوج قبل إغلاق الصفحة ─────────────────────────────
window.addEventListener('beforeunload', () => {
  flushNow()
})

// ── تسجيل الـ Service Worker للمتصفحات (وليس في Electron) ──
const isAppProtocol = window.location.protocol === 'app:';
if (!isAppProtocol && 'serviceWorker' in navigator) {
  import('virtual:pwa-register').then(({ registerSW }) => {
    registerSW({
      immediate: true,
      onOfflineReady() {
        console.log('[SW] App ready to work offline');
      },
      onRegisterError(error: unknown) {
        console.error('[SW] Service Worker registration failed:', error);
      }
    });
  }).catch((err) => {
    console.error('[SW] Failed to load PWA register module:', err);
  });
}

// منع تغيير الأرقام في حقول الإدخال عبر تحريك بكرة الماوس (scroll)
document.addEventListener('wheel', (event) => {
  const activeElement = document.activeElement as HTMLInputElement | null;
  if (activeElement?.type === 'number') {
    // Prevent accidental value changes without stealing focus from the field.
    event.preventDefault()
  }
}, { passive: false })

async function initApp() {
  if (typeof window.posRuntime?.getApiBaseUrl === 'function') {
    try {
      const url = await window.posRuntime.getApiBaseUrl()
      if (url) {
        window.API_BASE_URL = url
        console.log('[Init] Dynamic API base URL resolved:', url)
      }
    } catch (err) {
      console.warn('[Init] Failed to resolve dynamic API base URL:', err)
    }
  }

  ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
      <OfflineSyncManager />
      <App />
    </React.StrictMode>
  )
}

initApp();
