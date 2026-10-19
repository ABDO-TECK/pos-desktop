import { create } from 'zustand'

const STORAGE_KEY = 'pos_ui_theme'
const THEME_TRANSITION_MS = 380

type ThemeMode = 'dark' | 'light';

function applyTheme(mode: ThemeMode) {
  if (typeof document === 'undefined') return
  if (mode === 'dark') {
    document.documentElement.dataset.theme = 'dark'
  } else {
    delete document.documentElement.dataset.theme
  }
  try {
    localStorage.setItem(STORAGE_KEY, mode)
  } catch (err) {  /* ignore */
  }
}

function runWithThemeTransition(updateDom: () => void) {
  if (typeof document === 'undefined') {
    updateDom()
    return
  }
  const root = document.documentElement
  if (typeof document.startViewTransition === 'function') {
    document.startViewTransition(() => {
      updateDom()
    })
    return
  }
  root.classList.add('theme-changing')
  requestAnimationFrame(() => {
    updateDom()
    window.setTimeout(() => {
      root.classList.remove('theme-changing')
    }, THEME_TRANSITION_MS)
  })
}

function getStoredTheme(): ThemeMode {
  try {
    const s = localStorage.getItem(STORAGE_KEY)
    if (s === 'dark' || s === 'light') return s as ThemeMode
  } catch (err) {  /* ignore */
  }
  return 'light'
}

const initial = getStoredTheme()
applyTheme(initial)

interface ThemeState {
  mode: ThemeMode;
  toggle: () => void;
}

const useThemeStore = create<ThemeState>((set, get) => ({
  mode: initial,
  toggle: () => {
    const next = get().mode === 'dark' ? 'light' : 'dark'
    runWithThemeTransition(() => {
      applyTheme(next)
      set({ mode: next })
    })
  },
}))

export default useThemeStore
