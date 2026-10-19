import { useConfirmStore } from '../../store/confirmStore'
import { AlertTriangle } from 'lucide-react'

export default function ConfirmModal() {
  const { isOpen, message, handleConfirm, handleCancel } = useConfirmStore()

  if (!isOpen) return null

  return (
    <div className="modal-overlay" style={{ zIndex: 9999 }}>
      <div className="modal" style={{ maxWidth: '400px', textAlign: 'center' }}>
        <div style={{ marginBottom: '1rem', color: 'var(--danger)' }}>
          <AlertTriangle size={48} style={{ margin: '0 auto' }} />
        </div>
        <h3 style={{ marginBottom: '0.5rem', fontWeight: 700 }}>تأكيد الإجراء</h3>
        <p style={{ color: 'var(--text-muted)', marginBottom: '1.5rem', fontSize: '0.95rem', lineHeight: '1.5' }}>
          {message}
        </p>
        <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'center' }}>
          <button className="btn btn-danger" style={{ flex: 1, justifyContent: 'center' }} onClick={handleConfirm}>
            تأكيد
          </button>
          <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={handleCancel}>
            إلغاء
          </button>
        </div>
      </div>
    </div>
  )
}
