<?php
// DLV-PIN-01: delivery zones, PIN serviceability, product override precedence, strict mode, bulk import, cache version.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
$admin = CommerceAccess::provision('dlv-admin@example.test', 'Disposable-Dlv-Password');
$customer = CommerceTest::user('dlv-cust@example.test');
$svc = new DeliveryService($pdo);

// --- PIN format and empty state ---
$check(DeliveryService::validPin('722101') && DeliveryService::validPin('999999')
    && !DeliveryService::validPin('022101') && !DeliveryService::validPin('72210') && !DeliveryService::validPin('abc123'),
    'PIN format accepts a leading 1-9 and exactly six digits.');
$check($svc->resolve('722101') === null, 'An unknown PIN resolves to no zone.');
$check($svc->version() === 1, 'Delivery cache version is seeded at 1.');

// --- Zone creation advances the cache version ---
$zid = $svc->saveZone($admin, ['name' => 'Bankura District', 'eta_min_days' => 2, 'eta_max_days' => 4, 'shipping' => '50.00', 'active' => 1], 'dlv:zone:1');
$check($zid > 0 && $svc->version() === 2, 'Creating a zone advances the delivery cache version.');

// --- Bulk import validates, dedupes and reports rejects ---
$result = $svc->addPincodes($admin, $zid, "722101, 722102\n999999 72210X\n722101", 'dlv:pins:1');
$check($result['added'] === 3 && $result['rejected'] === 1, 'Bulk import adds 3 unique valid PINs and rejects one invalid token.');
$check($svc->version() === 3, 'Mapping PINs advances the delivery cache version.');

// --- Resolution ---
$zone = $svc->resolve('722101');
$check($zone && $zone['name'] === 'Bankura District' && (int)$zone['eta_min_days'] === 2 && (int)$zone['shipping_minor'] === 5000,
    'A mapped PIN resolves to its active zone with ETA and shipping.');

// --- forProduct: zone path and lenient unknown ---
$noOverride = ['delivery_pincodes' => [], 'delivery_min_days' => null, 'delivery_max_days' => null];
$r = $svc->forProduct($noOverride, '722101');
$check($r['status'] === 'available' && $r['eta_min'] === 2 && $r['shipping_minor'] === 5000, 'A product with no PIN list uses the zone.');
$check($svc->forProduct($noOverride, '333333')['status'] === 'unconfigured', 'An unknown PIN is lenient (contact the store) by default.');

// --- Product override narrows, regardless of zones ---
$override = ['delivery_pincodes' => ['555555'], 'delivery_min_days' => 3, 'delivery_max_days' => 7];
$check($svc->forProduct($override, '555555')['status'] === 'available', 'A product PIN list allows its own PIN.');
$check($svc->forProduct($override, '722101')['status'] === 'unavailable', 'A product PIN list denies a PIN outside it even where a zone serves it.');
CommerceTest::rejects(fn() => $svc->forProduct($noOverride, '12'), 422, 'forProduct rejects a malformed PIN.');

// --- Strict mode refuses unknown PINs ---
$settings = ['tax_bps' => 0, 'shipping' => '0', 'advance_bps' => 0, 'advance_min_order' => '0',
    'verify_autoconfirm_hours' => 48, 'payment_window_hours' => 48, 'delivery_strict' => 1, 'cod_enabled' => 0, 'razorpay_enabled' => 1];
(new SettingsService($pdo))->save($admin, $settings, 'dlv:settings:strict');
$check($svc->forProduct($noOverride, '333333')['status'] === 'unavailable', 'Strict mode refuses an unknown PIN on the product page.');
$check($svc->serviceable('722101') && !$svc->serviceable('333333') && !$svc->serviceable('72210'),
    'serviceable() allows a zoned PIN and refuses an unknown or malformed one under strict mode.');

// --- Permission and idempotency ---
CommerceTest::rejects(fn() => $svc->saveZone($customer, ['name' => 'Nope', 'eta_min_days' => 1, 'eta_max_days' => 2], 'dlv:zone:cust'), 403, 'A non-admin cannot manage delivery zones.');
$versionBefore = $svc->version();
$replay = $svc->saveZone($admin, ['name' => 'Ignored rename', 'eta_min_days' => 9, 'eta_max_days' => 9], 'dlv:zone:1');
$check($replay === $zid && $svc->version() === $versionBefore && $svc->resolve('722101')['name'] === 'Bankura District',
    'Replaying a zone operation key returns the same zone without a second write.');

echo 'DeliveryServiceTest (' . CommerceTest::$version . '): ' . CommerceTest::$checks . " checks passed.\n";
