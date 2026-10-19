import { useState } from 'react'
import styles from './Settings.module.css'
import StoreSettingsForm from './settings/StoreSettingsForm'
import NetworkAccessSection from './settings/NetworkAccessSection'
import BackupSection from './settings/BackupSection'
import UpdateSection from './settings/UpdateSection'
import FleetDashboard from './settings/FleetDashboard'
import SystemHealth from '../components/settings/SystemHealth'
import LogViewer from './settings/LogViewer'
import FactoryResetSection from './settings/FactoryResetSection'
import { Store, Wifi, Settings as SettingsIcon, Server } from 'lucide-react'

export default function Settings() {
  const [activeTab, setActiveTab] = useState<'store' | 'network' | 'system' | 'fleet'>('store')

  return (
    <div className={styles.settingsContainer}>
      <h1 className={styles.settingsTitle}>الإعدادات</h1>
      
      {/* التبويبات */}
      <div className={styles.settingsTabs}>
        <button 
          type="button"
          onClick={() => setActiveTab('store')} 
          className={`${styles.tabButton} ${activeTab === 'store' ? styles.tabActive : ''}`}
        >
          <Store size={16} />
          <span>إعدادات المتجر</span>
        </button>
        
        <button 
          type="button"
          onClick={() => setActiveTab('network')} 
          className={`${styles.tabButton} ${activeTab === 'network' ? styles.tabActive : ''}`}
        >
          <Wifi size={16} />
          <span>الاتصال بالهاتف</span>
        </button>

        <button 
          type="button"
          onClick={() => setActiveTab('fleet')} 
          className={`${styles.tabButton} ${activeTab === 'fleet' ? styles.tabActive : ''}`}
        >
          <Server size={16} />
          <span>أسطول التحديثات (Fleet)</span>
        </button>
        
        <button 
          type="button"
          onClick={() => setActiveTab('system')} 
          className={`${styles.tabButton} ${activeTab === 'system' ? styles.tabActive : ''}`}
        >
          <SettingsIcon size={16} />
          <span>النظام والصيانة</span>
        </button>
      </div>

      {/* محتوى التبويب النشط */}
      <div className={styles.tabContent}>
        {activeTab === 'store' && <StoreSettingsForm />}
        
        {activeTab === 'network' && <NetworkAccessSection />}

        {activeTab === 'fleet' && <FleetDashboard />}

        
        {activeTab === 'system' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <BackupSection />
            <UpdateSection />
            
            <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <SystemHealth />
            </section>

            <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <LogViewer />
            </section>

            <FactoryResetSection />
          </div>
        )}
      </div>
    </div>
  )
}
