import { create } from 'zustand'

type LanAccess = Awaited<ReturnType<NonNullable<Window['posRuntime']>['enableLanAccess']>>
const STORAGE_KEY = 'pos_lan_access_enabled'
const isDesktop = () => window.location.protocol === 'app:' && Boolean(window.posRuntime)

interface NetworkAccessState {
  access: LanAccess
  loading: boolean
  initialized: boolean
  preferredEnabled: boolean
  initialize: () => Promise<void>
  setEnabled: (enabled: boolean) => Promise<void>
}

// The service belongs to the desktop process, not the lifetime of a settings
// tab. Persist only the user's choice; never persist a claimed running status.
const useNetworkAccessStore = create<NetworkAccessState>((set, get) => ({
  access: { enabled: false, port: 8443, protocol: 'https' },
  loading: false,
  initialized: false,
  preferredEnabled: false,
  initialize: async () => {
    if (get().initialized) return
    set({ initialized: true })
    if (!isDesktop()) return
    let restore = false
    try { restore = localStorage.getItem(STORAGE_KEY) === 'true' } catch { /* Default to opt-out. */ }
    set({ preferredEnabled: restore })
    if (restore) await get().setEnabled(true)
  },
  setEnabled: async enabled => {
    if (get().loading) return
    const previous = get().access
    set({ loading: true })
    try {
      if (!isDesktop() || !window.posRuntime) throw new Error('Desktop bridge unavailable')
      const result = enabled
        ? await window.posRuntime.enableLanAccess()
        : await window.posRuntime.disableLanAccess()
      let error = result.error
      if (result.enabled === enabled) {
        set({ preferredEnabled: enabled })
        try { localStorage.setItem(STORAGE_KEY, String(enabled)) }
        catch { error = 'تم تغيير الوصول، لكن تعذر حفظ الاختيار لإعادة تشغيل البرنامج.' }
      } else if (!error) {
        error = enabled ? 'تعذر تفعيل الوصول من الهاتف.' : 'تعذر إيقاف الوصول من الهاتف.'
      }
      set({ access: { ...result, error } })
    } catch (error) {
      console.error('[LAN] Could not change phone access:', error)
      set({ access: { ...previous, error: enabled
        ? 'تعذر تفعيل الوصول من الهاتف. تحقق من جدار الحماية وأعد المحاولة.'
        : 'تعذر إيقاف الوصول من الهاتف. أعد المحاولة.' } })
    } finally {
      set({ loading: false })
    }
  },
}))

export default useNetworkAccessStore
