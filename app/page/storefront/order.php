<?php
$title = 'Order ' . $order['order_number'];
$page = 'order';
$pageStyles = ['order.css'];
if (!empty($justPlaced)) $pageScripts = ['vendor/lottie.min.js', 'order-success.js'];
require __DIR__ . '/header.php';
$awaiting = $order['payment_mode'] === 'razorpay' && $order['payment_status'] === 'awaiting_payment' && $order['status'] !== 'cancelled' && isset(OrderStates::paymentModes()['razorpay']);
$status = static fn($s): string => ucfirst(str_replace('_', ' ', (string)$s));
$tax = (int)($order['tax_minor'] ?? 0); $cgst = intdiv($tax, 2);
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

<div class="order-layout">
  <div class="order-main">
    <section class="panel"><h2>Items</h2>
      <?php foreach ($order['items'] as $item): ?>
      <div class="order-item"><span class="order-thumb" aria-hidden="true"><?= $e(mb_substr($item['name'], 0, 1)) ?></span><div><strong><?= $e($item['name']) ?></strong><small><?= $e($item['sku']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?></small></div><span class="order-price"><?= $m($item['line_total_minor']) ?></span></div>
      <?php endforeach; ?>
    </section>
    <section class="panel order-timeline"><h2>Order timeline</h2>
      <ol><?php foreach ($order['history'] as $event): ?><li><strong><?= $e($status($event['to_status'])) ?></strong><time><?= $e(gmdate('d M Y H:i', (int)$event['created_at'])) ?> UTC</time><?php if (!empty($event['reason'])): ?><p><?= $e($event['reason']) ?></p><?php endif; ?></li><?php endforeach; ?></ol>
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
