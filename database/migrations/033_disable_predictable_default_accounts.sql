-- Disable legacy accounts that still use the publicly known bcrypt test hash.
UPDATE users
SET is_active = 0,
    force_password_change = 1
WHERE email IN ('admin@pos.com', 'cashier@pos.com')
  AND password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

DELETE t
FROM tokens t
JOIN users u ON u.id = t.user_id
WHERE u.email IN ('admin@pos.com', 'cashier@pos.com')
  AND u.password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

DELETE rt
FROM refresh_tokens rt
JOIN users u ON u.id = rt.user_id
WHERE u.email IN ('admin@pos.com', 'cashier@pos.com')
  AND u.password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
