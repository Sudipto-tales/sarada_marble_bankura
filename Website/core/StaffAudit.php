<?php

require_once __DIR__ . '/CommerceAccess.php';

/** Required staff effects and audit share one caller-owned SQL transaction. */
final class StaffAudit
{
    private const ACTIONS = [
        'product.save' => ['admin.products.edit', 'product'], 'product.archive' => ['admin.products.edit', 'product'],
        'category.save' => ['admin.products.edit', 'category'], 'brand.save' => ['admin.products.edit', 'brand'],
        'image.save' => ['admin.products.edit', 'product'], 'image.remove' => ['admin.products.edit', 'product'],
        'inventory.adjust' => ['admin.inventory.adjust', 'variant'], 'order.transition' => ['admin.orders.edit', 'order'],
        'settings.save' => ['admin.settings.edit', 'settings'], 'pricing.save' => ['admin.pricing.edit', 'pricing_rule'],
        'access.assign' => ['admin.access.edit', 'user'], 'access.revoke' => ['admin.access.edit', 'user'],
    ];
    private const FIELDS = ['revision', 'status', 'enabled', 'featured', 'slug', 'sku', 'name', 'category_id', 'brand_id',
        'variant_ids', 'unit_price_minor', 'on_hand_milli', 'reserved_milli', 'delta_milli', 'reason', 'role', 'role_id',
        'payment_mode', 'shipping_minor', 'tax_bps', 'cod_enabled', 'pricing_enabled', 'factor_ppm', 'min_minor', 'max_minor',
        'sell_unit', 'qty_increment_milli', 'coverage_sqft_milli', 'image_id', 'rule_type', 'starts_at', 'ends_at'];

    public static function record(PDO $connection, int $actorId, string $action, string $targetType, ?int $targetId,
        string $operationKey, array $changes, ?string $requestId = null): int
    {
        global $pdo;
        if ($connection !== $pdo) throw new LogicException('Audit and commerce effects must use the bootstrapped PDO.');
        if (!$connection->inTransaction()) throw new LogicException('Required audit must be inside the mutation transaction.');
        $contract = self::ACTIONS[$action] ?? null;
        if (!$contract || $contract[1] !== $targetType || ($targetId !== null && $targetId < 1)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,99}\z/', $operationKey)) {
            throw new RequestRejected(422, 'invalid_audit', 'Invalid audit operation.');
        }
        CommerceAccess::requirePermission($contract[0], $actorId);
        $safe = [];
        foreach ($changes as $key => $value) {
            // Allowlist: passwords, tokens, addresses and arbitrary request fields are discarded.
            if (!in_array($key, self::FIELDS, true)) continue;
            if (is_array($value)) {
                if (count($value) > 50 || array_filter($value, static fn($v): bool => !is_int($v))) {
                    throw new RequestRejected(422, 'invalid_audit', 'Audit values are invalid.');
                }
            } elseif (!is_int($value) && !is_bool($value) && $value !== null
                && (!is_string($value) || strlen($value) > 190 || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value))) {
                throw new RequestRejected(422, 'invalid_audit', 'Audit values are invalid.');
            }
            $safe[$key] = $value;
        }
        ksort($safe);
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096) throw new RequestRejected(422, 'invalid_audit', 'Audit changes are too large.');
        $requestId ??= bin2hex(random_bytes(16));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $requestId)) throw new RequestRejected(422, 'invalid_audit', 'Request identifier is invalid.');
        $query = $connection->prepare('SELECT * FROM admin_activity_log WHERE operation_key = ?'
            . ($connection->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : ''));
        $query->execute([$operationKey]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['actor_user_id'] !== $actorId || $existing['action'] !== $action || $existing['target_type'] !== $targetType
                || ($existing['target_id'] === null ? null : (int)$existing['target_id']) !== $targetId || $existing['changes_json'] !== $json) {
                throw new RequestRejected(409, 'audit_key_conflict', 'Operation key has different inputs.');
            }
            return (int)$existing['id'];
        }
        $connection->prepare('INSERT INTO admin_activity_log (actor_user_id, action, target_type, target_id, operation_key, request_id, changes_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$actorId, $action, $targetType, $targetId, $operationKey, $requestId, $json, gmdate('Y-m-d\TH:i:s\Z')]);
        return (int)$connection->lastInsertId();
    }
}
