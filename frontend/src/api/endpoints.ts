import api from './axios'
import type { AxiosRequestConfig } from 'axios'
import { DEFAULT_PAGE, DEFAULT_PAGE_SIZE } from './constants'

// Auth
export const getCsrfCookie = () => api.get('/csrf-cookie')
export const login = (data: Record<string, string>) => api.post<AuthResponse>('/login', data)
export const refreshToken = () => api.post('/refresh')
export const logout = () => api.post('/logout')
export const getMe = () => api.get<ApiResponse<User>>('/user')

export const getChangelog = () => api.get('/update/changelog')

// Categories
export const getCategories = (params?: ApiQueryParams) => api.get<ApiResponse<Category[]>>('/categories', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const createCategory = (data: Partial<Category>) => api.post<ApiResponse<Category>>('/categories', data)
export const updateCategory = (id: number | string, data: Partial<Category>) => api.put<ApiResponse<Category>>(`/categories/${id}`, data)
export const deleteCategory = (id: number | string) => api.delete<ApiResponse<null>>(`/categories/${id}`)

// Products
export const getProducts = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/products', { params })
export async function getProductCatalogPage(
  checkpoint?: string,
  pageSize = 500,
): Promise<ProductCatalogPage> {
  const limit = Math.max(1, Math.min(500, Math.trunc(pageSize)))
  const response = await api.get<ApiResponse<Product[]>>('/products/sync', {
    params: { checkpoint, limit },
    hideGlobalError: true,
  } as AxiosRequestConfig & { hideGlobalError?: boolean })
  const pagination = response.data.pagination
  if (
    !response.data.catalog_scope
    || response.data.catalog_version === undefined
    || pagination?.type !== 'cursor'
    || !pagination.mode
    || !pagination.next_checkpoint
  ) {
    throw new Error('Invalid product catalog sync response')
  }

  return {
    products: Array.isArray(response.data.data) ? response.data.data : [],
    scope: response.data.catalog_scope,
    version: response.data.catalog_version,
    pagination: {
      type: 'cursor',
      mode: pagination.mode,
      limit: pagination.limit,
      hasMore: pagination.has_more === true,
      truncated: pagination.truncated === true,
      reset: pagination.reset === true,
      nextCheckpoint: pagination.next_checkpoint,
    },
  }
}
export const getProduct = (id: number | string) => api.get<ApiResponse<Product>>(`/products/${id}`)
export const getProductByBarcode = (barcode: string) =>
  api.get<ApiResponse<Product>>('/products/barcode', { params: { barcode } })
export const createProduct = (data: Partial<Product>) => api.post<ApiResponse<Product>>('/products', data)
export const updateProduct = (id: number | string, data: Partial<Product>) => api.put<ApiResponse<Product>>(`/products/${id}`, data)
export const deleteProduct = (id: number | string) => api.delete<ApiResponse<null>>(`/products/${id}`)

// Sales
export const createSale = (data: SaleCreatePayload) => api.post<ApiResponse<Sale>>('/sales', data)
export const getSales = (params?: ApiQueryParams) => api.get<ApiResponse<Sale[]>>('/sales', { params })
export const getSale = (id: number | string) => api.get<ApiResponse<Sale>>(`/sales/${id}`)
export const updateSaleStatus = (id: number | string, data: { status: string }) => api.put<ApiResponse<null>>(`/sales/${id}/status`, data)
export const deleteSale = (id: number | string) => api.delete<ApiResponse<null>>(`/sales/${id}`)

// Inventory
export const getInventory = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/inventory', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getLowStock = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/inventory/low-stock', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const adjustInventory = (id: number | string, data: { type: 'add' | 'subtract' | 'set'; quantity: number }) => api.put<ApiResponse<Product>>(`/inventory/${id}`, data)

// Suppliers
export const getSuppliers = (params?: ApiQueryParams, config?: AxiosRequestConfig) => api.get<ApiResponse<Supplier[]>>('/suppliers', { ...config, params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getSupplier = (id: number | string, config?: AxiosRequestConfig) => api.get<ApiResponse<SupplierLedgerData>>(`/suppliers/${id}`, config)
export const searchSuppliers = async (search: string, signal?: AbortSignal): Promise<Supplier[]> => {
  const response = await getSuppliers({ page: 1, limit: 20, search: search || undefined }, { signal })
  return Array.isArray(response.data.data) ? response.data.data : []
}
export const getSupplierOption = async (id: number, signal?: AbortSignal): Promise<Supplier | null> => {
  const response = await getSupplier(id, { signal })
  return response.data.data?.supplier ?? null
}
export const createSupplier = (data: Partial<Supplier>) => api.post<ApiResponse<Supplier>>('/suppliers', data)
export const updateSupplier = (id: number | string, data: Partial<Supplier>) => api.put<ApiResponse<Supplier>>(`/suppliers/${id}`, data)
export const deleteSupplier = (id: number | string) => api.delete<ApiResponse<null>>(`/suppliers/${id}`)
export const createPurchase = (data: BulkPurchasePayload) => api.post<ApiResponse<PurchaseInvoice>>('/purchases', data)
export const getPurchases = (params?: ApiQueryParams) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchases', { params })

// Reports
export const getDailyReport = (params?: ApiQueryParams) => api.get<ApiResponse<DailyReport>>('/reports/daily', { params })
export const getMonthlyReport = (params?: ApiQueryParams) => api.get<ApiResponse<MonthlySummary[]>>('/reports/monthly', { params })
export const getTopProducts = (params?: ApiQueryParams) => api.get<ApiResponse<TopProduct[]>>('/reports/products', { params })
export const getReportSummary = () => api.get<ApiResponse<ReportSummary>>('/reports/summary')

// Users
export const getUsers = (params?: ApiQueryParams) => api.get<ApiResponse<User[]>>('/users', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const createUser = (data: Partial<User>) => api.post<ApiResponse<User>>('/users', data)
export const updateUser = (id: number | string, data: UserUpdatePayload) => api.put<ApiResponse<User>>(`/users/${id}`, data)
export const deleteUser = (id: number | string) => api.delete<ApiResponse<null>>(`/users/${id}`)

// Settings
export const getSettings = () => api.get<ApiResponse<AppSettings>>('/settings')
export const updateSettings = (data: Partial<AppSettings>) => api.post<ApiResponse<AppSettings>>('/settings', data)

// Updates
type UpdateRequestConfig = AxiosRequestConfig & { hideGlobalError?: boolean }
export const getUpdateStatus = (deltaCapable = false) => api.get<ApiResponse<UpdateStatusData>>('/updates/status', {
  headers: { 'X-POS-Delta-Handoff': deltaCapable ? '1' : '0' },
})
export const checkUpdate = (deltaCapable = false, config?: UpdateRequestConfig) => api.get<ApiResponse<UpdateCheckResult>>('/updates/check', {
  ...config,
  headers: { ...config?.headers, 'X-POS-Delta-Handoff': deltaCapable ? '1' : '0' },
})
export const applyUpdate = (force = false, deltaCapable = false) => api.post<ApiResponse<UpdateApplyResult>>('/updates/apply', {
  ...(force ? { force: true } : {}),
  delta_capable: deltaCapable,
}, {
  timeout: 30_000,
  headers: { 'X-POS-Delta-Handoff': deltaCapable ? '1' : '0' },
})
export const getUpdateHistory = () => api.get<ApiResponse<UpdateHistoryRecord[]>>('/updates/history')
export const rollbackUpdate = (snapshotPath?: string) => api.post<ApiResponse<UpdateRollbackResult>>('/updates/rollback', snapshotPath ? { snapshot_path: snapshotPath } : null)
export const getUpdateSnapshots = () => api.get<ApiResponse<UpdateSnapshot[]>>('/updates/snapshots')
export const getUpdateJob = (id: number) => api.get<ApiResponse<UpdateJobResult>>(`/update/jobs/${id}`, { timeout: 15_000 })

export const getUpdateChannel = () => api.get<ApiResponse<{ channel: string; available_channels: string[]; device_id: string }>>('/updates/channel')
export const setUpdateChannel = (channel: string) => api.post<ApiResponse<{ ok: boolean; channel: string }>>('/updates/channel', { channel })

export interface CustomerUpdateStatusData {
  current_version: string
  available_version: string | null
  update_available: boolean
  update_type: 'bootstrap_installer' | 'delta_update'
  size: number
  release_notes: string
  mandatory: boolean
  installer_name?: string | null
}

export const getCustomerUpdateStatus = () => api.get<ApiResponse<CustomerUpdateStatusData>>('/updates/customer-status')
export const sendCustomerUpdateResult = (data: Record<string, unknown>) => api.post<ApiResponse<{ recorded: boolean; event: string }>>('/updates/customer-result', data)

// Fleet & Telemetry
export const getFleetStats = () => api.get<ApiResponse<FleetStatsData>>('/admin/fleet/stats')
export const getFleetDevices = (params?: { limit?: number; offset?: number; search?: string }) => api.get<ApiResponse<{ devices: FleetDeviceRecord[]; total: number; limit: number; offset: number }>>('/admin/fleet/devices', { params })
export const getDeviceDetails = (id: string) => api.get<ApiResponse<DeviceDetailsData>>(`/admin/fleet/devices/${encodeURIComponent(id)}`)
export const purgeTelemetry = (days = 90) => api.post<ApiResponse<{ deleted_count: number; retention_days: number }>>('/admin/fleet/purge', { days })
export const sendTelemetryEvent = (data: Record<string, unknown>) => api.post<ApiResponse<{ recorded: boolean }>>('/telemetry/updates', data)

// Self-Healing & Recovery
export const diagnoseUpdateRecovery = () => api.get<ApiResponse<RecoveryDiagnosisData>>('/admin/updates/recovery/diagnose')
export const executeRecoveryAction = (action: string, snapshotPath?: string) => api.post<ApiResponse<RecoveryActionResult>>('/admin/updates/recovery/execute', { action, snapshot_path: snapshotPath })
export const getRecoveryAuditLogs = (limit = 50) => api.get<ApiResponse<{ logs: RecoveryAuditEntry[]; total: number }>>('/admin/updates/recovery/audit', { params: { limit } })
export const runPostUpdateHealthCheck = (snapshotPath?: string) => api.post<ApiResponse<RecoveryHealthCheckResult>>('/admin/updates/recovery/health-check', { snapshot_path: snapshotPath })




// Backup
export const downloadBackup = () => api.get('/backup', { responseType: 'blob' })
/**
 * Restore through the trusted Electron main process. The browser endpoint is
 * intentionally disabled because a restore must run as a CLI operation while
 * PHP is stopped; this also prevents accidental remote database replacement.
 */
export const restoreBackup = async (): Promise<{
  success: boolean
  cancelled?: boolean
  error?: string
}> => {
  const restore = window.electronAPI?.backup?.restore
  if (typeof restore !== 'function') {
    throw new Error('Database restore is available in the desktop application only.')
  }
  const result = await restore()
  if (!result.success && !result.cancelled) {
    throw new Error(result.error || 'Database restore failed.')
  }
  return result
}

export const recoverPassword = async (payload: { email: string; password: string }) => {
  const recover = window.electronAPI?.auth?.recoverPassword
  if (typeof recover !== 'function') {
    throw new Error('Password recovery is available on the local desktop only.')
  }
  const result = await recover(payload)
  if (!result.success) {
    throw new Error(result.error || 'Password recovery failed.')
  }
  return result
}

// Purchases (bulk)
export const createBulkPurchase = (data: BulkPurchasePayload) => api.post<ApiResponse<PurchaseInvoice>>('/purchases/bulk', data)

// Purchase Invoices
export const getPurchaseInvoices = (params?: ApiQueryParams) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchase-invoices', { params })
export const getPurchaseInvoice  = (id: number | string)     => api.get<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}`)
export const deletePurchaseInvoice = (id: number | string)   => api.delete<ApiResponse<null>>(`/purchase-invoices/${id}`)
export const updateSupplierLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<LedgerEntry[]>>(`/suppliers/ledger/${entryId}`, data)
export const deleteSupplierLedgerEntry = (entryId: number | string) => api.delete<ApiResponse<LedgerEntry[]>>(`/suppliers/ledger/${entryId}`)
export const addSupplierPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<LedgerEntry[]>>(`/suppliers/${id}/payment`, data)

// Reports (profit)
export const getProfitReport = (params?: ApiQueryParams) => api.get<ApiResponse<ProfitReport>>('/reports/profit', { params })

// Customers
export const getCustomers    = (params?: ApiQueryParams, config?: AxiosRequestConfig) => api.get<ApiResponse<Customer[]>>('/customers', { ...config, params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getCustomer     = (id: number | string, config?: AxiosRequestConfig) => api.get<ApiResponse<CustomerLedgerData>>(`/customers/${id}`, config)
export const searchCustomers = async (search: string, signal?: AbortSignal): Promise<Customer[]> => {
  const response = await getCustomers({ page: 1, limit: 20, search: search || undefined }, { signal })
  return Array.isArray(response.data.data) ? response.data.data : []
}
export const getCustomerOption = async (id: number, signal?: AbortSignal): Promise<Customer | null> => {
  const response = await getCustomer(id, { signal })
  return response.data.data?.customer ?? null
}
export const createCustomer  = (data: Partial<Customer>)       => api.post<ApiResponse<Customer>>('/customers', data)
export const updateCustomer  = (id: number | string, data: Partial<Customer>)   => api.put<ApiResponse<Customer>>(`/customers/${id}`, data)
export const deleteCustomer  = (id: number | string)         => api.delete<ApiResponse<null>>(`/customers/${id}`)
export const addCustomerPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<CustomerLedgerData>>(`/customers/${id}/payment`, data)
export const updateCustomerLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<CustomerLedgerData>>(`/customers/ledger/${entryId}`, data)
export const deleteCustomerLedgerEntry = (entryId: number | string) => api.delete<ApiResponse<CustomerLedgerData>>(`/customers/ledger/${entryId}`)

// Health Check
export const getHealthCheck = () => api.get('/health')
export interface HealthDiagnostics {
  status: 'ok' | 'degraded' | 'failed'
  critical_failed: boolean
  version: string
  checks: {
    database: { status: string; latency_ms?: number | null }
    disk: { status: string; free_gb: number; total_gb: number; used_percent: number }
    memory: { status: string; usage_mb: number; peak_mb: number; limit: string }
    php: { status: string; version: string; extensions: Record<string, boolean> }
  }
}
export const getHealthDiagnostics = () => api.get<HealthDiagnostics>('/health/diagnostics')
export const getHealthMetrics = () => api.get('/health/metrics')
export const getNetworkInfo = () => api.get<ApiResponse<{ ips: string[]; port: string; protocol: string }>>('/system/network-info')
