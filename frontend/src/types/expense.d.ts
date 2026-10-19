declare global {
  interface ExpenseCategory {
    id: number;
    name: string;
    created_at?: string;
  }

  interface Expense {
    id: number;
    category_id: number;
    category_name?: string;
    user_id: number;
    user_name?: string;
    amount: number;
    notes: string | null;
    expense_date: string;
    created_at?: string;
    updated_at?: string;
  }
}

export {};
