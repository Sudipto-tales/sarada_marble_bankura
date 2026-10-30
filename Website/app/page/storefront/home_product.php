<?php
$choice = $material['variants'][0];
foreach ($material['variants'] as $candidate) if ((int)$candidate['unit_price_minor'] === (int)$material['min_price_minor']) { $choice = $candidate; break; }
$reference = $choice['original_price_minor'] ?? null;
$discount = $reference && (int)$reference > (int)$choice['unit_price_minor'] ? intdiv(((int)$reference - (int)$choice['unit_price_minor']) * 100, (int)$reference) : null;
?>
<article class="h-product">
<a class="h-product-image" href="<?= $u('product/'.$material['slug']) ?>"><?php if ($material['images']): ?><img src="<?= $u($material['images'][0]['path']) ?>" alt="<?= $e($material['images'][0]['alt_text'] ?: $material['name']) ?>" loading="lazy"><?php else: ?><span class="material-placeholder">Product photograph<br>coming soon</span><?php endif; ?></a>
<?php if ($discount): ?><span class="h-product-badge">−<?= $discount ?>%</span><?php endif; ?>
<form method="post" action="<?= $u('wishlist') ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= $e($material['id']) ?>"><button class="h-save" aria-label="Save <?= $e($material['name']) ?>"><?= $icon('heart') ?></button></form>
<h3><a href="<?= $u('product/'.$material['slug']) ?>"><?= $e($material['name']) ?></a></h3><p class="h-origin"><?= $e($choice['finish'] ?: $material['category_name']) ?></p>
<?php if (!empty($material['review_count'])): ?><a class="stone-rating" href="<?= $u('product/'.$material['slug']) ?>#reviews"><?= $stars($material['rating_tenths'], 'sm') ?><b><?= number_format($material['rating_tenths'] / 10, 1) ?></b><small>(<?= (int)$material['review_count'] ?>)</small></a><?php endif; ?>
<div class="h-prices"><?php if ((int)$choice['unit_price_minor'] > 0): ?><?php if ($discount): ?><del><?= $m($reference) ?></del><?php endif; ?><strong><?= $m($choice['unit_price_minor']) ?> <small>/ <?= $e($choice['sell_unit']) ?></small></strong><?php else: /* an unpriced variant would otherwise advertise "₹0.00" */ ?><strong class="price-unset">Price on request</strong><?php endif; ?></div>
<div class="h-product-bottom"><span><?= $material['in_stock'] ? 'In stock' : 'Unavailable' ?></span><?php $quickProduct = $material; $quickClass = 'h-add'; $quickLabel = $icon('bag');
$quickUnavailable = '<a class="h-add" href="' . $u('product/'.$material['slug']) . '#purchase" aria-label="Choose options for ' . $e($material['name']) . '">' . $icon('bag') . '</a>';
require __DIR__ . '/quick_add.php'; ?></div>
</article>
