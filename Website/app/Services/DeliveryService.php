<?php

/** PIN-code serviceability (DLV-PIN-01). A PIN maps to one active zone carrying an ETA window and a
    (display-only) shipping charge. Precedence for a product check: the product's own PIN list narrows
    first; otherwise the store zone table; otherwise unknown — lenient ("contact the store", still orderable)
    unless the admin has turned on strict mode. Zone/PIN writes advance the 'delivery' cache version
    transactionally so any downstream catalog cache is invalidated. */
final class DeliveryService
{
    private const MAX_IMPORT = 2000;

    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    public static function validPin(string $pin): bool { return (bool)preg_match('/\A[1-9][0-9]{5}\z/', $pin); }

    public function version(): int
    {
        try { return (int)$this->query("SELECT version FROM catalog_versions WHERE namespace='delivery'")->fetchColumn(); }
        catch (Throwable $error) { return 0; }
    }

    public static function advanceVersion(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Delivery version must advance inside its mutation transaction.');
        if (CommerceDatabase::query($pdo, "UPDATE catalog_versions SET version = version + 1 WHERE namespace = 'delivery'")->rowCount() !== 1)
            throw new RuntimeException('Delivery version is unavailable.');
    }

    /** The active zone serving a PIN, or null. Single indexed lookup against committed rows. */
    public function resolve(string $pin): ?array
    {
        if (!self::validPin($pin)) return null;
        $row = $this->query("SELECT z.* FROM delivery_pincodes p JOIN delivery_zones z ON z.id=p.zone_id
            WHERE p.pincode=? AND p.active=1 AND z.active=1 LIMIT 1", [$pin])->fetch();
        return $row ?: null;
    }

    /** Serviceability of one product at a PIN, for the product page. */
    public function forProduct(array $details, string $pin): array
    {
        if (!self::validPin($pin)) CommerceValues::invalid('postal_code');
        $productPins = $details['delivery_pincodes'] ?? [];
        if ($productPins) {
            if (!in_array($pin, $productPins, true))
                return ['status' => 'unavailable', 'message' => 'Online delivery is not configured for this PIN code. Contact the store.'];
            $min = $details['delivery_min_days']; $max = $details['delivery_max_days'];
            return ['status' => 'available', 'eta_min' => $min, 'eta_max' => $max, 'shipping_minor' => null,
                'message' => $min !== null && $max !== null ? 'Estimated delivery: ' . $min . '–' . $max . ' days.' : 'Delivery available to this PIN code.'];
        }
        $zone = $this->resolve($pin);
        if ($zone) return ['status' => 'available', 'zone' => $zone['name'], 'eta_min' => (int)$zone['eta_min_days'], 'eta_max' => (int)$zone['eta_max_days'],
            'shipping_minor' => (int)$zone['shipping_minor'], 'message' => 'Delivers to ' . $zone['name'] . ': ' . (int)$zone['eta_min_days'] . '–' . (int)$zone['eta_max_days'] . ' days.'];
        if ((new SettingsService($this->pdo))->effective()['delivery_strict'])
            return ['status' => 'unavailable', 'message' => 'We do not deliver to this PIN code yet. Contact the store.'];
        return ['status' => 'unconfigured', 'message' => 'Contact the store to confirm delivery to this PIN code.'];
    }

    /** Whether an address PIN is serviceable at the store level (used to bind checkout). Lenient mode
        treats an unknown PIN as serviceable so zones can be seeded gradually. */
    public function serviceable(string $pin): bool
    {
        if (!self::validPin($pin)) return false;
        if ($this->resolve($pin)) return true;
        return !(new SettingsService($this->pdo))->effective()['delivery_strict'];
    }

    // --- Admin zone management -------------------------------------------------

    public function zones(int $actorId): array
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId);
        return $this->query("SELECT z.*, (SELECT COUNT(*) FROM delivery_pincodes p WHERE p.zone_id=z.id) AS pincode_count
            FROM delivery_zones z ORDER BY z.name")->fetchAll();
    }

    public function zone(int $actorId, int $zoneId): ?array
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId);
        $zone = $this->query('SELECT * FROM delivery_zones WHERE id=?', [CommerceValues::id($zoneId)])->fetch();
        if (!$zone) return null;
        $zone['pincodes'] = $this->query('SELECT pincode FROM delivery_pincodes WHERE zone_id=? AND active=1 ORDER BY pincode', [$zoneId])->fetchAll(PDO::FETCH_COLUMN);
        return $zone;
    }

    public function saveZone(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId); $key = CommerceValues::key($key);
        $id = ($input['id'] ?? '') === '' ? null : CommerceValues::id($input['id']);
        $name = CommerceValues::text($input['name'] ?? null, 120, 'name');
        $etaMin = CommerceValues::integer($input['eta_min_days'] ?? null, 1, 365, 'eta_min_days');
        $etaMax = CommerceValues::integer($input['eta_max_days'] ?? null, 1, 365, 'eta_max_days');
        if ($etaMin > $etaMax) CommerceValues::invalid('eta_days');
        $shipping = CommerceValues::decimal(($input['shipping'] ?? '') === '' ? '0' : $input['shipping'], 2, CommerceValues::MAX_MONEY, 'shipping');
        $active = CommerceValues::flag($input['active'] ?? 1, 'active');
        return CommerceDatabase::transaction($this->pdo, function () use ($actorId, $id, $name, $etaMin, $etaMax, $shipping, $active, $key): int {
            CommerceAccess::requirePermission('admin.settings.edit', $actorId);
            $lock = CommerceDatabase::lock($this->pdo); $now = CommerceDatabase::clock($this->pdo);
            $prior = $this->query('SELECT target_id FROM admin_activity_log WHERE operation_key=?' . $lock, [$key])->fetch();
            if ($prior) return (int)$prior['target_id'];
            if ($id !== null) {
                if (!$this->query('SELECT id FROM delivery_zones WHERE id=?' . $lock, [$id])->fetchColumn()) throw new RequestRejected(404, 'not_found', 'Zone not found.');
                $this->query('UPDATE delivery_zones SET name=?,eta_min_days=?,eta_max_days=?,shipping_minor=?,active=?,updated_at=? WHERE id=?',
                    [$name, $etaMin, $etaMax, $shipping, $active, $now, $id]);
            } else {
                $this->query('INSERT INTO delivery_zones (name,eta_min_days,eta_max_days,shipping_minor,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                    [$name, $etaMin, $etaMax, $shipping, $active, $now, $now]);
                $id = (int)$this->pdo->lastInsertId();
            }
            StaffAudit::record($this->pdo, $actorId, 'delivery.zone', 'delivery_zone', $id, $key, ['name' => $name, 'active' => $active]);
            self::advanceVersion($this->pdo);
            return $id;
        });
    }

    /** Bulk-add PIN codes to a zone. Validates, dedupes and caps the batch; returns added/rejected counts. */
    public function addPincodes(int $actorId, int $zoneId, string $raw, string $key): array
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId); $zoneId = CommerceValues::id($zoneId); $key = CommerceValues::key($key);
        $tokens = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $valid = []; $rejected = 0;
        foreach ($tokens as $token) {
            if ($token === '') continue;
            if (self::validPin($token)) $valid[$token] = true; else $rejected++;
            if (count($valid) > self::MAX_IMPORT) CommerceValues::invalid('pincodes');
        }
        return CommerceDatabase::transaction($this->pdo, function () use ($actorId, $zoneId, $valid, $rejected, $key): array {
            CommerceAccess::requirePermission('admin.settings.edit', $actorId);
            $lock = CommerceDatabase::lock($this->pdo); $now = CommerceDatabase::clock($this->pdo);
            if ($this->query('SELECT id FROM admin_activity_log WHERE operation_key=?' . $lock, [$key])->fetchColumn())
                return ['added' => 0, 'rejected' => $rejected, 'replayed' => true];
            if (!$this->query('SELECT id FROM delivery_zones WHERE id=?' . $lock, [$zoneId])->fetchColumn()) throw new RequestRejected(404, 'not_found', 'Zone not found.');
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            // A PIN already mapped elsewhere is moved to this zone (one zone per PIN, enforced by the unique index).
            $sql = 'INSERT INTO delivery_pincodes (pincode,zone_id,active,created_at,updated_at) VALUES (?,?,1,?,?)';
            $sql .= $mysql ? ' ON DUPLICATE KEY UPDATE zone_id=VALUES(zone_id),active=1,updated_at=VALUES(updated_at)'
                : ' ON CONFLICT(pincode) DO UPDATE SET zone_id=excluded.zone_id,active=1,updated_at=excluded.updated_at';
            $added = 0;
            foreach (array_keys($valid) as $pin) { $this->query($sql, [$pin, $zoneId, $now, $now]); $added++; }
            StaffAudit::record($this->pdo, $actorId, 'delivery.pincodes', 'delivery_zone', $zoneId, $key, ['added' => $added, 'rejected' => $rejected]);
            self::advanceVersion($this->pdo);
            return ['added' => $added, 'rejected' => $rejected, 'replayed' => false];
        });
    }

    public function removePincode(int $actorId, string $pin, string $key): void
    {
        CommerceAccess::requirePermission('admin.settings.edit', $actorId); $key = CommerceValues::key($key);
        if (!self::validPin($pin)) CommerceValues::invalid('postal_code');
        CommerceDatabase::transaction($this->pdo, function () use ($actorId, $pin, $key): void {
            CommerceAccess::requirePermission('admin.settings.edit', $actorId);
            if ($this->query('SELECT id FROM admin_activity_log WHERE operation_key=?' . CommerceDatabase::lock($this->pdo), [$key])->fetchColumn()) return;
            $this->query('DELETE FROM delivery_pincodes WHERE pincode=?', [$pin]);
            StaffAudit::record($this->pdo, $actorId, 'delivery.pincode_remove', 'delivery_pincode', null, $key, ['pincode' => $pin]);
            self::advanceVersion($this->pdo);
        });
    }
}
