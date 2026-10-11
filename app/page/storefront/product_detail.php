<?php $title = $product['name']; require __DIR__ . '/header.php'; $csrf = RequestSecurity::csrfToken(); ?>
<h1><?= $e($product['name']) ?></h1><p><?= $e($product['description']) ?></p>
<div class="grid"><?php foreach ($product['images'] as $image): ?><img loading="lazy" src="<?= $u($image['path']) ?>" alt="<?= $e($image['alt_text']) ?>"><?php endforeach; ?></div>
<h2>Choose a variant</h2><div class="grid"><?php foreach ($product['variants'] as $variant): ?>
<form method="post" action="<?= $u('cart') ?>">
<h3><?= $e($variant['sku']) ?></h3><p><?= $e($variant['finish']) ?> · <?= $m($variant['unit_price_minor']) ?> per <?= $e($variant['sell_unit']) ?></p>
<?php if ($variant['coverage_sqft_milli'] !== null): ?><p>Coverage: <?= $q($variant['coverage_sqft_milli']) ?> sqft per <?= $e($variant['sell_unit']) ?></p><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="variant_id" value="<?= $e($variant['id']) ?>">
<input type="hidden" name="cart_id" value="<?= $e($cart['id'] ?? '') ?>"><input type="hidden" name="cart_revision" value="<?= $e($cart['revision']) ?>">
<label>Quantity (<?= $e($variant['sell_unit']) ?>)<input name="quantity" type="number" min="<?= $q($variant['qty_increment_milli']) ?>" step="<?= $q($variant['qty_increment_milli']) ?>" value="<?= $q($variant['qty_increment_milli']) ?>" required></label>
<small>Sold in increments of <?= $q($variant['qty_increment_milli']) ?> <?= $e($variant['sell_unit']) ?>. Adding replaces this variant's cart quantity.</small>
<?php if ($variant['available_milli'] !== null && (int)$variant['available_milli'] >= (int)$variant['qty_increment_milli']): ?><button>Add to cart</button><?php else: ?><p>Currently unavailable</p><?php endif; ?>
</form><?php endforeach; ?></div>
<?php require __DIR__ . '/footer.php'; ?>
