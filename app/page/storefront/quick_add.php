<?php
// One-click add of the cheapest in-stock variant at its minimum increment; the cart re-validates price and stock.
$quickVariant = null;
foreach ($quickProduct['variants'] as $candidate) {
    if (!(int)$candidate['enabled'] || $candidate['available_milli'] === null || (int)$candidate['available_milli'] < (int)$candidate['qty_increment_milli']) continue;
    if ($quickVariant === null || (int)$candidate['unit_price_minor'] < (int)$quickVariant['unit_price_minor']) $quickVariant = $candidate;
}
$quickBase = rtrim(StorefrontController::url(), '/');
$quickReturn = ltrim(substr((string)($_SERVER['REQUEST_URI'] ?? ''), strlen($quickBase)), '/');
?>
<?php if ($quickVariant): ?><form class="quick-add" method="post" action="<?= $u('cart') ?>"><input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="variant_id" value="<?= $e($quickVariant['id']) ?>"><input type="hidden" name="quantity" value="<?= $q($quickVariant['qty_increment_milli']) ?>"><input type="hidden" name="return_to" value="<?= $e($quickReturn) ?>"><button class="<?= $e($quickClass) ?>" aria-label="Add <?= $e($q($quickVariant['qty_increment_milli']) . ' ' . $quickVariant['sell_unit']) ?> of <?= $e($quickProduct['name']) ?> to bag" title="Add <?= $e($q($quickVariant['qty_increment_milli']) . ' ' . $quickVariant['sell_unit']) ?> to bag"><?= $quickLabel ?></button></form>
<?php else: ?><span class="quick-add-unavailable"><?= $quickUnavailable ?? 'Out of stock' ?></span><?php endif; ?>
