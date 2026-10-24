import { create } from 'zustand'
import { checkUpdate, getUpdateStatus } from '../api/endpoints'

async function hasDeltaHandoffCapability(): Promise<boolean> {
  try {
    return Boolean((await window.posRuntime?.getDeltaCapability?.())?.capable)
  } catch {
    return false
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
  channel: 'stable' | 'beta' | null;
  installedChannel: 'stable' | 'beta' | 'retired' | null;
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
  hydrateLocalStatus: (force?: boolean) => Promise<UpdateStatusData | null>;
  setChannelState: (channel: 'stable' | 'beta') => void;
  invalidate: () => void;
}

let activeSilentPromise: Promise<void> | null = null
let activeManualPromise: Promise<UpdateCheckResult | undefined> | null = null
let activeHydratePromise: Promise<UpdateStatusData | null> | null = null

let channelGeneration = 0
let manualGeneration = 0
let hydrateGeneration = 0
let authoritativeGeneration = 0

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
  channel: null,
  installedChannel: null,
  changelog: [],
  lastChecked: null,
  isChecking: false,
  isPending: false,
  updatesDisabled: false,
  updatesDisabledMessage: '',
  updatesUnreachable: false,
  updateErrorMessage: '',

  setChannelState: (channel: 'stable' | 'beta') => {
    channelGeneration++
    activeSilentPromise = null
    activeManualPromise = null
    activeHydratePromise = null
    lastSuccessCheckTime = 0
    lastFailureOrPendingTime = 0
    set({
      channel,
      hasUpdate: false,
      latestVersion: null,
      changelog: [],
      updatesUnreachable: false,
      updateErrorMessage: '',
      isChecking: false,
      isPending: false,
      lastChecked: null,
    })
  },

  invalidate: () => {
    channelGeneration++
    activeSilentPromise = null
    activeManualPromise = null
    activeHydratePromise = null
    lastSuccessCheckTime = 0
    lastFailureOrPendingTime = 0
    set({
      hasUpdate: false,
      latestVersion: null,
      changelog: [],
      updatesUnreachable: false,
      updateErrorMessage: '',
      isChecking: false,
      isPending: false,
      lastChecked: null,
    })
  },

  hydrateLocalStatus: (force = false): Promise<UpdateStatusData | null> => {
    if (activeHydratePromise && !force) {
      return activeHydratePromise
    }

    const currentChannelGen = channelGeneration
    const currentHydrateGen = ++hydrateGeneration
    const startAuthGen = authoritativeGeneration
    const isCurrent = () => currentChannelGen === channelGeneration && currentHydrateGen === hydrateGeneration

    const runHydrate = async (): Promise<UpdateStatusData | null> => {
      try {
        const deltaCapable = await hasDeltaHandoffCapability()
        if (!isCurrent()) return null

        const res = await getUpdateStatus(deltaCapable, { hideGlobalError: true })
        if (!isCurrent()) return null

        const data = res.data?.data
        if (data && typeof data === 'object') {
          const rawVersion = typeof data.current_version === 'string' ? data.current_version.trim() : null
          const validVersion = rawVersion || null
          const validChannel = (data.channel === 'stable' || data.channel === 'beta') ? data.channel : null
          const validInstalledChannel = (data.installed_channel === 'stable' || data.installed_channel === 'beta' || data.installed_channel === 'retired')
            ? data.installed_channel
            : null

          set((state) => ({
            currentVersion: (authoritativeGeneration > startAuthGen) ? state.currentVersion : (validVersion || state.currentVersion),
            channel: validChannel || state.channel,
            installedChannel: validInstalledChannel || state.installedChannel,
          }))
          return data
        }
        return null
      } catch {
        return null
      } finally {
        if (activeHydratePromise === currentPromise) {
          activeHydratePromise = null
        }
      }
    }

    const currentPromise = runHydrate()
    activeHydratePromise = currentPromise
    return currentPromise
  },

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

    const currentChannelGen = channelGeneration
    const currentManualGen = manualGeneration
    const isCurrent = () => currentChannelGen === channelGeneration && currentManualGen === manualGeneration

    const runSilent = async () => {
      if (!isCurrent()) return
      set({ isChecking: true, updatesUnreachable: false, updateErrorMessage: '' })

      try {
        const deltaCapable = await hasDeltaHandoffCapability()
        if (!isCurrent()) return

        const res = await checkUpdate(deltaCapable, { hideGlobalError: true, params: { background: true } })
        if (!isCurrent()) return
        let data = res.data?.data

        // Consume valid local current_version immediately without waiting 30s
        if (typeof data?.current_version === 'string' && data.current_version.trim()) {
          const ver = data.current_version.trim()
          set((state) => ({
            currentVersion: ver || state.currentVersion,
          }))
        }

        if (data?.updates_disabled) {
          if (!isCurrent()) return
          const nowDisabled = Date.now()
          lastSuccessCheckTime = nowDisabled
          authoritativeGeneration++
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
          if (!isCurrent()) return
          set({ isPending: true })
          const startTime = Date.now()

          while ((Date.now() - startTime) < MAX_POLL_DURATION_MS) {
            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS))
            if (!isCurrent()) return

            try {
              const pollRes = await checkUpdate(deltaCapable, { hideGlobalError: true, params: { background: true } })
              if (!isCurrent()) return
              const pollData = pollRes.data?.data

              if (typeof pollData?.current_version === 'string' && pollData.current_version.trim()) {
                const ver = pollData.current_version.trim()
                set((state) => ({ currentVersion: ver || state.currentVersion }))
              }

              if (pollData && !pollData.pending && pollData.status !== 'check_pending') {
                data = pollData
                break
              }
            } catch {
              break
            }
          }
        }

        if (!isCurrent()) return

        // If still pending after bounded polling (worker not running or still busy)
        if (data?.pending || data?.status === 'check_pending') {
          const localVersion = (typeof data?.current_version === 'string' && data.current_version.trim())
            ? data.current_version.trim()
            : (get().currentVersion || null)
          if (!isCurrent()) return
          lastFailureOrPendingTime = Date.now()

          set({
            currentVersion: localVersion,
            isPending: true,
          })
          return
        }

        // If unreachable or explicit failure reported
        if (data?.updates_unreachable) {
          const localVersion = (typeof data?.current_version === 'string' && data.current_version.trim())
            ? data.current_version.trim()
            : (get().currentVersion || null)
          if (!isCurrent()) return
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
          const rawVersion = typeof (data as Record<string, unknown> | null | undefined)?.current_version === 'string'
            ? ((data as Record<string, unknown>).current_version as string).trim()
            : null
          const localVersion = rawVersion || get().currentVersion || null
          if (!isCurrent()) return
          lastFailureOrPendingTime = Date.now()

          set({
            currentVersion: localVersion,
            isPending: false,
            updatesUnreachable: true,
            updateErrorMessage: 'استجابة خادم التحديثات غير صالحة أو غير مكتملة.',
          })
          return
        }

        // Settled success
        const localVersion = (typeof data.current_version === 'string' && data.current_version.trim())
          ? data.current_version.trim()
          : (get().currentVersion || null)
        if (!isCurrent()) return
        const nowSuccess = Date.now()
        lastSuccessCheckTime = nowSuccess
        authoritativeGeneration++

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
        if (!isCurrent()) return
        const localVersion = get().currentVersion || null
        lastFailureOrPendingTime = Date.now()
        set({
          currentVersion: localVersion,
          isPending: false,
          updatesUnreachable: true,
          updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
        })
      } finally {
        if (isCurrent()) {
          set({ isChecking: false })
        }
        if (activeSilentPromise === currentPromise) {
          activeSilentPromise = null
        }
      }
    }

    const currentPromise = runSilent()
    activeSilentPromise = currentPromise
    try {
      await activeSilentPromise
    } finally {
      if (activeSilentPromise === currentPromise) {
        activeSilentPromise = null
      }
    }
  },

  forceCheck: async () => {
    if (activeManualPromise) {
      return activeManualPromise
    }

    const currentChannelGen = channelGeneration
    const currentManualGen = ++manualGeneration

    const runManual = async () => {
      const isCurrent = () => currentChannelGen === channelGeneration && currentManualGen === manualGeneration
      if (!isCurrent()) return undefined

      set({ isChecking: true, updatesUnreachable: false, updateErrorMessage: '' })
      try {
        const deltaCapable = await hasDeltaHandoffCapability()
        if (!isCurrent()) return undefined

        const res = await checkUpdate(deltaCapable, { hideGlobalError: true })
        if (!isCurrent()) return undefined
        const data = res.data?.data

        const localVersion = (typeof data?.current_version === 'string' && data.current_version.trim())
          ? data.current_version.trim()
          : (get().currentVersion || null)
        const isValid = isValidSettledResult(data)
        const isUnreachable = Boolean(data?.updates_unreachable) || !isValid
        const nowAttempt = Date.now()

        if (!isCurrent()) return undefined

        if (!isUnreachable && isValid) {
          lastSuccessCheckTime = nowAttempt
          authoritativeGeneration++
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
        if (!isCurrent()) return undefined
        lastFailureOrPendingTime = Date.now()
        set({
          currentVersion: get().currentVersion || null,
          isPending: false,
          updatesUnreachable: true,
          updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
        })
        throw err
      } finally {
        if (isCurrent()) {
          set({ isChecking: false })
        }
        if (activeManualPromise === currentPromise) {
          activeManualPromise = null
        }
      }
    }

    const currentPromise = runManual()
    activeManualPromise = currentPromise
    try {
      return await currentPromise
    } finally {
      if (activeManualPromise === currentPromise) {
        activeManualPromise = null
      }
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
