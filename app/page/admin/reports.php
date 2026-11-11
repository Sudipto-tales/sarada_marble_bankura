<?php $module='reports';$title='Sales reports';$description='Paid orders and coupon usage within a UTC date range (up to 93 days).';require __DIR__.'/header.php'; ?>
<section class="panel"><form class="report-controls" method="get" action="<?= $u('admin/reports') ?>"><?php $field('from','From (UTC)',$report['from'],'date','required');$field('to','Through (UTC)',$report['to'],'date','required'); ?><div class="form-actions"><button class="button">Show report</button><a class="button secondary" href="<?= $u('admin/reports') ?>?from=<?= $e($report['from']) ?>&amp;to=<?= $e($report['to']) ?>&amp;format=csv">Export CSV ↗</a></div></form>
<dl><dt>Paid orders placed in this range</dt><dd><?= $e($report['sales']['order_count']) ?></dd><dt>Paid order revenue</dt><dd><?= $m($report['sales']['revenue_minor']) ?></dd></dl></section>
<section class="panel"><h2>Orders by status</h2><p>Every order placed in this range, grouped by its current status.</p>
<?php if($report['statuses']): ?><table><thead><tr><th>Status</th><th>Orders</th><th>Order value</th></tr></thead><tbody>
<?php foreach($report['statuses'] as $row): ?><tr><td><?= $e($row['status']) ?></td><td><?= $e($row['order_count']) ?></td><td><?= $m($row['value_minor']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><p class="notice">No orders were placed in this range.</p><?php endif; ?></section>
<section class="panel"><h2>Top products</h2><p>Best sellers by paid revenue in this range (up to 20).</p>
<?php if($report['topProducts']): ?><table><thead><tr><th>Material</th><th>Units sold</th><th>Revenue</th><th>Orders</th></tr></thead><tbody>
<?php foreach($report['topProducts'] as $row): ?><tr><td><?= $e($row['name']) ?></td><td><?= $q($row['qty_milli']) ?></td><td><?= $m($row['revenue_minor']) ?></td><td><?= $e($row['order_count']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><p class="notice">No paid orders in this range yet.</p><?php endif; ?></section>
<section class="panel"><h2>Current low stock</h2><p>Active enabled variants within five selling increments of empty (live, not limited to the date range). Adjust stock from the inventory workspace.</p>
<?php if($report['lowStock']): ?><table><thead><tr><th>Material</th><th>SKU</th><th>Available</th></tr></thead><tbody>
<?php foreach($report['lowStock'] as $row): ?><tr><td><?= $e($row['name']) ?></td><td><?= $e($row['sku']) ?></td><td><?= $q(max(0,(int)$row['available_milli'])) ?> <?= $e($row['sell_unit']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?><p class="notice">All active variants currently hold healthy stock.</p><?php endif; ?></section>
<section class="panel"><h2>Coupon usage</h2><p>Up to 100 coupons, sorted by discount amount. Uses include unpaid and cancelled orders; they do not indicate collected revenue.</p>
<table><thead><tr><th>Code</th><th>Lifetime uses</th><th>Uses in range</th><th>Discount in range</th></tr></thead><tbody>
<?php foreach($report['coupons'] as $row): ?><tr><td><?= $e($row['code']) ?></td><td><?= $e($row['used_count']) ?></td><td><?= $e($row['range_uses']) ?></td><td><?= $m($row['discount_minor']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php require __DIR__.'/footer.php'; ?>
