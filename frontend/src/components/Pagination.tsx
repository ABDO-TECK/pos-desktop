import styles from './Pagination.module.css'

interface PaginationProps {
  current: number
  total: number
  onPage: (page: number) => void
}

export default function Pagination({ current, total, onPage }: PaginationProps) {
  if (total <= 1) return null;

  return (
    <div className={styles.wrapper}>
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current - 1)} 
        disabled={current <= 1}
      >
        السابق
      </button>
      
      <span className={styles.pageInfo}>
        صفحة {current} من {total}
      </span>
      
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current + 1)} 
        disabled={current >= total}
      >
        التالي
      </button>
    </div>
  )
}
