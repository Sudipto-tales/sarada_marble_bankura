<?php $module='coupons';$title=$coupon?'Edit coupon':'Add coupon';$description='Set a discount, availability window and usage limits.';require __DIR__.'/header.php';$values=$values??[]; ?>
<section class="panel"><div class="panel-heading"><div><h2><?= $coupon?'Edit coupon':'Add coupon' ?></h2><p>Set a discount, availability window and usage limits.</p></div><?php if($coupon): ?><span class="pill"><?= $e($coupon['used_count']) ?> USES</span><?php endif; ?></div>
<form method="post" action="<?= $u('admin/coupon') ?>" data-validate>
<?php $hidden(['csrf'=>$csrf,'id'=>$values['id']??'','operation_key'=>$values['operation_key']??$key()]); ?>
<div class="form-grid"><?php $field('code','Coupon code',$values['code']??'','text','required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*"'); ?>
<label class="field"><span>Discount type</span><select name="kind"><option value="percent" <?= ($values['kind']??'percent')==='percent'?'selected':'' ?>>Percentage</option><option value="fixed" <?= ($values['kind']??'')==='fixed'?'selected':'' ?>>Fixed INR</option></select></label>
<?php $field('value','Discount (% or INR, according to type)',$values['value']??'','number','required min="0" step="0.01"');
$field('min_order','Minimum subtotal (INR)',$values['min_order']??'0','number','required min="0" step="0.01"');
$field('max_discount','Maximum discount (INR, optional)',$values['max_discount']??'','number','min="0" step="0.01"');
$field('usage_limit','Total uses allowed (optional)',$values['usage_limit']??'','number','min="1" max="2147483647"');
$field('per_user_limit','Uses per customer (optional)',$values['per_user_limit']??'','number','min="1" max="10000"');
$field('starts_at','Starts (UTC, optional)',$values['starts_at']??'','datetime-local');$field('ends_at','Ends (UTC, optional)',$values['ends_at']??'','datetime-local'); ?>
<label class="check-field full"><input type="checkbox" name="enabled" value="1" <?= ($values['enabled']??1)?'checked':'' ?>><span>Enabled — available at checkout</span></label></div>
<div class="form-actions"><button class="button">Save coupon</button><a class="button secondary" href="<?= $u('admin/coupons') ?>">Back to coupons</a></div></form></section>
<?php if($coupon): ?><section class="panel archive-panel"><div><h2>Archive this coupon</h2><p><?= $e($coupon['used_count']) ?> uses recorded. Existing orders keep their original discounts.</p></div><form method="post" action="<?= $u('admin/coupon/archive') ?>" data-confirm="Archive this coupon?"><?php $hidden(['csrf'=>$csrf,'id'=>$coupon['id'],'operation_key'=>$key()]); ?><button class="button danger secondary">Archive coupon</button></form></section><?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
