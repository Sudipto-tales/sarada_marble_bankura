<?php $title = 'Shopping cart'; require __DIR__ . '/header.php'; $csrf = RequestSecurity::csrfToken(); ?>
<h1>Shopping cart</h1>
<?php if (!empty($_SESSION['cart_merge_excluded'])): ?><p class="notice"><?= $e($_SESSION['cart_merge_excluded']) ?> unavailable item(s) were excluded when your guest cart was merged.</p><?php endif; ?>
<?php if (!$cart['items']): ?><p>Your cart is empty. <a href="<?= $u('shop') ?>">Continue shopping</a>.</p><?php else: ?>
<?php foreach ($cart['items'] as $item): ?><form method="post" action="<?= $u('cart') ?>">
<h2><?= $e($item['name']) ?></h2><p><?= $e($item['sku']) ?> · <?= $m($item['unit_price_minor']) ?> per <?= $e($item['sell_unit']) ?></p>
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="cart_id" value="<?= $e($cart['id']) ?>"><input type="hidden" name="cart_revision" value="<?= $e($cart['revision']) ?>"><input type="hidden" name="variant_id" value="<?= $e($item['variant_id']) ?>">
<label>Quantity (<?= $e($item['sell_unit']) ?>)<input type="number" name="quantity" min="<?= $q($item['qty_increment_milli']) ?>" step="<?= $q($item['qty_increment_milli']) ?>" value="<?= $q($item['qty_milli']) ?>" required></label>
<p>Line subtotal: <?= $m($item['line_total_minor']) ?></p><?php if (!$item['purchasable']): ?><p role="alert">This quantity is currently unavailable. Update or remove this item.</p><?php endif; ?>
<button name="action" value="update">Update</button> <button name="action" value="remove" formnovalidate>Remove</button></form><?php endforeach; ?>
<p><strong>Subtotal: <?= $m($cart['subtotal_minor']) ?></strong></p><p class="muted">Prices and availability are checked again when you place the order.</p><a href="<?= $u('checkout') ?>">Proceed to checkout</a>
<?php endif; require __DIR__ . '/footer.php'; ?>
