import { useState, useCallback, useEffect, ReactNode } from 'react'
import { Menu, Store, Moon, Sun } from 'lucide-react'
import Sidebar from './Sidebar'
import useSettingsStore from '../../store/settingsStore'
import useThemeStore from '../../store/themeStore'

interface LayoutProps {
  children: ReactNode;
}

export default function Layout({ children }: LayoutProps) {
  const [open, setOpen] = useState(false)
  const { storeName } = useSettingsStore()
  const themeMode = useThemeStore((s) => s.mode)
  const toggleTheme = useThemeStore((s) => s.toggle)

  useEffect(() => {
    document.title = storeName || 'نظام الكاشير'
  }, [storeName])

  const close = useCallback(() => setOpen(false), [])

  return (
    <div className="app-layout">

      {/* ── Mobile top header ── */}
      <header className="mobile-header drag-region">
        <button className="mh-btn no-drag" onClick={() => setOpen(true)} aria-label="القائمة">
          <Menu size={22} />
        </button>
        <Store size={20} color="#22c55e" />
        <span className="mh-title">{storeName || 'نظام الكاشير'}</span>
        <button
          type="button"
          className="mh-btn no-drag"
          onClick={toggleTheme}
          aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
          title={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
        >
          {themeMode === 'dark' ? <Sun size={20} /> : <Moon size={20} />}
        </button>
      </header>

      {/* ── Sidebar overlay (mobile) ── */}
      <div
        className={`sidebar-overlay${open ? ' open' : ''}`}
        onClick={close}
        aria-hidden="true"
      />

      {/* ── Sidebar ── */}
      <div className={`sidebar-wrap${open ? ' open' : ''}`}>
        <Sidebar onClose={close} />
      </div>

      {/* ── Page content ── */}
      <main className="main-content">
        {children}
      </main>

    </div>
  )
}
