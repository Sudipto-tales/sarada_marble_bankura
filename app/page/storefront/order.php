<?php $title = 'Order ' . $order['order_number']; require __DIR__ . '/header.php'; ?>
<h1>Order <?= $e($order['order_number']) ?></h1><p>Status: <?= $e($order['status']) ?> · Payment: <?= $e($order['payment_status']) ?></p>
<?php foreach ($order['items'] as $item): ?><article><h2><?= $e($item['name']) ?></h2><p><?= $e($item['sku']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p></article><?php endforeach; ?>
<dl><?php foreach (['subtotal_minor'=>'Subtotal','shipping_minor'=>'Delivery','tax_minor'=>'Tax','total_minor'=>'Total'] as $field=>$label): ?><dt><?= $e($label) ?></dt><dd><?= $m($order[$field]) ?></dd><?php endforeach; ?></dl>
<h2>Delivery address</h2><p><?= $e($order['address']['recipient']) ?><br><?= $e($order['address']['line1']) ?><br><?= $e($order['address']['line2']) ?><br><?= $e($order['address']['city'] . ', ' . $order['address']['state'] . ' ' . $order['address']['postal_code']) ?></p>
<?php require __DIR__ . '/footer.php'; ?>
