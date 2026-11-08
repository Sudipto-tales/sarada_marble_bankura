<?php
$module='analytics';$title='Analytics';$description='Orders, paid revenue and product engagement from recorded store activity.';require __DIR__.'/header.php';
$trend=$dashboard['trend'];$statuses=$dashboard['statuses'];$topProducts=$dashboard['topProducts'];
$trendJson=json_encode($trend,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$statusJson=json_encode($statuses,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$productJson=json_encode($topProducts,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$growthLabel=static function(?string $value):string{
    if($value===null)return 'New activity';
    if((float)$value===0.0)return 'No change vs previous period';
    return ((float)$value>0?'↑ ':'↓ ').ltrim($value,'-').'% vs previous period';
};
$metricsUpdated=$dashboard['metricsUpdatedAt']?gmdate('Y-m-d H:i',(int)$dashboard['metricsUpdatedAt']).' UTC':'Not yet processed';
?>
<section class="analytics-toolbar panel"><div><strong>Reporting period</strong><span><?= $e($dashboard['period']['from']) ?> – <?= $e($dashboard['period']['to']) ?> UTC</span></div><form method="get" action="<?= $u('admin/analytics') ?>"><label class="visually-hidden" for="analytics-days">Date range</label><select id="analytics-days" name="days"><option value="7" <?= $dashboard['period']['days']===7?'selected':'' ?>>Last 7 days</option><option value="30" <?= $dashboard['period']['days']===30?'selected':'' ?>>Last 30 days</option><option value="90" <?= $dashboard['period']['days']===90?'selected':'' ?>>Last 90 days</option></select><button class="button">Apply range</button></form></section>
<section class="analytics-stat-grid" aria-label="Store performance summary">
<article class="analytics-stat"><span class="analytics-stat-icon">↗</span><span class="analytics-stat-label">Total orders</span><strong><?= $e(number_format($dashboard['tiles']['orders_total'])) ?></strong><small class="trend-badge <?= ($dashboard['growth']['orders']??null)!==null&&(float)$dashboard['growth']['orders']<0?'down':'' ?>"><?= $e($growthLabel($dashboard['growth']['orders'])) ?></small></article>
<article class="analytics-stat"><span class="analytics-stat-icon">₹</span><span class="analytics-stat-label">Paid revenue</span><strong>₹<?= $e(AdminReadService::decimal($dashboard['tiles']['revenue_minor'],2)) ?></strong><small class="trend-badge <?= ($dashboard['growth']['revenue']??null)!==null&&(float)$dashboard['growth']['revenue']<0?'down':'' ?>"><?= $e($growthLabel($dashboard['growth']['revenue'])) ?></small></article>
<article class="analytics-stat"><span class="analytics-stat-icon">◎</span><span class="analytics-stat-label">Average order value</span><strong>₹<?= $e(AdminReadService::decimal($dashboard['tiles']['aov_minor'],2)) ?></strong><small>Paid revenue ÷ paid orders</small></article>
<article class="analytics-stat"><span class="analytics-stat-icon">◉</span><span class="analytics-stat-label">Product conversion</span><strong><?= $e(AdminReadService::decimal($dashboard['tiles']['conversion_bps'],2)) ?>%</strong><small><?= $e(number_format($dashboard['tiles']['orders_net'])) ?> net product orders / <?= $e(number_format($dashboard['tiles']['views'])) ?> views</small></article>
<article class="analytics-stat"><span class="analytics-stat-icon">▦</span><span class="analytics-stat-label">Active products</span><strong><?= $e(number_format($dashboard['tiles']['active_products'])) ?></strong><small>Published in the catalog now</small></article>
<article class="analytics-stat <?= $dashboard['tiles']['low_stock']>0?'attention':'' ?>"><span class="analytics-stat-icon">△</span><span class="analytics-stat-label">Low stock variants</span><strong><?= $e(number_format($dashboard['tiles']['low_stock'])) ?></strong><small>Below five selling increments</small></article>
</section>
<div class="analytics-chart-grid">
<section class="panel"><div class="panel-heading"><div><h2>Orders and revenue</h2><p>Orders placed and paid revenue by UTC day.</p></div><span class="pill"><?= $e($dashboard['period']['days']) ?> DAYS</span></div><div class="admin-analytics-chart" id="analytics-trend" role="img" aria-label="Daily orders and paid revenue" data-chart-series="<?= $e($trendJson) ?>"></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Orders by status</h2><p>Current status of orders placed in this range.</p></div></div><div class="admin-analytics-chart donut" id="analytics-status" role="img" aria-label="Orders grouped by status" data-chart-series="<?= $e($statusJson) ?>"></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Top products</h2><p>Paid revenue for the highest earning products in this range.</p></div></div><div class="admin-analytics-chart products" id="analytics-products" role="img" aria-label="Top products by paid revenue" data-chart-series="<?= $e($productJson) ?>"></div></section>
</div>
<section class="panel analytics-table-panel"><div class="panel-heading"><div><h2>Orders by status</h2><p>Current order state and current order value for orders placed in the selected range.</p></div><a class="button secondary" href="<?= $u('admin/reports') ?>?from=<?= $e($dashboard['period']['from']) ?>&amp;to=<?= $e($dashboard['period']['to']) ?>">Detailed sales report ↗</a></div>
<?php if($statuses): ?><div class="table-scroll"><table class="admin-table"><thead><tr><th>Status</th><th>Orders</th><th>Order value</th></tr></thead><tbody><?php foreach($statuses as $row): ?><tr><td><?= $e($row['status']) ?></td><td><?= $e(number_format((int)$row['order_count'])) ?></td><td>₹<?= $e(AdminReadService::decimal($row['value_minor'],2)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="notice">No orders were recorded in this period.</p><?php endif; ?>
<p class="analytics-freshness">Product views and conversion use the daily event aggregates. Last aggregate update: <?= $e($metricsUpdated) ?> · <?= $e(number_format($dashboard['pendingMetrics'])) ?> events pending.</p></section>
<?php require __DIR__.'/footer.php'; ?>
