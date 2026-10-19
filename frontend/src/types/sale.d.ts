declare global {
  interface SaleItem {
    product_id: number;
    quantity: number;
    unit_price: number;
    subtotal: number;
    price?: number;
    product_name?: string;
    name?: string;
    size_name?: string;
    unit_type?: 'piece' | 'weight' | 'liter';
    sell_by_weight?: number;
  }

  interface Sale {
    id: number;
    user_id: number;
    customer_id: number | null;
    customer_name?: string;
    total_amount: number;
    discount: number;
    tax: number;
    net_amount: number;
    payment_method: string;
    paid_amount: number;
    due_amount: number;
    subtotal?: number;
    total?: number;
    amount_paid?: number;
    amount_due?: number;
    change_due?: number;
    created_at?: string;
    cashier_name?: string;
    items?: SaleItem[];
    invoice?: { id: number; status: string; customer_name?: string };
    low_stock_alerts?: { product_id: number; name: string; quantity: number }[];
    driver_name?: string | null;
    vehicle_number?: string | null;
    shipping_cost?: number | string;
    delivery_date?: string | null;
    delivery_notes?: string | null;
  }

  interface NewCustomerPayload {
    name: string;
    phone: string;
    address: string;
  }

  interface SaleCreatePayload {
    idempotency_key: string;
    items: { product_id: number; quantity: number; price: number }[];
    discount: number;
    payment_method: string;
    amount_paid: number;
    customer_id?: number;
    new_customer?: NewCustomerPayload;
    deposit?: number;
    invoice_id?: number;
    status: 'completed' | 'reserved';
    driver_name?: string;
    shipping_cost: number;
    delivery_date?: string;
    delivery_notes?: string;
  }
}

export {};
