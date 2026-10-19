declare global {
  interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    email?: string | null;
    address: string | null;
    balance: number;
    initial_balance?: number;
    created_at?: string;
  }

  interface PurchaseItem {
    product_id: number;
    product_name?: string;
    quantity: number;
    unit_cost: number;
    subtotal: number;
    cost?: number;
    name?: string;
    product_barcode?: string;
    size_name?: string;
    unit_type?: 'piece' | 'weight' | 'liter';
    sell_by_weight?: number;
  }

  interface PurchaseInvoice {
    id: number;
    invoice_id?: number;
    supplier_id: number;
    supplier_name?: string;
    user_id: number;
    user_name?: string;
    total: number;
    discount?: number | null;
    shipping_cost?: number | null;
    notes?: string | null;
    driver_name?: string | null;
    /** Legacy data only; new purchase forms use shipping_cost instead. */
    vehicle_number?: string | null;
    delivery_date?: string | null;
    delivery_notes?: string | null;
    items?: PurchaseItem[];
    items_count?: number;
    created_at?: string;
  }

  interface BulkPurchasePayload {
    supplier_id: number;
    items: { product_id: number; quantity: number; cost: number; update_cost?: boolean }[];
    notes?: string;
    payment_amount?: number;
    payment_type?: string;
    deposit?: number;
    replace_invoice_id?: number | null;
    driver_name?: string;
    discount?: number;
    shipping_cost?: number;
    delivery_date?: string;
    delivery_notes?: string;
  }

  interface SupplierLedgerData {
    supplier: Supplier;
    entries: LedgerEntry[];
    balance: number;
    total_entries?: number;
    truncated?: boolean;
  }
}

export {};
