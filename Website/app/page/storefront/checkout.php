<?php $title = 'Checkout'; require __DIR__ . '/header.php'; ?>
<?php if (isset($payment)): ?>
<section class="panel php-payment">
<div class="page-intro"><div><span class="eyebrow">SECURE CHECKOUT</span><h1>Complete your payment</h1><p>Order <?= $e($payment['order_number']) ?> is reserved for you. Pay securely to confirm it.</p></div></div>
<div class="php-payment-grid"><div><h2>What you're paying for</h2>
<?php foreach ($order['items'] as $item): ?><p><?= $e($item['name']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p><?php endforeach; ?>
<dl><?php foreach (['subtotal_minor'=>'Subtotal','discount_minor'=>'Discount','shipping_minor'=>'Delivery','tax_minor'=>'Tax','total_minor'=>'Amount due'] as $f=>$l): ?><dt><?= $e($l) ?></dt><dd><?= $m($order[$f] ?? 0) ?></dd><?php endforeach; ?></dl></div>
<aside><h2>Delivery</h2><p><?= $e($order['address']['recipient']) ?><br><?= $e($order['address']['line1']) ?><br><?= $e($order['address']['city'] . ' ' . $order['address']['postal_code']) ?></p><p class="php-trust"><?= $icon('shield') ?> Payments are processed by Razorpay. We never see your card details.</p></aside></div>
<button type="button" id="razorpay-pay" class="button">Pay <?= $m($payment['amount_minor']) ?> securely</button><p id="payment-notice" class="muted" role="status">Your order is held while awaiting payment.</p></section>
<form id="razorpay-verify" method="post" action="<?= $u('payment/verify') ?>">
<input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>">
<?php foreach (['razorpay_order_id','razorpay_payment_id','razorpay_signature'] as $field): ?><input type="hidden" name="<?= $e($field) ?>"><?php endforeach; ?>
</form>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(() => {
    const options = <?= json_encode(['key'=>$payment['key_id'],'order_id'=>$payment['provider_order_id'],
        'amount'=>$payment['amount_minor'],'currency'=>$payment['currency'],'name'=>'Maa Sarada'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
    const notice = document.getElementById('payment-notice');
    options.handler = response => {
        const form = document.getElementById('razorpay-verify');
        for (const field of ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']) form.elements[field].value = response[field];
        form.submit();
    };
    options.modal = {ondismiss: () => { notice.textContent = 'Payment is awaiting completion. You can reopen checkout.'; }};
    const open = () => {
        if (typeof Razorpay === 'undefined') { notice.textContent = 'Payment checkout could not load. Please reload to retry.'; return; }
        const checkout = new Razorpay(options);
        checkout.on('payment.failed', () => { notice.textContent = 'Payment failed. Please retry or contact the store.'; });
        checkout.open();
    };
    document.getElementById('razorpay-pay').addEventListener('click', open);
    open();
})();
</script>
<?php require __DIR__ . '/footer.php'; return; endif; ?>
<div class="page-intro"><div><span class="eyebrow">YOUR NEXT SPACE</span><h1>Checkout</h1><p>Confirm delivery, review the server total and place your order.</p></div></div><div class="php-checkout">
<form class="panel" method="post" action="<?= $u('checkout/coupon') ?>"><h2>Coupon</h2>
<input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>">
<?php if($purchaseCart!==null): ?><input type="hidden" name="purchase_cart" value="<?= $e($purchaseCart) ?>"><?php endif; ?>
<?php if($selection): ?><input type="hidden" name="address_id" value="<?= $e($selection['address_id']) ?>"><input type="hidden" name="payment_mode" value="<?= $e($selection['payment_mode']) ?>"><?php endif; ?>
<label>Coupon code<input name="coupon_code" maxlength="40" value="<?= $e($couponCode??'') ?>"></label><button>Apply coupon</button><button class="button secondary" name="coupon_code" value="">Remove coupon</button>
<?php if($couponError): ?><p role="alert"><?= $e($couponError) ?> Remove or replace it to review your order.</p><?php endif; ?>
<?php if($coupon): ?><p><?= $e($coupon['code']) ?> · discount <?= $m($coupon['discount_minor']) ?></p><?php endif; ?></form>
<?php if (!$addresses): ?><p>Add a delivery address in <a href="<?= $u('account') ?>">your account</a> to continue.</p><?php elseif (!$modes): ?><p>Ordering is currently unavailable. Please contact the store.</p><?php else: ?>
<form class="panel" method="get" action="<?= $u('checkout') ?>"><h2>Delivery and payment</h2><?php if($purchaseCart!==null): ?><input type="hidden" name="purchase_cart" value="<?= $e($purchaseCart) ?>"><p class="notice">Buy now: only this selected material will be ordered. Your shopping bag is kept separately.</p><?php endif; ?>
<label>Delivery address<select name="address_id" required><?php foreach ($addresses as $address): ?><option value="<?= $e($address['id']) ?>" <?= ($selection['address_id'] ?? null) === (int)$address['id'] ? 'selected' : '' ?>><?= $e($address['recipient'] . ', ' . $address['line1'] . ', ' . $address['city'] . ' ' . $address['postal_code']) ?></option><?php endforeach; ?></select></label>
<fieldset class="php-pay-options"><legend>Payment method</legend><?php foreach ($modes as $mode=>$label): ?><label class="php-pay-option"><input type="radio" name="payment_mode" value="<?= $e($mode) ?>" <?= ($selection['payment_mode'] ?? '') === $mode ? 'checked' : '' ?> required><span class="php-pay-name"><?= $e($label) ?></span><?php if (!empty($cashback[$mode])): ?><b class="php-cashback">Get <?= $m($cashback[$mode]) ?> back</b><?php endif; ?></label><?php endforeach; ?></fieldset><button>Review order</button></form>
<?php if ($selection): ?><form class="panel php-order-review" method="post" action="<?= $u('checkout') ?>"><h2>Order review</h2>
<?php $chosen=null; foreach ($addresses as $addr) if ((int)$addr['id']===(int)$selection['address_id']) $chosen=$addr; ?>
<?php if ($chosen): ?><div class="php-address-card"><span class="eyebrow">DELIVER TO</span><p><strong><?= $e($chosen['recipient']) ?></strong><br><?= $e($chosen['line1']) ?><?php if (!empty($chosen['line2'])): ?><br><?= $e($chosen['line2']) ?><?php endif; ?><br><?= $e($chosen['city'].', '.($chosen['state']??'').' '.$chosen['postal_code']) ?></p></div><?php endif; ?>
<p>Payment: <?= $e($modes[$selection['payment_mode']]) ?><?php if (!empty($cashback[$selection['payment_mode']])): ?> · <b class="php-cashback">Get <?= $m($cashback[$selection['payment_mode']]) ?> back</b><?php endif; ?></p>
<?php foreach ($cart['items'] as $item): ?><p><?= $e($item['name']) ?> · <?= $e($item['sku']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p><?php endforeach; ?>
<dl><?php foreach (['subtotal_minor'=>'Subtotal','discount_minor'=>'Discount','shipping_minor'=>'Delivery','tax_minor'=>'Estimated tax','total_minor'=>'Estimated total'] as $field=>$label): ?><dt><?= $e($label) ?></dt><dd><?= $m($quote[$field]??0) ?></dd><?php endforeach; ?></dl>
<p class="muted">Final prices and stock are validated when you place your order.</p>
<input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>"><input type="hidden" name="checkout_key" value="<?= $e($key) ?>">
<?php foreach ($selection as $name=>$value): ?><input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>"><?php endforeach; ?><button>Place order</button></form><?php endif; endif; ?>
</div><?php require __DIR__ . '/footer.php'; ?>
