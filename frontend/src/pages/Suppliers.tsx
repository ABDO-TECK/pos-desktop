import { useState } from 'react'
import ReceiveGoods from './suppliers/ReceiveGoods'
import PurchaseHistory from './suppliers/PurchaseHistory'
import ManageSuppliers from './suppliers/ManageSuppliers'
import SupplierAccounts from './suppliers/SupplierAccounts'
import styles from './Suppliers.module.css'

type ReceiveCartLine = {
  product: Product
  quantity: number
  cost: number
}

export default function Suppliers() {
  const [tab, setTab] = useState(0)
  const [receiveCart, setReceiveCart] = useState<ReceiveCartLine[]>([])
  const [receiveSupplierId, setReceiveSupplierId] = useState('')
  const [receiveInvoiceId, setReceiveInvoiceId] = useState<number | null>(null)

  return (
    <div className={styles.root}>
      {/* Header + tab selector */}
      <div className="page-header col-mobile" style={{ marginBottom: 0 }}>
        <h2>الموردون</h2>
        <div className="page-tabs-bar">
          {['استلام بضاعة', 'سجل المشتريات', 'إدارة الموردين', 'حسابات الموردين'].map((t, i) => (
            <button
              key={i}
              onClick={() => setTab(i)}
              className={`${styles.tabBtn} ${tab === i ? styles.tabBtnActive : ''}`}
            >
              {t}
            </button>
          ))}
        </div>
      </div>

      {tab === 0 && <ReceiveGoods cart={receiveCart} setCart={setReceiveCart} supplierId={receiveSupplierId} setSupplierId={setReceiveSupplierId} invoiceId={receiveInvoiceId} setInvoiceId={setReceiveInvoiceId} />}
      {tab === 1 && <PurchaseHistory onReturnToCart={(items: PurchaseItem[], sId: number, originalInvoiceId: number) => {
        setReceiveSupplierId(String(sId))
        setReceiveInvoiceId(originalInvoiceId)
        setReceiveCart(items.map(i => ({
          product: {
            id: i.product_id,
            name: i.product_name ?? i.name ?? '',
            barcode: i.product_barcode ?? '',
            category_id: null,
            price: i.unit_cost,
            cost: i.cost ?? i.unit_cost,
            quantity: 0,
            units_per_box: 1,
          },
          quantity: i.quantity,
          cost: i.cost ?? i.unit_cost,
        })))
        setTab(0)
      }} />}
      {tab === 2 && <ManageSuppliers />}
      {tab === 3 && <SupplierAccounts />}

    </div>
  )
}
