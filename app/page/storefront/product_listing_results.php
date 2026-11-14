<?php
/**
 * Shop results fragment: toolbar, grid and pager. Rendered inside the full listing page and also
 * returned on its own (see product_listing_fragment.php) when the filter form / pager is driven by
 * product-listing.js, so filtering and paging swap this block without a full-page reload.
 * Needs: $products, $filters and the storefront view helpers ($e,$u,$m,$q,$icon,$stars,$navWishlist).
 */
$cur = (int)$products['page'];
$last = max(1, (int)$products['pages']);
$pageUrl = static fn(int $n): string => $u('shop') . '?' . $e(http_build_query(array_replace($filters, ['page' => $n])));
?>
<div class="collection-toolbar"><span><?= $e($products['total']) ?> materials</span><span>Page <?= $e($cur) ?> of <?= $e($last) ?></span></div>
<?php if (!$products['items']): ?><div class="empty-state"><h2>No products found matching your criteria.</h2><p>Try a different category or search.</p><a class="button" href="<?= $u('shop') ?>">View all materials ↗</a></div><?php endif; ?>
<div class="stone-grid"><?php foreach ($products['items'] as $product) require __DIR__ . '/product_card.php'; ?></div>
<?php if ($last > 1): ?>
<nav class="pagination" aria-label="Catalog pages"><?php if ($cur > 1): ?><a class="button secondary" data-page-link rel="prev" href="<?= $pageUrl($cur - 1) ?>">‹ Prev</a><?php endif; ?><?php
    $nums = [];
    for ($i = 1; $i <= $last; $i++) if ($i === 1 || $i === $last || ($i >= $cur - 1 && $i <= $cur + 1)) $nums[] = $i;
    $prev = 0;
    foreach ($nums as $n):
        if ($n - $prev > 1): ?><span class="pagination-gap" aria-hidden="true">…</span><?php endif; $prev = $n; ?><a class="button secondary<?= $n === $cur ? ' is-current' : '' ?>" data-page-link<?= $n === $cur ? ' aria-current="page"' : '' ?> href="<?= $pageUrl($n) ?>"><?= $e($n) ?></a><?php
    endforeach; ?><?php if ($cur < $last): ?><a class="button secondary" data-page-link rel="next" href="<?= $pageUrl($cur + 1) ?>">Next ›</a><?php endif; ?></nav>
<?php endif; ?>
