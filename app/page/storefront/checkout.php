<?php $title = 'Checkout'; require __DIR__ . '/header.php'; ?>
<h1>Checkout</h1>
<?php if (!$addresses): ?><p>Add a delivery address in <a href="<?= $u('account') ?>">your account</a> to continue.</p><?php elseif (!$modes): ?><p>Ordering is currently unavailable. Please contact the store.</p><?php else: ?>
<form method="get" action="<?= $u('checkout') ?>"><h2>Delivery and payment</h2>
<label>Delivery address<select name="address_id" required><?php foreach ($addresses as $address): ?><option value="<?= $e($address['id']) ?>" <?= ($selection['address_id'] ?? null) === (int)$address['id'] ? 'selected' : '' ?>><?= $e($address['recipient'] . ', ' . $address['line1'] . ', ' . $address['city'] . ' ' . $address['postal_code']) ?></option><?php endforeach; ?></select></label>
<label>Payment method<select name="payment_mode" required><?php foreach ($modes as $mode=>$label): ?><option value="<?= $e($mode) ?>" <?= ($selection['payment_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><button>Review order</button></form>
<?php if ($selection): ?><form method="post" action="<?= $u('checkout') ?>"><h2>Order review</h2>
<?php foreach ($cart['items'] as $item): ?><p><?= $e($item['name']) ?> · <?= $e($item['sku']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p><?php endforeach; ?>
<dl><?php foreach (['subtotal_minor'=>'Subtotal','shipping_minor'=>'Delivery','tax_minor'=>'Tax','total_minor'=>'Total'] as $field=>$label): ?><dt><?= $e($label) ?></dt><dd><?= $m($quote[$field]) ?></dd><?php endforeach; ?></dl>
<p>Payment: <?= $e($modes[$selection['payment_mode']]) ?></p><p class="muted">Final prices and stock are validated when you place your order.</p>
<input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>"><input type="hidden" name="checkout_key" value="<?= $e($key) ?>">
<?php foreach ($selection as $name=>$value): ?><input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>"><?php endforeach; ?><button>Place order</button></form><?php endif; endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
