<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Cache;
use App\Helpers\Response;
use PDO;
use App\Requests\SettingsRequest;


class SettingsController extends Controller {

    private const EXPOSED_KEYS = [
        'store_name',
        'tax_enabled',
        'tax_rate',
        'prevent_negative_stock',
        'loyalty_enabled',
        'loyalty_points_per_rial',
        'loyalty_rial_per_point',
        'store_logo',
    ];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function all(): array {
        $cached = Cache::get('settings_all');
        if ($cached !== null) return $cached;

        $placeholders = implode(',', array_fill(0, count(self::EXPOSED_KEYS), '?'));
        $stmt = $this->db->prepare(
            "SELECT `key`, `value`
             FROM settings
             WHERE `key` IN ({$placeholders})"
        );
        $stmt->execute(self::EXPOSED_KEYS);
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        Cache::set('settings_all', $settings, 300); // 5 minutes
        return $settings;
    }

    public function index() {
        return Response::cacheable($this->all(), 300);
    }

    public function update() {
        $request = new SettingsRequest($this->getBody());
        $data = $request->validated();
        $allowed = self::EXPOSED_KEYS;

        return $this->withTransaction(function ($db) use ($data, $allowed) {
            $stmt = $db->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );

            foreach ($allowed as $key) {
                if (array_key_exists($key, $data)) {
                    $val = (string)$data[$key];
                    $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
                }
            }

            // Clear cache before re-fetching to ensure fresh data in the response.
            // The event dispatch also clears cache (via CacheSubscriber), but clearing
            // explicitly here guarantees correct ordering regardless of listener execution.
            Cache::forget('settings_all');
            \App\Helpers\EventDispatcher::dispatch('settings.updated');
            return Response::success($this->all(), 'Settings updated');
        });
    }
}
