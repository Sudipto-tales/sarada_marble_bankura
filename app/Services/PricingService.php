<?php

/**
 * PRC-01 bounded, default-off dynamic pricing. The effective unit price is the catalog base multiplied by
 * the winning demand → inventory → campaign → membership factors (one rule per factor, chosen by priority
 * then scope specificity), each applied with integer half-up rounding, then clamped to the approved
 * floor/ceiling. Demand/inventory factors come from published daily metrics and current stock bands; they
 * are initial configurable policy, never measured individual willingness-to-pay. With pricing disabled, or
 * with no active rules or metrics, the base price is returned unchanged (never zero). Checkout persists the
 * applied rule version; existing order snapshots never change when rules later change.
 */
final class PricingService
{
    public const FACTOR_TYPES = ['demand', 'inventory', 'campaign', 'membership'];
    private const NEUTRAL = 1000000; // parts per million

    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    public static function enabled(): bool { return filter_var(env('PRICING_ENABLED', false), FILTER_VALIDATE_BOOLEAN); }

    public static function version(PDO $pdo): int
    {
        return (int) CommerceDatabase::query($pdo, "SELECT version FROM catalog_versions WHERE namespace='pricing'")->fetchColumn();
    }

    public static function advanceVersion(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Pricing version must advance inside its mutation transaction.');
        if (CommerceDatabase::query($pdo, "UPDATE catalog_versions SET version=version+1 WHERE namespace='pricing'")->rowCount() !== 1) {
            throw new RuntimeException('Pricing version is unavailable.');
        }
    }

    /** Effective unit price (integer paise) and the rule version applied. Default-off returns the base. */
    public function effective(int $productId, int $categoryId, int $variantId, int $baseMinor, ?int $now = null): array
    {
        $base = CommerceValues::integer($baseMinor, 0, CommerceValues::MAX_MONEY, 'base_minor');
        $version = self::version($this->pdo);
        if (!self::enabled()) return ['unit_price_minor' => $base, 'base_minor' => $base, 'rule_version' => $version];
        $now ??= CommerceDatabase::clock($this->pdo);
        $rules = $this->activeRules($productId, $categoryId, $now);
        $price = $base; $floor = null; $ceiling = null;
        foreach (self::FACTOR_TYPES as $type) {
            $rule = $rules[$type] ?? null;
            if (!$rule) continue;
            $factor = match ($type) {
                'demand' => $this->demandFactor($productId, $now),
                'inventory' => $this->inventoryFactor($variantId),
                default => CommerceValues::integer($rule['factor_ppm'], 1, 100000000, 'factor_ppm'),
            };
            $price = self::applyFactor($price, $factor);
            if ($rule['floor_minor'] !== null) $floor = max($floor ?? 0, (int) $rule['floor_minor']);
            if ($rule['ceiling_minor'] !== null) $ceiling = $ceiling === null ? (int) $rule['ceiling_minor'] : min($ceiling, (int) $rule['ceiling_minor']);
        }
        if ($floor !== null && $price < $floor) $price = $floor;
        if ($ceiling !== null && $price > $ceiling) $price = $ceiling;
        if ($price > CommerceValues::MAX_MONEY) CommerceValues::invalid('unit_price_minor');
        return ['unit_price_minor' => $price, 'base_minor' => $base, 'rule_version' => $version];
    }

    /** price * factorPpm / 1,000,000 with checked multiplication (overflow is rejected) and half-up rounding. */
    private static function applyFactor(int $price, int $factorPpm): int
    {
        return CommerceValues::round(CommerceValues::multiply($price, $factorPpm), 1000000);
    }

    /** One winning rule per factor type: highest priority, then product > category > global, then lowest id. */
    private function activeRules(int $productId, int $categoryId, int $now): array
    {
        $rank = "CASE scope WHEN 'product' THEN 2 WHEN 'category' THEN 1 ELSE 0 END";
        $rows = $this->query("SELECT * FROM pricing_rules WHERE enabled=1
            AND (effective_from IS NULL OR effective_from<=?) AND (effective_to IS NULL OR effective_to>?)
            AND (scope='global' OR (scope='category' AND scope_id=?) OR (scope='product' AND scope_id=?))
            ORDER BY priority DESC, $rank DESC, id ASC", [$now, $now, $categoryId, $productId])->fetchAll();
        $winner = [];
        foreach ($rows as $row) { $type = $row['factor_type']; if (!isset($winner[$type])) $winner[$type] = $row; }
        return $winner;
    }

    /** Demand band: A = accepted net product-orders in the last 7 days, B = the preceding 28 days. */
    private function demandFactor(int $productId, int $now): int
    {
        $today = gmdate('Y-m-d', $now); $d7 = gmdate('Y-m-d', $now - 7 * 86400); $d35 = gmdate('Y-m-d', $now - 35 * 86400);
        $count = fn(string $from, string $to): int => (int) $this->query(
            'SELECT COALESCE(SUM(orders_net),0) FROM product_daily_metrics WHERE product_id=? AND metric_date>? AND metric_date<=?',
            [$productId, $from, $to])->fetchColumn();
        $a = max(0, $count($d7, $today)); $b = max(0, $count($d35, $d7));
        if ($b < 3) return self::NEUTRAL;            // insufficient support (or missing metrics)
        if (8 * $a >= 3 * $b) return 1050000;        // high demand
        if (8 * $a < $b) return 950000;              // low demand
        return self::NEUTRAL;                        // normal
    }

    /** Inventory band: plentiful stock trims the price slightly; no scarcity surcharge initially. */
    private function inventoryFactor(int $variantId): int
    {
        $stock = $this->query('SELECT on_hand_milli,reserved_milli FROM inventory WHERE variant_id=?', [$variantId])->fetch();
        if (!$stock) return self::NEUTRAL;
        $onHand = (int) $stock['on_hand_milli']; $available = max(0, $onHand - (int) $stock['reserved_milli']);
        return ($onHand > 0 && 4 * $available >= 3 * $onHand) ? 980000 : self::NEUTRAL;
    }

    /** Admin rule upsert: validated, audited, idempotent on the operation key, advancing the pricing version. */
    public function saveRule(int $actorId, array $input, string $key): array
    {
        CommerceAccess::requirePermission('admin.pricing.edit', $actorId);
        $key = CommerceValues::key($key);
        $factorType = $input['factor_type'] ?? '';
        if (!in_array($factorType, self::FACTOR_TYPES, true)) CommerceValues::invalid('factor_type');
        $scope = $input['scope'] ?? '';
        if (!in_array($scope, ['global', 'category', 'product'], true)) CommerceValues::invalid('scope');
        $scopeId = $scope === 'global' ? null : CommerceValues::id($input['scope_id'] ?? null, 'scope_id');
        // Demand/inventory factors are computed from bands; their stored factor stays neutral.
        $factorPpm = in_array($factorType, ['demand', 'inventory'], true)
            ? self::NEUTRAL : CommerceValues::integer($input['factor_ppm'] ?? null, 1, 100000000, 'factor_ppm');
        $floor = ($input['floor_minor'] ?? null) === null || $input['floor_minor'] === '' ? null : CommerceValues::integer($input['floor_minor'], 0, CommerceValues::MAX_MONEY, 'floor_minor');
        $ceiling = ($input['ceiling_minor'] ?? null) === null || $input['ceiling_minor'] === '' ? null : CommerceValues::integer($input['ceiling_minor'], 0, CommerceValues::MAX_MONEY, 'ceiling_minor');
        if ($floor !== null && $ceiling !== null && $floor > $ceiling) CommerceValues::invalid('floor_minor');
        $priority = CommerceValues::integer($input['priority'] ?? 0, 0, 1000000, 'priority');
        $from = ($input['effective_from'] ?? null) === null || $input['effective_from'] === '' ? null : CommerceValues::integer($input['effective_from'], 0, PHP_INT_MAX, 'effective_from');
        $to = ($input['effective_to'] ?? null) === null || $input['effective_to'] === '' ? null : CommerceValues::integer($input['effective_to'], 0, PHP_INT_MAX, 'effective_to');
        if ($from !== null && $to !== null && $from >= $to) CommerceValues::invalid('effective_to');
        $enabled = CommerceValues::flag($input['enabled'] ?? 0, 'enabled');
        $id = isset($input['id']) ? CommerceValues::id($input['id']) : null;
        return CommerceDatabase::transaction($this->pdo, function () use ($actorId, $id, $factorType, $scope, $scopeId, $factorPpm, $floor, $ceiling, $priority, $from, $to, $enabled, $key): array {
            CommerceAccess::requirePermission('admin.pricing.edit', $actorId);
            if ($this->query('SELECT id FROM admin_activity_log WHERE operation_key=?' . CommerceDatabase::lock($this->pdo), [$key])->fetchColumn()) {
                $existing = $this->query('SELECT id,revision FROM pricing_rules WHERE id=(SELECT target_id FROM admin_activity_log WHERE operation_key=?)', [$key])->fetch();
                return ['id' => (int) ($existing['id'] ?? 0), 'revision' => (int) ($existing['revision'] ?? 0)];
            }
            $now = CommerceDatabase::clock($this->pdo);
            if ($id !== null) {
                $rule = $this->query('SELECT * FROM pricing_rules WHERE id=?' . CommerceDatabase::lock($this->pdo), [$id])->fetch();
                if (!$rule) throw new RequestRejected(404, 'not_found', 'Pricing rule not found.');
                $this->query('UPDATE pricing_rules SET factor_type=?,scope=?,scope_id=?,factor_ppm=?,floor_minor=?,ceiling_minor=?,priority=?,effective_from=?,effective_to=?,enabled=?,revision=revision+1,actor_user_id=?,updated_at=? WHERE id=?',
                    [$factorType, $scope, $scopeId, $factorPpm, $floor, $ceiling, $priority, $from, $to, $enabled, $actorId, $now, $id]);
                $revision = (int) $rule['revision'] + 1;
            } else {
                $this->query('INSERT INTO pricing_rules (factor_type,scope,scope_id,factor_ppm,floor_minor,ceiling_minor,priority,effective_from,effective_to,enabled,revision,actor_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,?)',
                    [$factorType, $scope, $scopeId, $factorPpm, $floor, $ceiling, $priority, $from, $to, $enabled, $actorId, $now, $now]);
                $id = (int) $this->pdo->lastInsertId(); $revision = 1;
            }
            self::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo, $actorId, 'pricing.save', 'pricing_rule', $id, $key, ['enabled' => $enabled, 'factor_ppm' => $factorPpm, 'revision' => $revision]);
            return ['id' => $id, 'revision' => $revision];
        });
    }
}
