<?php $module='reports';$title='Sales reports';$description='Paid orders and coupon usage within a UTC date range (up to 93 days).';require __DIR__.'/header.php'; ?>
<section class="panel"><form method="get" action="<?= $u('admin/reports') ?>"><?php $field('from','From (UTC)',$report['from'],'date','required');$field('to','Through (UTC)',$report['to'],'date','required'); ?><button class="button">Show report</button></form>
<dl><dt>Paid orders placed in this range</dt><dd><?= $e($report['sales']['order_count']) ?></dd><dt>Paid order revenue</dt><dd><?= $m($report['sales']['revenue_minor']) ?></dd></dl>
<h2>Coupon usage</h2><p>Up to 100 coupons, sorted by discount amount. Uses include unpaid and cancelled orders; they do not indicate collected revenue.</p>
<table><thead><tr><th>Code</th><th>Lifetime uses</th><th>Uses in range</th><th>Discount in range</th></tr></thead><tbody>
<?php foreach($report['coupons'] as $row): ?><tr><td><?= $e($row['code']) ?></td><td><?= $e($row['used_count']) ?></td><td><?= $e($row['range_uses']) ?></td><td><?= $m($row['discount_minor']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php require __DIR__.'/footer.php'; ?>
