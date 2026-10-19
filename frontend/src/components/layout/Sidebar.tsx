import { useEffect } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import {
  ShoppingCart, Package, Truck, BarChart2,
  Users, LogOut, Store, Receipt, Settings, X, UserCheck, Moon, Sun,
} from 'lucide-react'
import useAuthStore from '../../store/authStore'
import useSettingsStore from '../../store/settingsStore'
import useThemeStore from '../../store/themeStore'
import useUpdateStore from '../../store/updateStore'
import toast from 'react-hot-toast'

const navItems = [
  { to: '/',           label: 'نقطة البيع',   icon: ShoppingCart, roles: ['admin', 'cashier'] },
  { to: '/sales',      label: 'سجل المبيعات', icon: Receipt,      roles: ['admin', 'cashier'] },
  { to: '/products',   label: 'المنتجات',     icon: Package,      roles: ['admin'] },
  { to: '/customers',  label: 'العملاء',       icon: UserCheck,    roles: ['admin', 'cashier'] },
  { to: '/suppliers',  label: 'الموردون',      icon: Truck,        roles: ['admin'] },
  { to: '/expenses',   label: 'المصروفات',     icon: Receipt,      roles: ['admin'] },
  { to: '/reports',    label: 'التقارير',      icon: BarChart2,    roles: ['admin'] },
  { to: '/users',      label: 'المستخدمون',   icon: Users,        roles: ['admin'] },
  { to: '/settings',   label: 'الإعدادات',    icon: Settings,     roles: ['admin'] },
]

interface SidebarProps {
  onClose?: () => void;
}

export default function Sidebar({ onClose }: SidebarProps) {
  const { user, logout } = useAuthStore()
  const { storeName }    = useSettingsStore()
  const themeMode        = useThemeStore((s) => s.mode)
  const toggleTheme      = useThemeStore((s) => s.toggle)
  const navigate         = useNavigate()
  
  // Updates
  const { hasUpdate, currentVersion, silentCheck } = useUpdateStore()

  useEffect(() => {
    if (user?.role === 'admin') {
      silentCheck()
    }
  }, [user?.role, silentCheck])

  const handleLogout = async () => {
    await logout()
    toast.success('تم تسجيل الخروج')
    navigate('/login')
    onClose?.()
  }

  const visibleItems = navItems.filter(item => user?.role && item.roles.includes(user.role))

  return (
    <aside className="sidebar-panel">

      {/* Safe area for Electron Window Controls (X, Maximize, Minimize) */}
      <div className="drag-region" style={{ height: '36px', width: '100%' }} />

      {/* Logo row */}
      <div className="sidebar-header" style={{ paddingTop: '0.5rem', minHeight: '60px' }}>
        <Store size={24} color="#22c55e" style={{ flexShrink: 0 }} />
        <span className="sidebar-logo-text" style={{ color: '#fff', fontWeight: 700, fontSize: '1rem', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {storeName || 'نظام الكاشير'}
        </span>
        <button
          type="button"
          className="sidebar-icon-btn"
          onClick={toggleTheme}
          aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
          title={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
        >
          {themeMode === 'dark' ? <Sun size={20} /> : <Moon size={20} />}
        </button>
        {/* Close button — mobile only */}
        <button
          type="button"
          onClick={onClose}
          style={{ display: 'none' }}
          className="sidebar-close-btn sidebar-icon-btn"
          aria-label="إغلاق القائمة"
        >
          <X size={18} />
        </button>
      </div>

      {/* Nav links */}
      <nav className="sidebar-nav">
        {visibleItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            onClick={onClose}
            className={({ isActive }) =>
              `sidebar-link${isActive ? ' sidebar-link--active' : ''}`
            }
          >
            <div style={{ position: 'relative', display: 'flex' }}>
              <item.icon size={18} style={{ flexShrink: 0 }} />
              {item.to === '/settings' && hasUpdate && (
                <span style={{
                  position: 'absolute', top: '-2px', right: '-2px',
                  width: '8px', height: '8px', background: 'var(--danger)',
                  borderRadius: '50%', border: '2px solid var(--sidebar-bg)'
                }} />
              )}
            </div>
            <span className="nav-label">{item.label}</span>
          </NavLink>
        ))}
      </nav>

      {/* User info + logout */}
      <div className="sidebar-footer">
        <div className="user-info" style={{ color: '#9ca3af', fontSize: '0.8rem', marginBottom: '0.5rem' }}>
          <div style={{ color: '#fff', fontWeight: 600 }}>{user?.name}</div>
          <div>{user?.role === 'admin' ? 'مدير النظام' : 'كاشير'}</div>
        </div>
        <button
          onClick={handleLogout}
          style={{
            display: 'flex', alignItems: 'center', gap: '0.5rem',
            background: 'transparent', border: 'none',
            color: '#ef4444', cursor: 'pointer',
            fontSize: '0.9rem', padding: '0.4rem 0',
          }}
        >
          <LogOut size={16} /> تسجيل الخروج
        </button>
        {currentVersion && (
          <div style={{ textAlign: 'center', marginTop: '0.5rem', fontSize: '0.7rem', color: 'rgba(255,255,255,0.3)' }}>
            v{currentVersion}
          </div>
        )}
      </div>
    </aside>
  )
}
