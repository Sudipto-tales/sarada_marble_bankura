<?php $module='settings';$title='Store settings';$description='Configure checkout tax, delivery and payment availability.';require __DIR__.'/header.php'; ?>
<section class="panel"><h2>Checkout</h2>
<?php if($can('admin.settings.edit')): ?>
<?php if($values===null){$values=['tax_bps'=>$settings['tax_bps'],'shipping'=>AdminReadService::decimal($settings['shipping_minor'],2),'cod_enabled'=>(int)$settings['cod_enabled'],'razorpay_enabled'=>(int)$settings['razorpay_enabled']];foreach($settings['cashback'] as $cm=>$cv){$values["cashback_{$cm}_bps"]=$cv['bps'];$values["cashback_{$cm}_cap"]=AdminReadService::decimal($cv['cap_minor'],2);}} ?>
<form method="post" action="<?= $u('admin/settings') ?>">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$values['operation_key']??$key()]); ?>
<div class="form-grid"><?php $field('tax_bps','Default GST (basis points: 1800 = 18%)',$values['tax_bps']??'','number','required min="0" max="10000"');$field('shipping','Flat delivery charge (INR)',$values['shipping']??'','number','required min="0" step="0.01"'); ?>
<?php foreach(['cod_enabled'=>'Cash on delivery','razorpay_enabled'=>'Online payment (Razorpay)'] as $name=>$label): ?><label><input type="checkbox" name="<?= $e($name) ?>" value="1" <?= !empty($values[$name])?'checked':'' ?>><?= $e($label) ?></label><?php endforeach; ?></div>
<p class="help-text">Cashback is computed from the cart subtotal and shown next to each payment method at checkout. Display only &mdash; no amount is credited. Set percent to 0 for none; cap 0 means no limit.</p>
<?php foreach(['cod'=>'Cash on delivery','razorpay'=>'Online payment (Razorpay)','simulation'=>'Development simulation'] as $cm=>$clabel): ?>
<div class="form-grid"><?php $field("cashback_{$cm}_bps",$clabel.' cashback % (basis points: 500 = 5%)',$values["cashback_{$cm}_bps"]??'0','number','min="0" max="10000"');$field("cashback_{$cm}_cap",$clabel.' cashback cap (INR, 0 = none)',$values["cashback_{$cm}_cap"]??'0','number','min="0" step="0.01"'); ?></div>
<?php endforeach; ?>
<button class="button">Save checkout settings</button></form>
<?php else: ?><dl><dt>Delivery</dt><dd><?= $m($settings['shipping_minor']) ?></dd><dt>Default GST</dt><dd><?= $e(AdminReadService::decimal($settings['tax_bps'],2)) ?>%</dd><dt>Payment methods</dt><dd><?= $payments?$e(implode(', ',array_values($payments))):'None configured' ?></dd></dl><?php endif; ?>
<p>Category tax overrides apply after coupon discounts. Gateway credentials remain operator-managed.</p>
<p>Customer registration: <?= $signup?'Available':'Disabled' ?>. Product image decoder: <?= ProductImageService::available()?'Available':'PHP GD required' ?>.</p></section>
<?php require __DIR__.'/footer.php'; ?>
