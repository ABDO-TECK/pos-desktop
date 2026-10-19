import { useState } from 'react'

import ExpenseLogTab from './expenses/ExpenseLogTab'
import ExpenseCategoriesTab from './expenses/ExpenseCategoriesTab'
import styles from './Expenses.module.css'

export default function Expenses() {
  const [tab, setTab] = useState('log') // 'log' or 'categories'
  
  return (
    <div className={styles.container}>
      <h1 className={styles.title}>نظام المصروفات</h1>

      <div className="tabs-scroll">
        <button
          onClick={() => setTab('log')}
          className={`${styles.tabButton} ${tab === 'log' ? styles.tabButtonActive : ''}`}
        >
          سجل المصروفات
        </button>
        <button
          onClick={() => setTab('categories')}
          className={`${styles.tabButton} ${tab === 'categories' ? styles.tabButtonActive : ''}`}
        >
          تصنيفات المصروفات
        </button>
      </div>

      {tab === 'log' ? <ExpenseLogTab /> : <ExpenseCategoriesTab />}
    </div>
  )
}
