import { create } from 'zustand'
import { checkUpdate } from '../api/endpoints'

async function hasDeltaHandoffCapability(): Promise<boolean> {
  try {
    return Boolean((await window.posRuntime?.getDeltaCapability?.())?.capable)
  } catch {
    return false
  }
}

async function getInstalledElectronVersion(): Promise<string | null> {
  try {
    if (!window.electronAPI?.getVersion) return null
    return (await window.electronAPI.getVersion()) || null
  } catch {
    return null
  }
}

export function isValidSettledResult(data: unknown): data is UpdateCheckResult {
  if (!data || typeof data !== 'object') return false
  const d = data as Record<string, unknown>
  // Reject pending checks
  if (d.pending === true || d.status === 'check_pending') return false
  if (typeof d.status !== 'string' || !d.status) return false
  if (typeof d.has_update !== 'boolean') return false
  if (typeof d.current_version !== 'string' || !d.current_version) return false
  if (!Array.isArray(d.changelog)) return false
  if (d.has_update) {
    if (typeof d.latest_version !== 'string' || !d.latest_version) return false
  } else {
    if (d.latest_version !== null && d.latest_version !== undefined && typeof d.latest_version !== 'string') return false
  }
  if (d.updates_disabled !== undefined && typeof d.updates_disabled !== 'boolean') return false
  if (d.updates_unreachable !== undefined && typeof d.updates_unreachable !== 'boolean') return false
  return true
}

interface UpdateState {
  hasUpdate: boolean;
  latestVersion: string | null;
  currentVersion: string | null;
  changelog: UpdateCheckResult['changelog'];
  lastChecked: number | null;
  isChecking: boolean;
  isPending?: boolean;
  updatesDisabled: boolean;
  updatesDisabledMessage: string;
  updatesUnreachable: boolean;
  updateErrorMessage: string;
  silentCheck: () => Promise<void>;
  forceCheck: () => Promise<UpdateCheckResult | undefined>;
}

let activeSilentPromise: Promise<void> | null = null
let activeManualPromise: Promise<UpdateCheckResult | undefined> | null = null

let globalGeneration = 0
let latestManualGeneration = 0

let lastSuccessCheckTime = 0
let lastFailureOrPendingTime = 0

const SUCCESS_COOLDOWN_MS = 6 * 60 * 60 * 1000 // 6 hours
const RETRY_COOLDOWN_MS = 60 * 1000            // 60 seconds failure/pending retry cooldown
const MAX_POLL_DURATION_MS = 30_000            // <= 30 seconds
const POLL_INTERVAL_MS = 2_000                 // 2 seconds

const useUpdateStore = create<UpdateState>((set, get) => ({
  hasUpdate: false,
  latestVersion: null,
  currentVersion: null,
  changelog: [],
  lastChecked: null,
  isChecking: false,
  isPending: false,
  updatesDisabled: false,
  updatesDisabledMessage: '',
  updatesUnreachable: false,
  updateErrorMessage: '',

  silentCheck: async () => {
    // If a manual check is currently in flight, quietly join it; never compete or queue against it
    if (activeManualPromise) {
      try {
        await activeManualPromise
      } catch {
        // Quietly swallow manual error for silent caller
      }
      return
    }

    const now = Date.now()
    const lastSuccess = lastSuccessCheckTime || 0
    if (lastSuccess && (now - lastSuccess) < SUCCESS_COOLDOWN_MS) {
      return
    }
    if (lastFailureOrPendingTime && (now - lastFailureOrPendingTime) < RETRY_COOLDOWN_MS) {
      return
    }

    if (activeSilentPromise) {
      return activeSilentPromise
    }

    const generation = ++globalGeneration

    const runSilent = async () => {
      set({ isChecking: true, updatesUnreachable: false, updateErrorMessage: '' })
      try {
        const deltaCapable = await hasDeltaHandoffCapability()
        if (latestManualGeneration > generation) return

        const res = await checkUpdate(deltaCapable, { hideGlobalError: true, params: { background: true } })
        let data = res.data?.data

        if (latestManualGeneration > generation) return

        if (data?.updates_disabled) {
          if (latestManualGeneration > generation) return
          const nowDisabled = Date.now()
          lastSuccessCheckTime = nowDisabled
          set({
            updatesDisabled: true,
            updatesDisabledMessage: data?.message || 'خادم التحديثات غير مهيأ.',
            isPending: false,
            lastChecked: nowDisabled,
          })
          return
        }

        // Bounded polling if background check is pending
        if (data?.pending || data?.status === 'check_pending') {
          set({ isPending: true })
          const startTime = Date.now()

          while ((Date.now() - startTime) < MAX_POLL_DURATION_MS) {
            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS))
            if (latestManualGeneration > generation) return

            try {
              const pollRes = await checkUpdate(deltaCapable, { hideGlobalError: true, params: { background: true } })
              const pollData = pollRes.data?.data
              if (latestManualGeneration > generation) return

              if (pollData && !pollData.pending && pollData.status !== 'check_pending') {
                data = pollData
                break
              }
            } catch {
              break
            }
          }
        }

        if (latestManualGeneration > generation) return

        // If still pending after bounded polling (worker not running or still busy)
        if (data?.pending || data?.status === 'check_pending') {
          const localVersion = (await getInstalledElectronVersion()) || get().currentVersion
          if (latestManualGeneration > generation) return
          lastFailureOrPendingTime = Date.now()

          set({
            currentVersion: localVersion,
            isPending: true,
          })
          return
        }

        // If unreachable or explicit failure reported
        if (data?.updates_unreachable) {
          const localVersion = (await getInstalledElectronVersion()) || get().currentVersion
          if (latestManualGeneration > generation) return
          lastFailureOrPendingTime = Date.now()

          set({
            currentVersion: localVersion,
            isPending: false,
            updatesUnreachable: true,
            updateErrorMessage: formatUpdateError(data),
          })
          return
        }

        // Runtime guard: validate complete settled result structure
        if (!isValidSettledResult(data)) {
          const localVersion = (await getInstalledElectronVersion()) || get().currentVersion
          if (latestManualGeneration > generation) return
          lastFailureOrPendingTime = Date.now()

          set({
            currentVersion: localVersion,
            isPending: false,
            updatesUnreachable: true,
            updateErrorMessage: 'استجابة خادم التحديثات غير صالحة أو غير مكتملة.',
          })
          return
        }

        // Settled success: gather fallback version before timestamp and state update
        const localVersion = data.current_version || (await getInstalledElectronVersion()) || get().currentVersion
        if (latestManualGeneration > generation) return
        const nowSuccess = Date.now()
        lastSuccessCheckTime = nowSuccess

        set({
          hasUpdate: Boolean(data.has_update),
          latestVersion: data.latest_version || null,
          currentVersion: localVersion,
          changelog: data.changelog || [],
          lastChecked: nowSuccess,
          isPending: false,
          updatesDisabled: Boolean(data.updates_disabled),
          updatesDisabledMessage: data.updates_disabled ? (data.message || 'خادم التحديثات غير مهيأ.') : '',
          updatesUnreachable: false,
          updateErrorMessage: '',
        })
      } catch {
        const localVersion = (await getInstalledElectronVersion()) || get().currentVersion
        if (latestManualGeneration <= generation) {
          lastFailureOrPendingTime = Date.now()
          set({
            currentVersion: localVersion,
            isPending: false,
            updatesUnreachable: true,
            updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
          })
        }
      } finally {
        if (latestManualGeneration <= generation) {
          set({ isChecking: false })
        }
      }
    }

    activeSilentPromise = runSilent()
    try {
      await activeSilentPromise
    } finally {
      activeSilentPromise = null
    }
  },

  forceCheck: async () => {
    if (activeManualPromise) {
      return activeManualPromise
    }

    const generation = ++globalGeneration
    latestManualGeneration = generation

    const runManual = async () => {
      set({ isChecking: true, updatesUnreachable: false, updateErrorMessage: '' })
      try {
        const deltaCapable = await hasDeltaHandoffCapability()
        const res = await checkUpdate(deltaCapable, { hideGlobalError: true })
        const data = res.data?.data

        const localVersion = data?.current_version || (await getInstalledElectronVersion()) || null
        const isValid = isValidSettledResult(data)
        const isUnreachable = Boolean(data?.updates_unreachable) || !isValid
        const nowAttempt = Date.now()

        if (!isUnreachable && isValid) {
          lastSuccessCheckTime = nowAttempt
        } else {
          lastFailureOrPendingTime = nowAttempt
        }

        const stateUpdate = {
          hasUpdate: Boolean(data?.has_update),
          latestVersion: data?.latest_version || null,
          currentVersion: localVersion,
          changelog: data?.changelog || [],
          lastChecked: (!isUnreachable && isValid) ? nowAttempt : get().lastChecked,
          isPending: false,
          updatesDisabled: Boolean(data?.updates_disabled),
          updatesDisabledMessage: data?.updates_disabled ? (data?.message || 'خادم التحديثات غير مهيأ.') : '',
          updatesUnreachable: isUnreachable,
          updateErrorMessage: isUnreachable ? formatUpdateError(data) : '',
        }
        set(stateUpdate)
        return { ...data, current_version: localVersion }
      } catch (err) {
        const localVersion = (await getInstalledElectronVersion()) || get().currentVersion
        lastFailureOrPendingTime = Date.now()
        set({
          currentVersion: localVersion,
          isPending: false,
          updatesUnreachable: true,
          updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
        })
        throw err
      } finally {
        set({ isChecking: false })
      }
    }

    activeManualPromise = runManual()
    try {
      return await activeManualPromise
    } finally {
      activeManualPromise = null
    }
  }
}))

function formatUpdateError(data: Partial<UpdateCheckResult> | undefined): string {
  const baseMessage = data?.message || 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
  const errorCode = data?.errorCode || data?.status
  const details = data?.details
  const checkedUrl = data?.checkedUrl

  const technicalParts = [
    errorCode ? `code: ${errorCode}` : null,
    details ? `details: ${details}` : null,
    checkedUrl ? `url: ${checkedUrl}` : null,
  ].filter(Boolean)

  return technicalParts.length ? `${baseMessage} (${technicalParts.join(' | ')})` : baseMessage
}

export default useUpdateStore
