<?php $module='settings';$title='Store settings';$description='Configure checkout tax, delivery and payment availability.';require __DIR__.'/header.php'; ?>
<section class="panel"><h2>Checkout</h2>
<?php if($can('admin.settings.edit')): ?>
<?php if($values===null){$values=['tax_bps'=>$settings['tax_bps'],'shipping'=>AdminReadService::decimal($settings['shipping_minor'],2),'advance_bps'=>$settings['advance_bps'],'cod_enabled'=>(int)$settings['cod_enabled'],'razorpay_enabled'=>(int)$settings['razorpay_enabled']];foreach($settings['cashback'] as $cm=>$cv){$values["cashback_{$cm}_bps"]=$cv['bps'];$values["cashback_{$cm}_cap"]=AdminReadService::decimal($cv['cap_minor'],2);}} ?>
<form method="post" action="<?= $u('admin/settings') ?>">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$values['operation_key']??$key()]); ?>
<div class="form-grid"><?php $field('tax_bps','Default GST (basis points: 1800 = 18%)',$values['tax_bps']??'','number','required min="0" max="10000"');$field('shipping','Flat delivery charge (INR)',$values['shipping']??'','number','required min="0" step="0.01"');$field('advance_bps','Default advance payment (basis points: 2000 = 20%, 0 = full payment)',$values['advance_bps']??'0','number','required min="0" max="10000"'); ?>
<?php foreach(['cod_enabled'=>'Cash on delivery','razorpay_enabled'=>'Online payment (Razorpay)'] as $name=>$label): ?><label><input type="checkbox" name="<?= $e($name) ?>" value="1" <?= !empty($values[$name])?'checked':'' ?>><?= $e($label) ?></label><?php endforeach; ?></div>
<p class="help-text">Cashback is computed from the cart subtotal and shown next to each payment method at checkout. Display only &mdash; no amount is credited. Set percent to 0 for none; cap 0 means no limit.</p>
<?php foreach(['cod'=>'Cash on delivery','razorpay'=>'Online payment (Razorpay)','simulation'=>'Development simulation'] as $cm=>$clabel): ?>
<div class="form-grid"><?php $field("cashback_{$cm}_bps",$clabel.' cashback % (basis points: 500 = 5%)',$values["cashback_{$cm}_bps"]??'0','number','min="0" max="10000"');$field("cashback_{$cm}_cap",$clabel.' cashback cap (INR, 0 = none)',$values["cashback_{$cm}_cap"]??'0','number','min="0" step="0.01"'); ?></div>
<?php endforeach; ?>
<button class="button">Save checkout settings</button></form>
<?php else: ?><dl><dt>Delivery</dt><dd><?= $m($settings['shipping_minor']) ?></dd><dt>Default GST</dt><dd><?= $e(AdminReadService::decimal($settings['tax_bps'],2)) ?>%</dd><dt>Advance payment</dt><dd><?= (int)$settings['advance_bps']>0?$e(AdminReadService::decimal($settings['advance_bps'],2)).'%':'Full payment' ?></dd><dt>Payment methods</dt><dd><?= $payments?$e(implode(', ',array_values($payments))):'None configured' ?></dd></dl><?php endif; ?>
<p>Category tax overrides apply after coupon discounts. Gateway credentials remain operator-managed.</p>
<p>Customer registration: <?= $signup?'Available':'Disabled' ?>. Product image decoder: <?= ProductImageService::available()?'Available':'PHP GD required' ?>.</p></section>
<section class="panel"><h2>Customer OTP channels</h2>
<p class="help-text">Email codes are always the default. Toggle WhatsApp or SMS on and store its credentials to let shoppers choose that channel for first-time verification and two-step sign-in. Leave a secret field blank to keep the stored value.</p>
<?php if($can('admin.settings.edit')): ?>
<form method="post" action="<?= $u('admin/settings/otp') ?>">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$key()]); ?>
<fieldset><legend>WhatsApp (Meta Cloud API)</legend>
<label><input type="checkbox" name="otp_whatsapp_enabled" value="1" <?= !empty($otp['whatsapp']['enabled'])?'checked':'' ?>> Enable WhatsApp codes</label>
<div class="form-grid"><?php $field('otp_whatsapp_phone_id','Phone number ID',$otp['whatsapp']['phone_id']??'','text','');$field('otp_whatsapp_template','Authentication template name',$otp['whatsapp']['template']??'','text',''); ?>
<label class="field"><span>Access token <?= !empty($otp['whatsapp']['token'])?'(stored)':'' ?></span><input type="password" name="otp_whatsapp_token" autocomplete="off" placeholder="<?= !empty($otp['whatsapp']['token'])?'•••••• leave blank to keep':'' ?>"></label></div></fieldset>
<fieldset><legend>SMS (MSG91)</legend>
<label><input type="checkbox" name="otp_sms_enabled" value="1" <?= !empty($otp['sms']['enabled'])?'checked':'' ?>> Enable SMS codes</label>
<div class="form-grid"><?php $field('otp_sms_template','Flow/template ID',$otp['sms']['template']??'','text','');$field('otp_sms_sender','Sender ID',$otp['sms']['sender']??'','text',''); ?>
<label class="field"><span>Auth key <?= !empty($otp['sms']['authkey'])?'(stored)':'' ?></span><input type="password" name="otp_sms_authkey" autocomplete="off" placeholder="<?= !empty($otp['sms']['authkey'])?'•••••• leave blank to keep':'' ?>"></label></div></fieldset>
<button class="button">Save OTP channels</button></form>
<?php else: ?><dl><dt>WhatsApp</dt><dd><?= !empty($otp['whatsapp']['enabled'])?'Enabled':'Disabled' ?></dd><dt>SMS</dt><dd><?= !empty($otp['sms']['enabled'])?'Enabled':'Disabled' ?></dd></dl><?php endif; ?></section>
<?php require __DIR__.'/footer.php'; ?>
