import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import { lazy, Suspense, useEffect } from 'react'
import Layout from './components/layout/Layout'
import ErrorBoundary from './components/ui/ErrorBoundary'
import useAuthStore from './store/authStore'
import useSettingsStore from './store/settingsStore'
import useThemeStore from './store/themeStore'
import ConfirmModal from './components/common/ConfirmModal'
import ForcePasswordChangeModal from './components/common/ForcePasswordChangeModal'
import { useInventorySSE } from './hooks/useInventorySSE'
import { ConflictResolutionDialog } from './components/ConflictResolutionDialog'
import { useConfirmStore } from './store/confirmStore'

/**
 * Retry wrapper for lazy imports — handles transient network/SSL failures.
 * Retries up to `maxRetries` times with exponential delay before reloading the page.
 * Uses a counter to prevent infinite reload loops (max 1 reload per session).
 */
function lazyRetry(importFn: () => Promise<{ default: React.ComponentType<unknown> }>, maxRetries = 2) {
  return lazy(() => {
    const attempt = (retriesLeft: number): Promise<{ default: React.ComponentType<unknown> }> =>
      importFn().catch((err: unknown) => {
        if (retriesLeft > 0) {
          return new Promise((resolve) => setTimeout(resolve, 500)).then(() =>
            attempt(retriesLeft - 1)
          )
        }
        // All retries failed — force a full page reload (clears stale chunks)
        // but only once to prevent infinite loops
        const reloadCount = parseInt(sessionStorage.getItem('chunk_reload_count') || '0', 10)
        if (reloadCount < 1) {
          sessionStorage.setItem('chunk_reload_count', String(reloadCount + 1))
          window.location.reload()
        }
        throw err
      })
    return attempt(maxRetries)
  })
}

// Code splitting via lazy imports with retry
const Login     = lazyRetry(() => import('./pages/Login'))
const POS       = lazyRetry(() => import('./pages/Pos'))
const Products  = lazyRetry(() => import('./pages/Products'))
const Suppliers = lazyRetry(() => import('./pages/Suppliers'))
const Reports   = lazyRetry(() => import('./pages/Reports'))
const Users     = lazyRetry(() => import('./pages/Users'))
const Sales     = lazyRetry(() => import('./pages/Sales'))
const Settings  = lazyRetry(() => import('./pages/Settings'))
const Customers = lazyRetry(() => import('./pages/Customers'))
const Expenses  = lazyRetry(() => import('./pages/Expenses'))
const Branches  = lazyRetry(() => import('./pages/Branches'))

function PageLoader() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100vh' }}>
      <span className="spinner" style={{ width: '2rem', height: '2rem', borderWidth: '3px' }} />
    </div>
  )
}

function PrivateRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, user, _hasHydrated } = useAuthStore()
  if (!_hasHydrated) return <PageLoader />
  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (user?.force_password_change === 1) return <PageLoader />
  return children
}

function AdminRoute({ children }: { children: React.ReactNode }) {
  const { user, _hasHydrated } = useAuthStore()
  if (!_hasHydrated) return <PageLoader />
  if (user?.force_password_change === 1) return <PageLoader />
  return user?.role === 'admin' ? children : <Navigate to="/" replace />
}

function SettingsLoader() {
  const { isAuthenticated, user } = useAuthStore()
  const fetchSettings = useSettingsStore(s => s.fetchSettings)
  useEffect(() => { 
    if (isAuthenticated && user?.force_password_change !== 1) fetchSettings() 
  }, [isAuthenticated, user?.force_password_change, fetchSettings])
  return null
}

function SSELoader() {
  const { isAuthenticated } = useAuthStore()
  useInventorySSE(isAuthenticated)
  return null
}

/**
 * Global dialogs must not survive the session boundary. Otherwise a dialog
 * opened on a protected page can leave a full-screen hit target over /login
 * after logout, making the login fields appear to ignore mouse input.
 */
function SessionInteractionReset() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)

  useEffect(() => {
    if (isAuthenticated) return

    useConfirmStore.getState().reset()
    const activeElement = document.activeElement
    if (activeElement instanceof HTMLElement) activeElement.blur()
  }, [isAuthenticated])

  return null
}

function SessionHydrator() {
  const { isAuthenticated, _hasHydrated, sessionRefreshRequired, refreshSession } = useAuthStore()

  useEffect(() => {
    if (!_hasHydrated || !isAuthenticated || !sessionRefreshRequired) return
    void refreshSession()
  }, [_hasHydrated, isAuthenticated, sessionRefreshRequired, refreshSession])

  return null
}

function AppShell() {
  const themeMode = useThemeStore((s) => s.mode)
  const toastStyle = {
    fontFamily: 'Tajawal, sans-serif',
    fontSize: '0.9rem',
    direction: 'rtl' as const,
    ...(themeMode === 'dark'
      ? { background: '#1f2937', color: '#f3f4f6', border: '1px solid #374151' }
      : {}),
  }

  return (
    <BrowserRouter>
      <ForcePasswordChangeModal />
      <ConfirmModal />
      <Toaster position="top-center" toastOptions={{ style: toastStyle }} />
      <ConflictResolutionDialog />
      <SessionInteractionReset />
      <SessionHydrator />
      <SettingsLoader />
      <SSELoader />
      <Suspense fallback={<PageLoader />}>
        <Routes>
          <Route path="/login" element={<Login />} />

          <Route path="/" element={
            <PrivateRoute>
              <Layout>
                <POS />
              </Layout>
            </PrivateRoute>
          } />

          <Route path="/products" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Products /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/suppliers" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Suppliers /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/reports" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Reports /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/expenses" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Expenses /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/users" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Users /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/sales" element={
            <PrivateRoute>
              <Layout><Sales /></Layout>
            </PrivateRoute>
          } />

          <Route path="/settings" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Settings /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/customers" element={
            <PrivateRoute>
              <Layout><Customers /></Layout>
            </PrivateRoute>
          } />

          <Route path="/branches" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Branches /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <AppShell />
    </ErrorBoundary>
  )
}
