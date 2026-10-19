import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { login as loginApi, logout as logoutApi, getCsrfCookie, getMe } from '../api/endpoints'
import { setCsrfSignature } from '../api/axios'

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  branch_id: number;
  force_password_change?: number;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  _hasHydrated: boolean;
  sessionRefreshRequired: boolean;
  setHasHydrated: (val: boolean) => void;
  setSessionRefreshRequired: (val: boolean) => void;
  login: (email: string, password: string) => Promise<User>;
  refreshSession: () => Promise<void>;
  logout: () => Promise<void>;
  requireReauthentication: () => Promise<void>;
  setUser: (user: User | null) => void;
}

async function clearOfflineCache(): Promise<void> {
  try {
    const { clearAllCache } = await import('../utils/idb')
    await clearAllCache()
  } catch (error) {
    console.error('Failed to clear IDB cache on logout', error)
  }
}

const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      _hasHydrated: false,
      sessionRefreshRequired: false,

      setHasHydrated: (val) => set({ _hasHydrated: val }),
      setSessionRefreshRequired: (val) => set({ sessionRefreshRequired: val }),

      login: async (email, password) => {
        try {
          const csrfRes = await getCsrfCookie()
          const sig = csrfRes?.data?.data?.csrf_token ?? null
          setCsrfSignature(sig)
        } catch(e) {}
        const res = await loginApi({ email, password })
        const { user } = res.data.data as unknown as { user: User }
        set({ user, token: null, isAuthenticated: true, sessionRefreshRequired: false })
        return user
      },

      refreshSession: async () => {
        try {
          const res = await getMe()
          const user = res.data.data as unknown as User
          if (user) set({ user, isAuthenticated: true, sessionRefreshRequired: false })
        } catch (error: unknown) {
          const response = (error as {
            response?: {
              status?: number;
              data?: { errors?: Record<string, unknown> };
            };
          }).response
          const forcePasswordChange = response?.data?.errors?.force_password_change

          if (response?.status === 403 && forcePasswordChange) {
            set((state) => ({
              user: state.user ? { ...state.user, force_password_change: 1 } : null,
              sessionRefreshRequired: false,
            }))
            return
          }

          if (response?.status === 401) {
            await clearOfflineCache()
            set({ user: null, token: null, isAuthenticated: false, sessionRefreshRequired: false })
            return
          }

          // Preserve the cached session on transient startup failures. The
          // next authenticated request can still reconcile it through the
          // normal API interceptor.
          console.warn('Failed to refresh authenticated session', error)
          set({ sessionRefreshRequired: false })
        }
      },

      logout: async () => {
        try { await logoutApi() } catch (err) { }
        try {
          await window.electronAPI?.auth?.clearSession?.()
        } catch (error) {
          console.warn('Failed to clear desktop session after logout', error)
        }
        await clearOfflineCache()
        set({ user: null, token: null, isAuthenticated: false, sessionRefreshRequired: false })
      },

      requireReauthentication: async () => {
        await clearOfflineCache()
        set({ user: null, token: null, isAuthenticated: false, sessionRefreshRequired: false })
        window.location.assign('/login')
      },

      setUser: (user) => set({ user }),
    }),
    {
      name: 'pos_auth',
      partialize: (s) => ({ user: s.user, isAuthenticated: s.isAuthenticated }),
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true)
        state?.setSessionRefreshRequired(state.isAuthenticated)
      },
    }
  )
)

export default useAuthStore
