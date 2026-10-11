<?php $title = 'Shop | Maa Sarada Marble'; require __DIR__ . '/header.php'; ?>
<h1>Shop marble and tiles</h1>
<form method="get" action="<?= $u('shop') ?>">
<label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= $e($category['slug']) ?>" <?= ($filters['category'] ?? '') === $category['slug'] ? 'selected' : '' ?>><?= $e($category['name']) ?></option><?php endforeach; ?></select></label>
<label>Search<input name="q" maxlength="80" value="<?= $e($filters['q'] ?? '') ?>"></label>
<label>Sort<select name="sort"><?php foreach (['featured'=>'Featured first','newest'=>'Newest first','price_low'=>'Price low to high','price_high'=>'Price high to low'] as $value=>$label): ?><option value="<?= $e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
<button>Apply filters</button></form>
<?php if (!$products['items']): ?><p>No products found matching your criteria.</p><?php endif; ?>
<div class="grid"><?php foreach ($products['items'] as $product): ?><article>
<?php if ($product['images']): ?><img loading="lazy" src="<?= $u($product['images'][0]['path']) ?>" alt="<?= $e($product['images'][0]['alt_text']) ?>"><?php endif; ?>
<h2><a href="<?= $u('product/' . $product['slug']) ?>"><?= $e($product['name']) ?></a></h2>
<p><?= $e($product['category_name']) ?> <?= $e($product['brand_name'] ?? '') ?></p>
<p>From <?= $m($product['min_price_minor']) ?> <small>Choose a variant to see its selling unit.</small></p>
<p><?= $product['in_stock'] ? 'In stock' : 'Currently unavailable' ?></p></article><?php endforeach; ?></div>
<nav aria-label="Catalog pages"><?php foreach (['Previous' => $products['page'] - 1, 'Next' => $products['page'] + 1] as $label=>$page): ?><?php if ($page >= 1 && $page <= $products['pages']): ?><a href="<?= $u('shop') ?>?<?= $e(http_build_query(array_replace($filters, ['page'=>$page]))) ?>"><?= $e($label) ?></a><?php endif; ?><?php endforeach; ?></nav>
<?php require __DIR__ . '/footer.php'; ?>
