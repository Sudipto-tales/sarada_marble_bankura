<?php $module='coupons';$title=$coupon?'Edit coupon':'Add coupon';$description='Set a discount, availability window and usage limits.';require __DIR__.'/header.php';$values=$values??[]; ?>
<section class="panel"><form method="post" action="<?= $u('admin/coupon') ?>">
<?php $hidden(['csrf'=>$csrf,'id'=>$values['id']??'','operation_key'=>$values['operation_key']??$key()]); ?>
<div class="form-grid"><?php $field('code','Coupon code',$values['code']??'','text','required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*"'); ?>
<label>Discount type<select name="kind"><option value="percent" <?= ($values['kind']??'percent')==='percent'?'selected':'' ?>>Percentage</option><option value="fixed" <?= ($values['kind']??'')==='fixed'?'selected':'' ?>>Fixed INR</option></select></label>
<?php $field('value','Discount (% or INR, according to type)',$values['value']??'','number','required min="0" step="0.01"');
$field('min_order','Minimum subtotal (INR)',$values['min_order']??'0','number','required min="0" step="0.01"');
$field('max_discount','Maximum discount (INR, optional)',$values['max_discount']??'','number','min="0" step="0.01"');
$field('usage_limit','Total uses allowed (optional)',$values['usage_limit']??'','number','min="1" max="2147483647"');
$field('per_user_limit','Uses per customer (optional)',$values['per_user_limit']??'','number','min="1" max="10000"');
$field('starts_at','Starts (UTC, optional)',$values['starts_at']??'','datetime-local');$field('ends_at','Ends (UTC, optional)',$values['ends_at']??'','datetime-local'); ?>
<label><input type="checkbox" name="enabled" value="1" <?= ($values['enabled']??1)?'checked':'' ?>>Enabled</label></div><button class="button">Save coupon</button></form>
<?php if($coupon): ?><p><?= $e($coupon['used_count']) ?> uses recorded. Existing orders keep their original discounts.</p><form method="post" action="<?= $u('admin/coupon/archive') ?>"><?php $hidden(['csrf'=>$csrf,'id'=>$coupon['id'],'operation_key'=>$key()]); ?><button class="button secondary">Archive coupon</button></form><?php endif; ?></section>
<?php require __DIR__.'/footer.php'; ?>
