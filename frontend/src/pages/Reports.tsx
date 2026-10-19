import { useState } from 'react'

import SummaryTab from './reports/SummaryTab'
import DailyTab from './reports/DailyTab'
import MonthlyTab from './reports/MonthlyTab'
import ProductsTab from './reports/ProductsTab'
import ProfitTab from './reports/ProfitTab'
import styles from './Reports.module.css'

export default function Reports() {
  const [tab, setTab] = useState('summary')

  const tabs = [
    { id: 'summary',  label: 'الملخص' },
    { id: 'daily',    label: 'يومي' },
    { id: 'monthly',  label: 'شهري' },
    { id: 'products', label: 'المنتجات' },
    { id: 'profit',   label: 'الأرباح' },
  ]

  return (
    <div className={styles.container}>
      <h1 className={styles.title}>التقارير والتحليلات</h1>

      {/* Tabs */}
      <div className="tabs-scroll">
        {tabs.map((t) => (
          <button 
            key={t.id} 
            onClick={() => setTab(t.id)} 
            className={`${styles.tabButton} ${tab === t.id ? styles.tabButtonActive : ''}`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'summary' && <SummaryTab />}
      {tab === 'daily' && <DailyTab />}
      {tab === 'monthly' && <MonthlyTab />}
      {tab === 'products' && <ProductsTab />}
      {tab === 'profit' && <ProfitTab />}
    </div>
  )
}
