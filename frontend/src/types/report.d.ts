declare global {
  interface DailyReport {
    date: string;
    summary: {
      total_revenue: number;
      total_cost: number;
      total_profit: number;
      total_expenses: number;
      net_profit: number;
      total_invoices: number;
      total_discount: number;
      total_tax: number;
    } | null;
    invoices: Array<{
      id: number;
      cashier_name: string;
      total: number;
      payment_method: string;
      created_at: string;
    }>;
  }

  interface MonthlySummary {
    month: string;
    total_sales: number;
    total_invoices: number;
  }

  interface TopProduct {
    product_id: number;
    product_name: string;
    total_quantity: number;
    total_revenue: number;
  }

  interface ReportSummary {
    today_sales: number;
    today_invoices: number;
    month_sales: number;
    month_invoices: number;
    total_products: number;
    low_stock_count: number;
  }

  interface ProfitReport {
    total_revenue: number;
    total_cost: number;
    gross_profit: number;
    items: { product_name: string; revenue: number; cost: number; profit: number }[];
  }
}

export {};
