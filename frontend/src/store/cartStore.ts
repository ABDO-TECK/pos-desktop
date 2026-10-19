import { create } from 'zustand'

export interface CartItem {
  id: number;
  name: string;
  barcode?: string;
  price: number;
  quantity: number;
  subtotal: number;
  units_per_box?: number;
  sell_by_weight?: number;
  scanned_as_box?: boolean;
  unit_type?: string;
  size_name?: string | null;
}

export interface AddItemProduct {
  id: number;
  name: string;
  barcode?: string;
  price: string | number;
  units_per_box?: string | number;
  sell_by_weight?: string | number;
  scanned_as_box?: boolean;
  unit_type?: string;
  size_name?: string | null;
  [key: string]: unknown;
}

export interface MergeInvoiceLine {
  product_id?: number;
  price?: string | number;
  quantity?: string | number;
  product_name?: string;
  name?: string;
  barcode?: string;
}

interface CartState {
  items: CartItem[];
  discount: number;
  paymentMethod: string;
  amountPaid: number;
  rebillingInvoiceId: number | null;
  rebillingCustomerId: number | null;
  rebillingAmountPaid: number;
  rebillingPaymentMethod: string | null;
  rebillingShippingCost: number;
  subtotal: number;
  itemCount: number;

  addItem: (product: AddItemProduct) => void;
  removeItem: (id: number) => void;
  updateQuantity: (id: number, qty: number) => void;
  updatePrice: (id: number, newPrice: number) => void;
  setDiscount: (discount: number) => void;
  setPaymentMethod: (method: string) => void;
  setAmountPaid: (amount: number) => void;
  clearCart: () => void;
  mergeInvoiceLines: (
    lines: MergeInvoiceLine[],
    invoiceId?: number | null,
    customerId?: number | null,
    amountPaid?: number,
    originalPaymentMethod?: string | null,
    shippingCost?: number,
  ) => void;
  switchItemProduct: (oldId: number, newProduct: AddItemProduct) => void;
}

const useCartStore = create<CartState>((set, get) => ({
  items: [],
  discount: 0,
  paymentMethod: 'cash',
  amountPaid: 0,
  rebillingInvoiceId: null,
  rebillingCustomerId: null,
  rebillingAmountPaid: 0,
  rebillingPaymentMethod: null,
  rebillingShippingCost: 0,

  switchItemProduct: (oldId: number, newProduct: AddItemProduct) => {
    const items = get().items
    const price = parseFloat(String(newProduct.price)) || 0
    const unitsPerBox = Math.max(1, parseInt(String(newProduct.units_per_box)) || 1)
    const unitType = newProduct.unit_type ?? (parseInt(String(newProduct.sell_by_weight)) === 1 ? 'weight' : 'piece')

    const existingIndex = items.findIndex((i) => i.id === newProduct.id && i.id !== oldId)
    const oldItem = items.find((i) => i.id === oldId)
    if (!oldItem) return

    if (existingIndex >= 0) {
      set({
        items: items
          .map((i, idx) =>
            idx === existingIndex
              ? { ...i, quantity: i.quantity + oldItem.quantity, subtotal: (i.quantity + oldItem.quantity) * i.price }
              : i
          )
          .filter((i) => i.id !== oldId),
      })
    } else {
      set({
        items: items.map((i) =>
          i.id === oldId
            ? {
                ...i,
                id: newProduct.id,
                name: newProduct.name,
                barcode: newProduct.barcode,
                price: price,
                subtotal: i.quantity * price,
                units_per_box: unitsPerBox,
                unit_type: unitType,
                size_name: newProduct.size_name,
              }
            : i
        ),
      })
    }
  },

  addItem: (product: AddItemProduct) => {
    const items = get().items
    const price = parseFloat(String(product.price)) || 0
    const unitsPerBox = Math.max(1, parseInt(String(product.units_per_box)) || 1)
    const unitType = product.unit_type ?? (parseInt(String(product.sell_by_weight)) === 1 ? 'weight' : 'piece')
    const isByWeight = unitType === 'weight'
    
    const qtyToAdd = product.scanned_as_box ? unitsPerBox : 1

    const existing = items.find((i) => i.id === product.id)
    if (existing) {
      set({
        items: items.map((i) =>
          i.id === product.id
            ? { ...i, quantity: i.quantity + qtyToAdd, subtotal: (i.quantity + qtyToAdd) * i.price }
            : i
        ),
      })
    } else {
      set({
        items: [...items, { ...product, price, quantity: qtyToAdd, subtotal: price * qtyToAdd, units_per_box: unitsPerBox, sell_by_weight: isByWeight ? 1 : 0, unit_type: unitType }],
      })
    }
  },

  removeItem: (id: number) =>
    set((state) => {
      const items = state.items.filter((i) => i.id !== id)
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    }),

  updateQuantity: (id: number, qty: number) => {
    set((state) => {
      let items
      if (qty <= 0) {
        items = state.items.filter((i) => i.id !== id)
      } else {
        items = state.items.map((i) =>
          i.id === id ? { ...i, quantity: parseFloat(qty.toString()) || 0, subtotal: (parseFloat(qty.toString()) || 0) * i.price } : i
        )
      }
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    })
  },

  updatePrice: (id: number, newPrice: number) => {
    set((state) => {
      const price = parseFloat(newPrice.toString()) || 0;
      const items = state.items.map((i) =>
        i.id === id ? { ...i, price, subtotal: i.quantity * price } : i
      )
      return { items }
    })
  },

  setDiscount: (discount: number) => set({ discount: parseFloat(discount.toString()) || 0 }),
  setPaymentMethod: (method: string) => set({ paymentMethod: method }),
  setAmountPaid: (amount: number) => set({ amountPaid: parseFloat(amount.toString()) || 0 }),

  clearCart: () =>
    set({
      items: [],
      discount: 0,
      amountPaid: 0,
      paymentMethod: 'cash',
      rebillingInvoiceId: null,
      rebillingCustomerId: null,
      rebillingAmountPaid: 0,
      rebillingPaymentMethod: null,
      rebillingShippingCost: 0,
    }),

  mergeInvoiceLines: (
    lines: MergeInvoiceLine[],
    invoiceId: number | null = null,
    customerId: number | null = null,
    amountPaid: number = 0,
    originalPaymentMethod: string | null = null,
    shippingCost: number = 0,
  ) => {
    if (!lines?.length) return
    set((state) => {
      let items = [...state.items]
      for (const line of lines) {
        const id = Number(line.product_id)
        const price = parseFloat(String(line.price)) || 0
        const qty = parseFloat(String(line.quantity)) || 0
        if (qty <= 0 || !id) continue
        const name = line.product_name ?? line.name ?? 'منتج'
        const barcode = line.barcode ?? ''
        const idx = items.findIndex((i) => i.id === id)
        if (idx >= 0) {
          const i = items[idx]
          const nq = i.quantity + qty
          items[idx] = { ...i, name, barcode, price, quantity: nq, subtotal: nq * price }
        } else {
          items.push({
            id,
            name,
            barcode,
            price,
            quantity: qty,
            subtotal: price * qty,
          })
        }
      }
      const rid = invoiceId != null ? Number(invoiceId) : null
      const cid = customerId != null ? Number(customerId) : null
      const normalizedPaymentMethod = originalPaymentMethod?.trim() || null
      const normalizedShippingCost = Number.isFinite(Number(shippingCost))
        ? Math.max(0, Number(shippingCost))
        : 0
      return {
        items,
        rebillingInvoiceId: Number.isFinite(rid) && (rid as number) > 0 ? rid : state.rebillingInvoiceId,
        rebillingCustomerId: Number.isFinite(cid) && (cid as number) > 0 ? cid : state.rebillingCustomerId,
        rebillingAmountPaid: Number.isFinite(amountPaid) && amountPaid > 0 ? amountPaid : 0,
        rebillingPaymentMethod: normalizedPaymentMethod,
        rebillingShippingCost: normalizedShippingCost,
        paymentMethod: normalizedPaymentMethod ?? state.paymentMethod,
      }
    })
  },

  get subtotal() {
    return get().items.reduce((s: number, i: CartItem) => s + (parseFloat(i.subtotal.toString()) || 0), 0)
  },
  get itemCount() {
    return get().items.reduce((s: number, i: CartItem) => s + i.quantity, 0)
  },
}))

export default useCartStore
