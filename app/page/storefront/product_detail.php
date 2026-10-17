<?php
$title = $product['name']; $page = 'product';
$pageStyles = ['product-detail.css']; $pageScripts = ['product-detail.js'];
require __DIR__ . '/header.php';
$csrf = RequestSecurity::csrfToken();
$available = static fn(array $v): bool => $v['available_milli'] !== null && (int)$v['available_milli'] >= (int)$v['qty_increment_milli'];
$first = current(array_filter($product['variants'], $available)) ?: $product['variants'][0];
$hasPreview = (bool)array_filter($product['images'], static fn(array $image): bool => $image['variant_id'] === null || in_array((int)$image['variant_id'], array_map('intval', array_column($product['variants'], 'id')), true));
?>
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= $u() ?>">Home</a><span>/</span><a href="<?= $u('category/' . $product['category_slug']) ?>"><?= $e($product['category_name']) ?></a><span>/</span><span aria-current="page"><?= $e($product['name']) ?></span></nav>
<section class="detail-layout pdp-hero" aria-label="Product details">
  <div class="php-gallery">
    <?php if ($product['images']): $primary = $product['images'][0]; ?>
    <a class="gallery-main" id="gallery-link" href="<?= $u($primary['path']) ?>" target="_blank" rel="noopener"><img id="product-image" src="<?= $u($primary['path']) ?>" alt="<?= $e($primary['alt_text'] ?: $product['name']) ?>"><span>View full image ⤢</span></a>
    <div class="gallery-thumbs" aria-label="Product photographs"><?php foreach ($product['images'] as $index => $image): ?><a href="<?= $u($image['path']) ?>" data-gallery-image="<?= $u($image['path']) ?>" data-gallery-alt="<?= $e($image['alt_text'] ?: $product['name']) ?>" aria-label="Product photograph <?= $e($index + 1) ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?>><img loading="lazy" src="<?= $u($image['path']) ?>" alt="<?= $e($image['alt_text'] ?: $product['name']) ?>"></a><?php endforeach; ?></div>
    <p class="pdp-image-caption"><?= count($product['images']) ?> product <?= count($product['images']) === 1 ? 'photograph' : 'photographs' ?> · Select a thumbnail to explore</p>
    <?php else: ?><div class="material-placeholder php-detail-placeholder">Natural stone<br>Product photographs coming soon</div><?php endif; ?>
    <p class="note php-stone-note">Every slab has its own character. Inspect the actual stone for colour and veining before selection.</p>
  </div>
  <div class="detail-copy">
    <div class="pdp-badges"><span class="pdp-chip"><?= $e($product['category_name']) ?></span><?php if ($product['featured']): ?><span class="pdp-chip">Featured selection</span><?php endif; ?><span class="pdp-chip <?= $product['in_stock'] ? 'pdp-stock' : '' ?>"><?= $product['in_stock'] ? 'In stock' : 'Currently unavailable' ?></span></div>
    <?php if ($product['brand_name']): ?><a class="pdp-brand" href="<?= $u('shop') ?>?brand=<?= $e($product['brand_slug']) ?>"><?= $e($product['brand_name']) ?> ↗</a><?php endif; ?>
    <h1><?= $e($product['name']) ?></h1>
    <p class="pdp-subtitle"><?= $e(implode(' · ', array_unique(array_filter(array_column($product['variants'], 'finish'))))) ?></p>
    <div class="pdp-price"><strong data-selected-price><?= $m($first['unit_price_minor']) ?></strong><span data-selected-unit>per <?= $e($first['sell_unit']) ?></span></div>
    <p class="note">Material price. Review delivery charges and taxes at checkout.</p>
    <div class="php-card-actions"><?php $saved = in_array((int)$product['id'], $navWishlist ?? [], true); ?><form method="post" action="<?= $u('wishlist') ?>" data-wishlist><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= $e($product['id']) ?>"><input type="hidden" name="action" value="<?= $saved ? 'remove' : 'add' ?>"><button class="text-button save-heart<?= $saved ? ' saved' : '' ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>"><span class="heart" aria-hidden="true">♥</span> Save material</button></form><form method="post" action="<?= $u('compare') ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= $e($product['id']) ?>"><button class="text-button">⇄ Compare</button></form></div>
    <div id="purchase" class="pdp-purchase">
      <h2>Choose your finish &amp; quantity</h2>
      <div class="pdp-variant-picker" hidden><label for="variant-picker">Variant</label><select id="variant-picker"><?php foreach ($product['variants'] as $variant): ?><option value="<?= $e($variant['id']) ?>" <?= (int)$variant['id'] === (int)$first['id'] ? 'selected' : '' ?>><?= $e(($variant['finish'] ?: $variant['sku']) . ' · ' . $variant['sell_unit']) ?> — <?= $m($variant['unit_price_minor']) ?><?= $available($variant) ? '' : ' · Unavailable' ?></option><?php endforeach; ?></select></div>
      <?php foreach ($product['variants'] as $variant): ?>
      <form id="purchase-<?= $e($variant['id']) ?>" class="php-variant" data-variant-content="<?= $e($variant['id']) ?>" data-price-minor="<?= $e($variant['unit_price_minor']) ?>" data-increment="<?= $e($variant['qty_increment_milli']) ?>" data-stock="<?= $available($variant) ? 'true' : 'false' ?>" data-unit="<?= $e($variant['sell_unit']) ?>" method="post" action="<?= $u('cart') ?>">
        <h3><?= $e($variant['finish'] ?: 'Natural finish') ?> <span><?= $e($variant['sku']) ?></span></h3>
        <p><?= $m($variant['unit_price_minor']) ?> per <?= $e($variant['sell_unit']) ?><?php if ($variant['coverage_sqft_milli'] !== null): ?> · <?= $q($variant['coverage_sqft_milli']) ?> sqft per <?= $e($variant['sell_unit']) ?><?php endif; ?></p>
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="variant_id" value="<?= $e($variant['id']) ?>"><input type="hidden" name="return_to" value="<?= $e('product/' . $product['slug']) ?>">
        <label for="quantity-<?= $e($variant['id']) ?>">Quantity (<?= $e($variant['sell_unit']) ?>)</label>
        <div class="pdp-quantity"><button type="button" data-quantity-step="-1" aria-label="Decrease quantity" hidden>−</button><input id="quantity-<?= $e($variant['id']) ?>" name="quantity" type="number" min="<?= $q($variant['qty_increment_milli']) ?>" max="1000000" step="<?= $q($variant['qty_increment_milli']) ?>" value="<?= $q($variant['qty_increment_milli']) ?>" required><button type="button" data-quantity-step="1" aria-label="Increase quantity" hidden>+</button></div>
        <small>Sold in increments of <?= $q($variant['qty_increment_milli']) ?> <?= $e($variant['sell_unit']) ?>. Adds to any quantity already in your bag.</small>
        <div class="pdp-estimate" hidden><span>Material estimate</span><output aria-live="polite"></output><small>Delivery and taxes are calculated at checkout.</small></div>
        <p class="pdp-availability"><?= $available($variant) ? 'Available: ' . $q($variant['available_milli']) . ' ' . $e($variant['sell_unit']) : 'Currently unavailable' ?></p>
        <div class="pdp-buy-actions"><button class="button" <?= $available($variant) ? '' : 'disabled' ?>><?= $available($variant) ? 'Add to cart' : 'Out of stock' ?></button><a class="button secondary" href="<?= $u('cart') ?>">View bag ↗</a></div>
        <a class="pdp-calculator" href="<?= $u('calculator') ?>?variant_id=<?= $e($variant['id']) ?>">▦ Not sure how much? Calculate your area ↗</a>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<div class="pdp-planning">
  <?php if ($hasPreview): ?><a class="pdp-preview" href="<?= $u('visualizer') ?>?product_id=<?= $e($product['id']) ?>"><span class="pdp-tool-icon" aria-hidden="true">◈</span><span><strong>See it in your space</strong><small>Preview this material on a room photograph.</small></span><span aria-hidden="true">↗</span></a><?php endif; ?>
  <a class="pdp-catalogue" href="<?= $u('catalogues') ?>"><span class="pdp-tool-icon" aria-hidden="true">▤</span><span><strong>Explore brand catalogues</strong><small>Browse the available collection books.</small></span><span aria-hidden="true">↗</span></a>
</div>
<nav class="pdp-section-nav" aria-label="Product sections"><a href="#about-stone">About this stone</a><a href="#specifications">Specifications</a><a href="#delivery">Delivery</a><a href="#reviews">Reviews</a><?php if ($related): ?><a href="#similar-products">Similar products</a><?php endif; ?></nav>
<div class="pdp-information">
  <section id="about-stone" class="pdp-section"><span class="eyebrow">The material</span><h2>About this stone</h2><p class="pdp-description"><?= $e($product['description'] ?: 'Explore the available finishes and specifications to choose a material for your project.') ?></p><a class="text-button" href="<?= $u('category/' . $product['category_slug']) ?>">Explore <?= $e($product['category_name']) ?> ↗</a></section>
  <section id="specifications" class="pdp-section"><span class="eyebrow">Know your stone</span><h2>Specifications</h2><p class="note">Details for each available variant.</p>
    <?php foreach ($product['variants'] as $variant):
      $specs = ['Stone type' => $product['category_name'], 'Brand' => $product['brand_name'], 'SKU' => $variant['sku'], 'Finish' => $variant['finish'],
        'Thickness' => $variant['thickness_mm_milli'] === null ? null : $q($variant['thickness_mm_milli']) . ' mm',
        'Length' => $variant['length_mm_milli'] === null ? null : $q($variant['length_mm_milli']) . ' mm',
        'Width' => $variant['width_mm_milli'] === null ? null : $q($variant['width_mm_milli']) . ' mm',
        'Selling unit' => $variant['sell_unit'], 'Minimum increment' => $q($variant['qty_increment_milli']) . ' ' . $variant['sell_unit'],
        'Coverage per ' . $variant['sell_unit'] => $variant['coverage_sqft_milli'] === null ? null : $q($variant['coverage_sqft_milli']) . ' sqft',
        'Available stock' => $variant['available_milli'] === null ? null : $q($variant['available_milli']) . ' ' . $variant['sell_unit']]; ?>
    <div data-variant-content="<?= $e($variant['id']) ?>"><h3><?= $e($variant['finish'] ?: $variant['sku']) ?></h3><dl class="pdp-specs"><?php foreach ($specs as $label => $value): if ($value === null || $value === '') continue; ?><div><dt><?= $e($label) ?></dt><dd><?= $e($value) ?></dd></div><?php endforeach; ?></dl></div>
    <?php endforeach; ?>
  </section>
  <section id="delivery" class="pdp-section"><span class="eyebrow">Plan your order</span><h2>Delivery &amp; selection</h2><p>Review your delivery address, available payment options and shipping charges during checkout. Contact the store to confirm site access, unloading arrangements and delivery timing before ordering.</p><p class="note">Natural colour and vein patterns vary between slabs. Confirm the exact material and finish for your project.</p></section>
  <section id="reviews" class="pdp-section"><span class="eyebrow">Customer voices</span><h2>Ratings &amp; reviews</h2><?php require __DIR__ . '/review_summary.php'; ?><a class="text-button" href="<?= $u('product/' . $product['slug'] . '/reviews') ?>"><?= $reviews['total'] ? 'All reviews &amp; write a review ↗' : 'Write a review ↗' ?></a></section>
</div>
<?php if ($related): ?><section id="similar-products" class="pdp-discovery"><div class="section-head"><div><span class="eyebrow">Keep exploring</span><h2>Similar products</h2><p class="note">More available materials in <?= $e($product['category_name']) ?>.</p></div><a class="text-button" href="<?= $u('category/' . $product['category_slug']) ?>">View collection ↗</a></div><div class="stone-grid"><?php $detailProduct = $product; foreach ($related as $product) require __DIR__ . '/product_card.php'; $product = $detailProduct; ?></div></section><?php endif; ?>
<?php if ($brands): ?><section class="pdp-discovery"><div class="section-head"><div><span class="eyebrow">Find your collection</span><h2>Shop by brands</h2></div></div><div class="pdp-brands"><?php foreach (array_slice($brands, 0, 12) as $brand): ?><a href="<?= $u('shop') ?>?brand=<?= $e($brand['slug']) ?>"><span><?= $e($brand['name']) ?></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
<div class="pdp-mobile-buy" aria-label="Purchase shortcuts"><div><strong data-selected-price><?= $m($first['unit_price_minor']) ?></strong><small data-selected-unit>per <?= $e($first['sell_unit']) ?></small></div><a href="#purchase" class="pdp-options">Options</a><button class="button" data-mobile-purchase form="purchase-<?= $e($first['id']) ?>" <?= $available($first) ? '' : 'disabled' ?>><?= $available($first) ? 'Add to cart' : 'Out of stock' ?></button></div>
<?php if ($product['images']): ?><dialog id="product-lightbox" aria-label="Full product photograph"><div class="pdp-lightbox-toolbar"><button type="button" data-photo-previous aria-label="Previous photograph">←</button><span data-photo-count></span><button type="button" data-photo-next aria-label="Next photograph">→</button><button type="button" data-photo-close aria-label="Close photograph">Close ×</button></div><img alt=""></dialog><?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
