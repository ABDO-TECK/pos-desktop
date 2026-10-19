export interface ProductFormState {
  name: string
  barcodes: string[]
  price: string | number
  cost: string | number
  quantity: string | number
  low_stock_threshold: string | number
  units_per_box: number
  category_id: number | string | null
  sell_by_weight: number
  barcode: string
  unit_type: 'piece' | 'weight' | 'liter' | string
  sizes: Product[]
}

export const emptyProduct: ProductFormState = {
  name: '',
  barcodes: [''],
  price: '',
  cost: '',
  quantity: '',
  low_stock_threshold: 5,
  units_per_box: 1,
  category_id: null,
  sell_by_weight: 0,
  barcode: '',
  unit_type: 'piece',
  sizes: [],
}

export function normalizeProductPayload(form: ProductFormState) {
  const raw = Array.isArray(form.barcodes) ? form.barcodes : [form.barcode || '']
  const main = String(raw[0] ?? '').trim()
  const additional_barcodes = raw.slice(1).map((b) => String(b).trim()).filter(Boolean)
  const { barcodes: _b, barcode: _old, ...rest } = form
  return {
    ...rest,
    category_id: rest.category_id === '' || rest.category_id == null ? null : rest.category_id,
    quantity: rest.quantity === '' || rest.quantity == null ? 0 : Number(rest.quantity),
    cost: rest.cost === '' || rest.cost == null ? 0 : Number(rest.cost),
    low_stock_threshold: rest.low_stock_threshold === '' || rest.low_stock_threshold == null ? 5 : Number(rest.low_stock_threshold),
    sizes: (rest.sizes || []).map((s: any) => ({
      ...s,
      price: s.price === '' || s.price == null ? 0 : Number(s.price),
      cost: s.cost === '' || s.cost == null ? 0 : Number(s.cost),
      quantity: s.quantity === '' || s.quantity == null ? 0 : Number(s.quantity),
      low_stock_threshold: s.low_stock_threshold === '' || s.low_stock_threshold == null ? 5 : Number(s.low_stock_threshold),
    })),
    barcode: main,
    additional_barcodes,
  }
}
