<?php

namespace App\Services;


class AuthService {
    private ?array $user = null;
    private int $branchId = 1;
    private string $apiVersion = 'v1';

    // ── Static accessor (مؤقت — للتوافق مع الكود الذي لا يمكنه الوصول لـ Container) ──
    private static int $globalBranchId = 1;

    public function setUser(array $user): void {
        $this->user = $user;
    }

    /**
     * الحصول على بيانات المستخدم الحالي.
     *
     * @return array|null بيانات المستخدم أو null إذا لم يكن مسجلاً الدخول
     */
    public function user(): ?array {
        return $this->user;
    }

    /**
     * الحصول على بيانات المستخدم الحالي (alias for user()).
     *
     * @return array|null بيانات المستخدم أو null إذا لم يكن مسجلاً الدخول
     */
    public function getCurrentUser(): ?array {
        return $this->user();
    }

    /**
     * التحقق مما إذا كان المستخدم يمتلك صلاحية محددة.
     *
     * @param int|null $userId معرّف المستخدم (اختياري / للتوافق)
     * @param string $permission اسم الصلاحية
     * @return bool
     */
    public function hasPermission(?int $userId, string $permission): bool {
        if ($this->user === null) {
            return false;
        }
        if (($this->user['role'] ?? '') === 'admin') {
            return true;
        }
        return \App\Middleware\PermissionMiddleware::allows($this, $permission);
    }

    /**
     * الحصول على معرّف المستخدم الحالي.
     *
     * @return int|null معرّف المستخدم أو null
     */
    public function id(): ?int {
        return $this->user['id'] ?? null;
    }

    /**
     * الحصول على صلاحية/دور المستخدم الحالي.
     *
     * @return string|null دور المستخدم أو null
     */
    public function role(): ?string {
        return $this->user['role'] ?? null;
    }

    /**
     * التحقق مما إذا كان هناك مستخدم مسجل الدخول.
     *
     * @return bool صحيح إذا كان مسجلاً الدخول، وإلا خطأ
     */
    public function check(): bool {
        return $this->user !== null;
    }

    // ── Branch ID ──────────────────────────────────────────────

    public function setBranchId(int $branchId): void {
        $this->branchId = $branchId;
        self::$globalBranchId = $branchId; // تحديث القيمة الثابتة أيضاً
    }

    /**
     * الحصول على معرّف الفرع الحالي.
     *
     * @return int معرّف الفرع
     */
    public function branchId(): int {
        return $this->branchId;
    }

    /**
     * الحصول على معرّف الفرع العام الثابت.
     *
     * @deprecated Use injected AuthService::branchId() instead.
     *             This static accessor exists for backward compatibility with code
     *             that cannot access the DI container (e.g., Model classes).
     *             Callers should be migrated to receive AuthService via constructor injection.
     *
     * @return int معرّف الفرع العام
     */
    public static function getGlobalBranchId(): int {
        return self::$globalBranchId;
    }

    // ── API Version ───────────────────────────────────────────

    public function setApiVersion(string $version): void {
        $this->apiVersion = $version;
    }

    /**
     * الحصول على إصدار واجهة برمجة التطبيقات (API) الحالي.
     *
     * @return string إصدار الـ API
     */
    public function apiVersion(): string {
        return $this->apiVersion;
    }
}
