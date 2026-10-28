<?php
$title = 'Order ' . $order['order_number'];
$page = 'order';
$pageStyles = ['order.css'];
if (!empty($justPlaced)) $pageScripts = ['vendor/lottie.min.js', 'order-success.js'];
require __DIR__ . '/header.php';
$awaiting = $order['payment_mode'] === 'razorpay' && $order['payment_status'] === 'awaiting_payment' && $order['status'] !== 'cancelled' && isset(OrderStates::paymentModes()['razorpay']);
$status = static fn($s): string => ucfirst(str_replace('_', ' ', (string)$s));
$tax = (int)($order['tax_minor'] ?? 0); $cgst = intdiv($tax, 2);
// Package-tracker steps: canonical journey + real timestamps/reasons from order history.
$trackMeta = ['placed' => ['Order placed', 'box'], 'awaiting_verification' => ['Verifying order', 'shield'], 'confirmed' => ['Confirmed', 'check'], 'processing' => ['Preparing order', 'tools'], 'shipped' => ['Shipped', 'truck'], 'outForDelivery' => ['Out for delivery', 'pin'], 'delivered' => ['Delivered', 'home']];
$trackSeq = array_keys($trackMeta);
$trackHist = [];
foreach ($order['history'] as $ev) $trackHist[$ev['to_status']] = $ev;
$cancelled = $order['status'] === 'cancelled';
$verifying = !$cancelled && in_array($order['status'], ['placed', 'awaiting_verification'], true);
$due = (int)($order['amount_due_minor'] ?? $order['total_minor']); $balance = (int)$order['total_minor'] - $due;
$reachedIdx = -1;
foreach ($trackSeq as $i => $s) if (isset($trackHist[$s])) $reachedIdx = $i;
$trackEvent = static fn($s) => $trackHist[$s] ?? null;
?>
<?php if (!empty($justPlaced)): ?>
<section class="order-success" aria-live="polite">
  <div id="order-lottie" class="order-success-anim" data-src="<?= $a('order-success.json') ?>" aria-hidden="true"></div>
  <h1>Order placed!</h1>
  <p class="order-success-lead">Thank you, <?= $e($order['address']['recipient']) ?>. Your order <strong><?= $e($order['order_number']) ?></strong> is confirmed.</p>
  <p class="order-success-total"><?= $m($order['total_minor']) ?> · <?= $e($status($order['payment_status'])) ?></p>
  <div class="order-success-actions">
    <?php if ($awaiting): ?><a class="button button-wide" href="<?= $u('checkout?order=' . $order['id']) ?>">Complete payment →</a><?php endif; ?>
    <a class="button<?= $awaiting ? ' secondary' : '' ?>" href="<?= $u('orders') ?>">View all orders</a>
    <a class="text-button" href="<?= $u('order/' . $order['id'] . '/invoice') ?>">Print order summary ↗</a>
  </div>
</section>
<?php endif; ?>

<div class="page-intro"><div><span class="eyebrow">YOUR ORDER</span><h1>Order <?= $e($order['order_number']) ?></h1><p>Status: <strong><?= $e($status($order['status'])) ?></strong> · Payment: <?= $e($status($order['payment_status'])) ?></p></div><a class="button secondary" href="<?= $u('orders') ?>">All orders ↗</a></div>

<?php if ($verifying): ?><div class="notice" role="status"><?= $icon('shield') ?> Our team will call you shortly to verify this order for security. It's confirmed once we've verified you.</div><?php endif; ?>
<?php if ($balance > 0): ?><div class="notice" role="status">You paid <strong><?= $m($due) ?></strong> in advance. The remaining <strong><?= $m($balance) ?></strong> is payable after you receive the product.</div><?php endif; ?>

<div class="order-layout">
  <div class="order-main">
    <section class="panel"><h2>Items</h2>
      <?php foreach ($order['items'] as $item): ?>
      <div class="order-item"><span class="order-thumb" aria-hidden="true"><?= $e(mb_substr($item['name'], 0, 1)) ?></span><div><strong><?= $e($item['name']) ?></strong><small><?= $e($item['sku']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?></small></div><span class="order-price"><?= $m($item['line_total_minor']) ?></span></div>
      <?php endforeach; ?>
    </section>
    <section class="panel order-track"><div class="order-track-head"><h2>Track your order</h2>
      <?php if ($cancelled): ?><span class="order-track-pill is-cancelled"><?= $icon('close') ?>Cancelled</span>
      <?php elseif ($reachedIdx === count($trackSeq) - 1): ?><span class="order-track-pill is-done"><?= $icon('check') ?>Delivered</span>
      <?php else: $cur = $trackMeta[$trackSeq[max($reachedIdx, 0)]]; ?><span class="order-track-pill is-active"><?= $icon($cur[1]) ?><?= $e($cur[0]) ?></span><?php endif; ?>
      </div>
      <ol class="track-steps">
        <?php foreach ($trackSeq as $i => $key): [$label, $ic] = $trackMeta[$key]; $ev = $trackEvent($key);
          $state = $i < $reachedIdx ? 'is-done' : ($i === $reachedIdx ? ($cancelled ? 'is-done' : 'is-current') : ($cancelled ? 'is-void' : 'is-upcoming')); ?>
        <li class="track-step <?= $state ?>"><span class="track-node"><?= $icon($state === 'is-done' ? 'check' : $ic) ?></span>
          <div class="track-label"><strong><?= $e($label) ?></strong>
            <?php if ($ev): ?><time><?= $e(gmdate('d M Y, H:i', (int)$ev['created_at'])) ?> UTC</time><?php if (!empty($ev['reason'])): ?><p><?= $e($ev['reason']) ?></p><?php endif; ?>
            <?php else: ?><span class="pending-tag"><?= $cancelled ? 'Not reached' : 'Pending' ?></span><?php endif; ?></div></li>
        <?php endforeach; ?>
        <?php if ($cancelled && ($ev = $trackEvent('cancelled'))): ?>
        <li class="track-step is-cancelled"><span class="track-node"><?= $icon('close') ?></span>
          <div class="track-label"><strong>Order cancelled</strong><time><?= $e(gmdate('d M Y, H:i', (int)$ev['created_at'])) ?> UTC</time><?php if (!empty($ev['reason'])): ?><p><?= $e($ev['reason']) ?></p><?php endif; ?></div></li>
        <?php endif; ?>
      </ol>
    </section>
  </div>
  <aside class="order-aside">
    <div class="panel"><h2>Summary</h2>
      <dl class="order-totals">
        <div><dt>Subtotal</dt><dd><?= $m($order['subtotal_minor']) ?></dd></div>
        <?php if ((int)$order['discount_minor'] > 0): ?><div class="is-discount"><dt>Discount</dt><dd>-<?= $m($order['discount_minor']) ?></dd></div><?php endif; ?>
        <div><dt>CGST</dt><dd><?= $m($cgst) ?></dd></div>
        <div><dt>SGST</dt><dd><?= $m($tax - $cgst) ?></dd></div>
        <div><dt>Delivery</dt><dd><?= (int)$order['shipping_minor'] ? $m($order['shipping_minor']) : 'Free' ?></dd></div>
        <div class="order-total"><dt>Total</dt><dd><?= $m($order['total_minor']) ?></dd></div>
        <?php if ($balance > 0): ?><div><dt>Paid in advance</dt><dd><?= $m($due) ?></dd></div><div class="order-total"><dt>Balance on delivery</dt><dd><?= $m($balance) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($awaiting): ?><a class="button button-wide" href="<?= $u('checkout?order=' . $order['id']) ?>">Complete payment</a><?php endif; ?>
      <a class="button secondary button-wide" href="<?= $u('order/' . $order['id'] . '/invoice') ?>">Print order summary</a>
    </div>
    <div class="panel"><h2>Delivery address</h2>
      <p class="order-address"><strong><?= $e($order['address']['recipient']) ?></strong><br><?= $e($order['address']['line1']) ?><?php if (!empty($order['address']['line2'])): ?><br><?= $e($order['address']['line2']) ?><?php endif; ?><br><?= $e($order['address']['city'] . ', ' . $order['address']['state'] . ' ' . $order['address']['postal_code']) ?></p>
    </div>
    <p class="note">This is an order summary, not a tax invoice.</p>
  </aside>
</div>
<?php require __DIR__ . '/footer.php'; ?>
