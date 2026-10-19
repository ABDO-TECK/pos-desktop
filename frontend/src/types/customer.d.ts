declare global {
  interface Customer {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    balance: number;
    initial_balance?: number;
    loyalty_points?: number;
    created_at?: string;
  }

  interface PaymentPayload {
    amount: number;
    type: string;
    notes?: string;
    description?: string;
  }

  interface LedgerEntry {
    id: number;
    type: 'debit' | 'credit';
    amount: number;
    description: string;
    invoice_id: number | null;
    created_by: number | null;
    created_at: string;
  }

  type CustomerLedgerEntryType = 'debit' | 'credit' | 'initial' | 'opening';

  interface CustomerLedgerRow {
    id: number | null;
    date: string;
    description: string | null;
    debit: number;
    credit: number;
    balance: number;
    type: CustomerLedgerEntryType;
    invoice_id?: number | null;
  }

  interface CustomerLedgerData {
    customer: Customer;
    entries: CustomerLedgerRow[];
    balance: number;
    total_entries: number;
    truncated: boolean;
  }
}

export {};
