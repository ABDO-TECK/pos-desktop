declare global {
  type UserRole = 'admin' | 'cashier';

  interface User {
    id: number;
    name: string;
    username?: string;
    email: string;
    password?: string;
    role: UserRole;
    branch_id: number;
    is_active?: 0 | 1;
    force_password_change?: number;
    created_at?: string;
    updated_at?: string;
  }

  interface UserUpdatePayload {
    name: string;
    email: string;
    role?: UserRole;
    is_active?: 0 | 1;
    password?: string;
    current_password?: string;
  }

  interface AuthResponse {
    user: User;
    message?: string;
    data?: User; // To allow res.data.data access
  }
}

export {};
