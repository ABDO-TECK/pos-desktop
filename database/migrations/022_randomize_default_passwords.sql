-- ============================================================
-- Migration 022: تأمين كلمات المرور الافتراضية
-- ============================================================
-- يتحقق إذا كانت كلمة مرور admin أو cashier لا تزال الافتراضية (password)
-- ويفرض تغييرها عند أول دخول.
-- ملاحظة: لا نُغيّر كلمة المرور هنا لأن ذلك يتطلب PHP (password_hash).
-- بدلاً من ذلك، نتأكد أن force_password_change مُفعّل لكل المستخدمين
-- الذين لديهم كلمة المرور الافتراضية.

UPDATE users
SET force_password_change = 1
WHERE password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
  AND force_password_change = 0;
