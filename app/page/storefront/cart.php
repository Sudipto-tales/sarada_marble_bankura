<?php $title = 'Shopping cart | Maa Sarada'; $page = 'cart'; $pageStyles = ['cart.css']; $pageScripts = ['cart.js']; require __DIR__ . '/header.php'; $csrf = RequestSecurity::csrfToken(); ?>
<div class="breadcrumb"><a href="<?= $u() ?>">Home</a><span>/</span><span>Shopping bag</span></div>
<div class="page-intro"><div><span class="eyebrow">YOUR MATERIAL SELECTION</span><h1>Your shopping bag.</h1><p>Considered materials for your next space.</p></div></div>
<?php if (!empty($_SESSION['cart_merge_excluded'])): ?><p class="notice"><?= $e($_SESSION['cart_merge_excluded']) ?> unavailable item(s) were excluded when your guest cart was merged.</p><?php endif; ?>
<?php if (!$cart['items']): ?>
<div class="empty-state"><h2>Your cart is empty.</h2><p>Explore the collection and find a surface that feels like yours.</p><a class="button" href="<?= $u('shop') ?>">Continue shopping ↗</a></div>
<?php else: ?>
<div class="cart-layout">
<section class="cart-items">
<?php foreach ($cart['items'] as $item): $bagProduct = $cartProducts[$item['product_id']] ?? null; ?>
<form class="cart-item<?= $item['purchasable'] ? '' : ' is-unavailable' ?>" method="post" action="<?= $u('cart') ?>">
<button class="cart-update" data-cart-update name="action" value="update" hidden>Update</button>
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="cart_id" value="<?= $e($cart['id']) ?>"><input type="hidden" name="cart_revision" value="<?= $e($cart['revision']) ?>"><input type="hidden" name="variant_id" value="<?= $e($item['variant_id']) ?>">
<?php if ($bagProduct): ?><a class="cart-thumb" href="<?= $u('product/'.$bagProduct['slug']) ?>"><?php if ($bagProduct['images']): ?><img src="<?= $u($bagProduct['images'][0]['path']) ?>" alt="<?= $e($bagProduct['images'][0]['alt_text'] ?: $bagProduct['name']) ?>" loading="lazy"><?php else: ?><span class="material-placeholder">Photo soon</span><?php endif; ?></a><?php else: ?><span class="cart-thumb"></span><?php endif; ?>
<div class="cart-item-main">
<h2><?php if ($bagProduct): ?><a href="<?= $u('product/'.$bagProduct['slug']) ?>"><?= $e($item['name']) ?></a><?php else: ?><?= $e($item['name']) ?><?php endif; ?></h2>
<?php if (!$item['purchasable']): ?><span class="cart-badge">Unavailable</span><?php endif; ?>
<p class="cart-item-meta"><?= $e($item['sku']) ?></p>
<p class="cart-item-meta"><?php if ($item['line_original_minor'] !== null): ?><del><?= $m($item['original_price_minor']) ?></del> <?php endif; ?><?= $m($item['unit_price_minor']) ?> per <?= $e($item['sell_unit']) ?></p>
<strong class="cart-item-price"><?php if ($item['line_original_minor'] !== null): ?><del><?= $m($item['line_original_minor']) ?></del> <?php endif; ?><?= $m($item['line_total_minor']) ?></strong>
<?php if (!$item['purchasable']): ?><p class="cart-item-alert" role="alert">This quantity is currently unavailable. Update or remove this item.</p><?php endif; ?>
</div>
<div class="cart-item-side">
<button class="cart-remove" name="action" value="remove" formnovalidate aria-label="Remove <?= $e($item['name']) ?>"><?= $icon('trash') ?></button>
<div class="cart-qty" data-cart-qty>
<button type="button" data-dec aria-label="Decrease quantity">&minus;</button>
<input type="number" name="quantity" min="<?= $q($item['qty_increment_milli']) ?>" step="<?= $q($item['qty_increment_milli']) ?>" value="<?= $q($item['qty_milli']) ?>" aria-label="Quantity in <?= $e($item['sell_unit']) ?>" required>
<button type="button" data-inc aria-label="Increase quantity">+</button>
</div>
</div>
</form>
<?php endforeach; ?>
</section>
<aside class="cart-summary">
<h2>Order summary</h2>
<div class="cart-summary-row"><span>Items</span><span><?= count($cart['items']) ?></span></div>
<?php if ($cart['savings_minor'] > 0): ?>
<div class="cart-summary-row"><span>Price (before sale)</span><span><?= $m($cart['subtotal_minor'] + $cart['savings_minor']) ?></span></div>
<div class="cart-summary-row cart-summary-save"><span>Sale discount</span><span>&minus;<?= $m($cart['savings_minor']) ?></span></div>
<?php endif; ?>
<div class="cart-summary-row"><span>Subtotal</span><span><?= $m($cart['subtotal_minor']) ?></span></div>
<div class="cart-summary-row"><span>Shipping</span><span class="cart-summary-muted">Calculated at checkout</span></div>
<div class="cart-summary-total"><span>Estimated total</span><strong><?= $m($cart['subtotal_minor']) ?></strong></div>
<?php if ($cart['savings_minor'] > 0): ?><p class="cart-summary-saved">You save <?= $m($cart['savings_minor']) ?> on this order.</p><?php endif; ?>
<p class="note">Shipping, GST and any discount codes are applied at checkout. Prices and availability are re-checked when you place the order.</p>
<a class="button cart-checkout" href="<?= $u('checkout') ?>">Go to checkout →</a>
<a class="text-button" href="<?= $u('calculator') ?>">Plan installation costs ↗</a>
<a class="text-button" href="<?= $u('shop') ?>">Continue shopping ↗</a>
</aside>
</div>
<?php endif; require __DIR__ . '/footer.php'; ?>
