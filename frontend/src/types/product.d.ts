declare global {
  interface Product {
    id: number;
    barcode: string;
    name: string;
    category_id: number | null;
    category_name?: string;
    price: number;
    cost: number;
    quantity: number;
    purchase_price?: number;
    sale_price?: number;
    stock_quantity?: number;
    sell_by_weight?: 0 | 1;
    units_per_box?: number;
    box_barcode?: string | null;
    additional_barcodes?: string[];
    low_stock_threshold?: number;
    created_at?: string;
    updated_at?: string;
    deleted_at?: string | null;
    _deleted?: boolean;
    unit_type?: 'piece' | 'weight' | 'liter';
    parent_product_id?: number | null;
    size_name?: string | null;
    sizes?: Product[];
  }

  interface ProductCatalogPage {
    products: Product[];
    scope: string;
    version: number;
    pagination: {
      type: 'cursor';
      mode: 'snapshot' | 'delta';
      limit: number;
      hasMore: boolean;
      truncated: boolean;
      reset: boolean;
      nextCheckpoint: string;
    };
  }

  interface Category {
    id: number;
    name: string;
    description: string | null;
    created_at?: string;
  }
}

export {};
