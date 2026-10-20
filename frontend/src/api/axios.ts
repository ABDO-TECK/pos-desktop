import axios, { InternalAxiosRequestConfig, AxiosResponse, AxiosError } from 'axios'
import toast from 'react-hot-toast'
import { shouldSuppressUpdateConnectionError } from '../utils/updateRestart'

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
})

// CSRF HMAC signature storage — set by authStore after calling /csrf-cookie
let csrfSignature: string | null = null
let csrfRequest: Promise<string> | null = null
export function setCsrfSignature(sig: string | null) { csrfSignature = sig }
export function getCsrfSignature(): string | null { return csrfSignature }

async function ensureCsrfSignature(): Promise<string> {
  if (csrfSignature) return csrfSignature
  if (!csrfRequest) {
    csrfRequest = api.get('/csrf-cookie').then((response) => {
      const signature: unknown = response.data?.data?.csrf_token
      if (typeof signature !== 'string' || !/^[a-f0-9]{64}$/i.test(signature)) {
        throw new Error('Unable to initialize request protection')
      }
      csrfSignature = signature
      return signature
    }).finally(() => { csrfRequest = null })
  }
  return csrfRequest
}

api.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  // Restored desktop sessions retain cookies, but this in-memory signature
  // starts empty after a reload. Fetch a fresh pair before protected writes.
  const method = (config.method ?? 'get').toLowerCase()
  if (!['get', 'head', 'options'].includes(method) && config.url !== '/login') {
    await ensureCsrfSignature()
  }
  // Attach CSRF HMAC signature in the X-XSRF-TOKEN header
  // The server verifies: HMAC(cookie_nonce, server_secret) === this header value
  const sig = getCsrfSignature()
  if (sig && config.headers) {
    config.headers['X-XSRF-TOKEN'] = sig
  }

  // Prepend dynamic API base URL if available in Electron runtime
  const dynamicBase = window.API_BASE_URL
  if (dynamicBase) {
    config.baseURL = dynamicBase.replace(/\/+$/, '') + '/api/v1'
  }

  return config
})

let isRefreshing = false
let failedQueue: Array<{ resolve: (v: unknown) => void; reject: (e: unknown) => void }> = []

function processQueue(error: unknown) {
  failedQueue.forEach(p => error ? p.reject(error) : p.resolve(undefined))
  failedQueue = []
}

api.interceptors.response.use(
  (res: AxiosResponse) => res,
  async (err: AxiosError) => {
    const originalRequest = err.config as InternalAxiosRequestConfig & { _retry?: boolean }

    if (shouldSuppressUpdateConnectionError(err)) return Promise.reject(err)

    // If the request was for update check, completely skip refresh, auth redirect, localStorage clear, and reload
    const isUpdateCheck = originalRequest?.url?.includes('/update/check') || err.config?.url?.includes('/update/check');

    // Try refresh on 401 (except login, refresh, client-log, and update-check endpoints)
    if (err.response?.status === 401
        && !isUpdateCheck
        && !originalRequest?.url?.includes('/login')
        && !originalRequest?.url?.includes('/refresh')
        && !originalRequest?.url?.includes('/client-log')
        && !originalRequest._retry) {
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({ resolve, reject })
        }).then(() => api(originalRequest))
      }

      originalRequest._retry = true
      isRefreshing = true

      try {
        await api.post('/refresh')
        processQueue(null)
        return api(originalRequest)
      } catch (refreshErr) {
        processQueue(refreshErr)
        localStorage.removeItem('pos_auth')
        window.location.href = '/login'
        return Promise.reject(refreshErr)
      } finally {
        isRefreshing = false
      }
    }

    // Handle 403 force_password_change — update the auth store silently
    const data = err.response?.data as Record<string, unknown> | undefined;
    const errors = data?.errors as Record<string, unknown> | undefined;
    if (err.response?.status === 403 && errors?.force_password_change && !isUpdateCheck) {
      try {
        const raw = localStorage.getItem('pos_auth')
        if (raw) {
          const parsed = JSON.parse(raw)
          if (parsed?.state?.user) {
            parsed.state.user.force_password_change = 1
            localStorage.setItem('pos_auth', JSON.stringify(parsed))
            // Reload only once to pick up the new store state and show the modal
            if (!sessionStorage.getItem('fpc_reload')) {
              sessionStorage.setItem('fpc_reload', '1')
              window.location.reload()
            }
          }
        }
      } catch (_ignored) { }
      return Promise.reject(err)
    }

    // Skip global toast if explicitly requested via custom config or if it is an update check
    const isGlobalErrorHidden = err.config && (err.config as unknown as Record<string, unknown>).hideGlobalError;
    if (isGlobalErrorHidden || isUpdateCheck) {
      return Promise.reject(err)
    }

    // Extract error message
    const errData = err.response?.data as Record<string, unknown> | undefined;
    const message = (errData?.message as string) || (errData?.error as string) || 'حدث خطأ في الاتصال بالخادم';

    // Handle Validation Errors (422) specifically
    if (err.response?.status === 422 && data?.errors) {
      const firstError = Object.values(data.errors)[0] as string[];
      toast.error(firstError?.[0] || 'بيانات غير صحيحة');
    } else if (err.response?.status !== 401 && err.code !== 'ERR_CANCELED') {
      toast.error(message);
    }

    return Promise.reject(err)
  }
)

export default api
