<?php $title = 'Checkout'; $page = 'checkout'; $pageStyles = ['checkout.css']; $pageScripts = ['checkout.js']; require __DIR__ . '/header.php'; $csrf = RequestSecurity::csrfToken(); ?>
<?php if (isset($payment)): ?>
<div class="page-intro"><div><span class="eyebrow">SECURE CHECKOUT</span><h1>Complete your payment</h1><p>Order <?= $e($payment['order_number']) ?> is reserved for you. Pay securely to confirm it.</p></div></div>
<div class="checkout-layout">
  <div class="checkout-main">
    <section class="panel php-payment">
      <h2>What you're paying for</h2>
      <?php foreach ($order['items'] as $item): ?><p><?= $e($item['name']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p><?php endforeach; ?>
      <div class="php-address-card"><span class="eyebrow">DELIVER TO</span><p><strong><?= $e($order['address']['recipient']) ?></strong><br><?= $e($order['address']['line1']) ?><br><?= $e($order['address']['city'] . ' ' . $order['address']['postal_code']) ?></p></div>
      <p class="muted"><?= $icon('shield') ?> Payments are processed by Razorpay. We never see your card details.</p>
      <?php $payBalance = (int)($order['total_minor'] ?? 0) - (int)$payment['amount_minor']; ?>
      <button type="button" id="razorpay-pay" class="button button-wide">Pay <?= $m($payment['amount_minor']) ?> securely</button>
      <?php if ($payBalance > 0): ?><p class="muted" role="status">This is a <?= $m($payment['amount_minor']) ?> advance. The remaining <?= $m($payBalance) ?> is payable after you receive the product.</p><?php endif; ?>
      <p id="payment-notice" class="muted" role="status">Your order is held while awaiting payment.</p>
    </section>
  </div>
  <aside class="checkout-summary">
    <div class="summary-card"><h2>Order Summary</h2>
      <div class="summary-items"><?php foreach ($order['items'] as $item): ?><div class="summary-item"><span class="summary-thumb" aria-hidden="true"><?= $e(mb_substr($item['name'], 0, 1)) ?></span><div><strong><?= $e($item['name']) ?></strong><small><?= $e($q($item['qty_milli']) . ' ' . $item['sell_unit']) ?></small></div><span class="summary-price"><?= $m($item['line_total_minor']) ?></span></div><?php endforeach; ?></div>
      <?php $otax = (int)($order['tax_minor'] ?? 0); $ocgst = intdiv($otax, 2); ?>
      <dl class="summary-totals">
        <div><dt>Subtotal</dt><dd><?= $m($order['subtotal_minor'] ?? 0) ?></dd></div>
        <?php if ((int)($order['discount_minor'] ?? 0) > 0): ?><div class="is-discount"><dt>Discount</dt><dd>-<?= $m($order['discount_minor']) ?></dd></div><?php endif; ?>
        <div><dt>CGST</dt><dd><?= $m($ocgst) ?></dd></div>
        <div><dt>SGST</dt><dd><?= $m($otax - $ocgst) ?></dd></div>
        <div><dt>Delivery</dt><dd><?= (int)($order['shipping_minor'] ?? 0) ? $m($order['shipping_minor']) : 'Free' ?></dd></div>
        <div class="summary-total"><dt>Order total</dt><dd><?= $m($order['total_minor'] ?? 0) ?></dd></div>
        <?php if ($payBalance > 0): ?><div><dt>Pay now (advance)</dt><dd><?= $m($payment['amount_minor']) ?></dd></div><div class="summary-total"><dt>Balance on delivery</dt><dd><?= $m($payBalance) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <ul class="trust-badges"><li><?= $icon('shield') ?><span>Secure Checkout</span></li><li><?= $icon('truck') ?><span>Reliable Delivery</span></li><li><?= $icon('return') ?><span>Easy Support</span></li></ul>
  </aside>
</div>
<form id="razorpay-verify" method="post" action="<?= $u('payment/verify') ?>">
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
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
<?php $step = $selection ? 3 : 1; ?>
<div class="page-intro"><div><span class="eyebrow">YOUR NEXT SPACE</span><h1>Checkout</h1><p>Confirm delivery, review the server total and place your order.</p></div></div>
<ol class="checkout-steps"><li class="<?= $step > 1 ? 'done' : 'current' ?>"><span>1</span>Shipping</li><li class="<?= $step > 2 ? 'done' : ($step === 2 ? 'current' : '') ?>"><span>2</span>Payment</li><li class="<?= $step >= 3 ? 'current' : '' ?>"><span>3</span>Review</li></ol>
<div class="php-checkout checkout-layout" data-checkout data-quote-url="<?= $u('checkout/quote') ?>" data-address-url="<?= $u('account/addresses') ?>" data-cart-id="<?= $e($cart['id']) ?>" data-cart-rev="<?= $e($cart['revision']) ?>" data-purchase-cart="<?= $e($purchaseCart ?? '') ?>" data-csrf="<?= $e($csrf) ?>">
  <div class="checkout-main">
    <form class="checkout-forms" method="get" action="<?= $u('checkout') ?>" data-selection-form>
      <?php if($purchaseCart!==null): ?><input type="hidden" name="purchase_cart" value="<?= $e($purchaseCart) ?>"><p class="notice">Buy now: only this selected material will be ordered. Your shopping bag is kept separately.</p><?php endif; ?>
      <section class="panel" data-address-section>
        <h2>Shipping address</h2>
        <div class="address-list" data-address-list>
          <?php foreach($addresses as $i=>$ad): ?>
          <label class="address-option"><input type="radio" name="address_id" value="<?= $e($ad['id']) ?>" <?= $i===0?'checked':'' ?> required data-address><span class="address-card-inline"><strong><?= $e($ad['recipient']) ?></strong><small><?= $e($ad['line1'].($ad['line2']?', '.$ad['line2']:'').', '.$ad['city'].', '.($ad['state']?:'').' '.$ad['postal_code']) ?></small><small><?= $e($ad['phone']) ?></small></span></label>
          <?php endforeach; ?>
        </div>
        <?php if(!$addresses): ?><p class="muted" data-address-empty>Add a delivery address to continue.</p><?php endif; ?>
        <button type="button" class="text-button js-only" data-add-toggle<?= $addresses?'':' hidden' ?>>+ Add new address</button>
        <p class="no-js"><a class="text-button" href="<?= $u('account') ?>">Manage addresses in your account ↗</a></p>
      </section>
      <section class="panel" data-payment-section>
        <h2>Payment method</h2>
        <?php if($modes): ?>
        <fieldset class="php-pay-options"><legend class="sr-only">Payment method</legend>
          <?php foreach($modes as $mode=>$label): ?><label class="php-pay-option"><input type="radio" name="payment_mode" value="<?= $e($mode) ?>" <?= ($selection['payment_mode']??'')===$mode?'checked':'' ?> required data-payment><span class="php-pay-name"><?= $e($label) ?></span><?php if(!empty($cashback[$mode])): ?><b class="php-cashback">Get <?= $m($cashback[$mode]) ?> back</b><?php endif; ?></label><?php endforeach; ?>
        </fieldset>
        <div class="coupon-row js-only"><label>Have a coupon?<input name="coupon_code" maxlength="40" value="<?= $e($couponCode??'') ?>" data-coupon autocomplete="off"></label><button type="button" class="button secondary" data-apply-coupon>Apply</button><span class="form-msg<?= $couponError?' error':'' ?>" data-coupon-msg role="status"><?= $couponError?$e($couponError):($coupon?'Coupon '.$e($coupon['code']).' applied':'') ?></span></div>
        <?php else: ?><p>Ordering is currently unavailable. Please contact the store.</p><?php endif; ?>
        <button class="button button-wide no-js" data-review-submit>Review order →</button>
      </section>
    </form>

    <!-- Inline add-address (JS; posts to the shared address endpoint) -->
    <div class="panel address-form js-only" data-address-form<?= $addresses?' hidden':'' ?>>
      <h2>New delivery address</h2>
      <div class="field-grid">
        <label class="col-2">Full name<input name="recipient" maxlength="190"></label>
        <label class="col-2">Phone<input name="phone" maxlength="20" inputmode="tel"></label>
        <label class="col-2">Address line 1<input name="line1" maxlength="190"></label>
        <label class="col-2">Address line 2 (optional)<input name="line2" maxlength="190"></label>
        <label>City<input name="city" maxlength="100"></label>
        <label>State<input name="state" maxlength="100"></label>
        <label>ZIP / Postal code<input name="postal_code" maxlength="16" inputmode="numeric"></label>
        <label>Country<input name="country_code" value="IN" readonly></label>
      </div>
      <label class="checkbox"><input type="checkbox" name="is_default" value="1"> Save as default</label>
      <div class="form-row"><button type="button" class="button" data-address-save>Save address</button><button type="button" class="text-button" data-add-cancel<?= $addresses?'':' hidden' ?>>Cancel</button><span class="form-msg" data-address-msg role="status"></span></div>
    </div>

    <!-- No-JS coupon (session POST) -->
    <form class="panel no-js" method="post" action="<?= $u('checkout/coupon') ?>">
      <h2>Coupon</h2><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><?php if($purchaseCart!==null): ?><input type="hidden" name="purchase_cart" value="<?= $e($purchaseCart) ?>"><?php endif; ?>
      <label>Coupon code<input name="coupon_code" maxlength="40" value="<?= $e($couponCode??'') ?>"></label><button class="button secondary">Apply</button> <button class="button secondary" name="coupon_code" value="" aria-label="Remove coupon"><?= $icon('trash') ?></button>
    </form>

    <!-- No-JS server review/place -->
    <?php if($selection): ?>
    <form class="panel php-order-review server-review no-js" method="post" action="<?= $u('checkout') ?>"><h2>Order review</h2>
      <?php $chosen=null; foreach($addresses as $addr) if((int)$addr['id']===(int)$selection['address_id']) $chosen=$addr; ?>
      <?php if($chosen): ?><div class="php-address-card"><span class="eyebrow">DELIVER TO</span><p><strong><?= $e($chosen['recipient']) ?></strong><br><?= $e($chosen['line1']) ?><?php if(!empty($chosen['line2'])): ?><br><?= $e($chosen['line2']) ?><?php endif; ?><br><?= $e($chosen['city'].', '.($chosen['state']??'').' '.$chosen['postal_code']) ?></p></div><?php endif; ?>
      <p>Payment: <?= $e($modes[$selection['payment_mode']]) ?></p>
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="checkout_key" value="<?= $e($key) ?>">
      <?php foreach($selection as $name=>$value): ?><input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>"><?php endforeach; ?><button class="button button-wide">Place order</button>
    </form>
    <?php endif; ?>

    <!-- JS place order -->
    <form class="panel js-only" method="post" action="<?= $u('checkout') ?>" data-place-form>
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="checkout_key" value="" data-key>
      <input type="hidden" name="cart_id" value="<?= $e($cart['id']) ?>"><input type="hidden" name="cart_revision" value="<?= $e($cart['revision']) ?>">
      <input type="hidden" name="address_id" value="" data-place-address><input type="hidden" name="payment_mode" value="" data-place-payment>
      <button class="button button-wide" data-place disabled>Place order</button>
      <p class="muted" data-place-hint>Select an address and payment method to place your order.</p>
    </form>
  </div>

  <aside class="checkout-summary">
    <div class="summary-card"><h2>Order Summary</h2>
      <div class="summary-items"><?php foreach ($cart['items'] as $item): ?><div class="summary-item"><span class="summary-thumb" aria-hidden="true"><?= $e(mb_substr($item['name'], 0, 1)) ?></span><div><strong><?= $e($item['name']) ?></strong><small><?= $e(($item['finish'] ?: $item['sku']) . ' · ' . $q($item['qty_milli']) . ' ' . $item['sell_unit']) ?></small></div><span class="summary-price"><?= $m($item['line_total_minor']) ?></span></div><?php endforeach; ?></div>
      <?php $tax=(int)$quote['tax_minor']; $cgst=intdiv($tax,2); $disc=(int)($quote['discount_minor']??0); ?>
      <dl class="summary-totals">
        <div><dt>Subtotal</dt><dd data-sum="subtotal"><?= $m($quote['subtotal_minor']) ?></dd></div>
        <div class="is-discount" data-row="discount"<?= $disc>0?'':' hidden' ?>><dt>Discount</dt><dd data-sum="discount">-<?= $m($disc) ?></dd></div>
        <div><dt>CGST<?= $tax?' ('.rtrim(rtrim(number_format($quote['policy']['tax_bps']/200,2),'0'),'.').'%)':'' ?></dt><dd data-sum="cgst"><?= $m($cgst) ?></dd></div>
        <div><dt>SGST<?= $tax?' ('.rtrim(rtrim(number_format($quote['policy']['tax_bps']/200,2),'0'),'.').'%)':'' ?></dt><dd data-sum="sgst"><?= $m($tax-$cgst) ?></dd></div>
        <div><dt>Delivery</dt><dd data-sum="shipping"><?= (int)$quote['shipping_minor']?$m($quote['shipping_minor']):'Free' ?></dd></div>
        <div class="summary-total"><dt>Total</dt><dd data-sum="total"><?= $m($quote['total_minor']) ?></dd></div>
      </dl>
      <p class="summary-note">Taxes and delivery are set by the store. Final prices and stock are confirmed when you place your order.</p>
    </div>
    <ul class="trust-badges"><li><?= $icon('shield') ?><span>Secure Checkout</span></li><li><?= $icon('truck') ?><span>Reliable Delivery</span></li><li><?= $icon('return') ?><span>Easy Support</span></li></ul>
  </aside>
</div>
<?php require __DIR__ . '/footer.php'; ?>
