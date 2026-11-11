<?php $module='delivery';$title='Delivery zones';$description='Group serviceable PIN codes into zones with an ETA window and delivery charge.';require __DIR__.'/header.php'; ?>
<section class="panel"><div class="panel-heading"><div><h2>Delivery zones</h2><p>A PIN code belongs to one zone. The product page checks a shopper's PIN against these, and checkout can block unknown PIN codes when strict delivery is on (Store settings).</p></div></div>
<div class="table-scroll"><table class="admin-table"><thead><tr><th>Zone</th><th>ETA (days)</th><th>Delivery charge</th><th>PIN codes</th><th>Status</th><th></th></tr></thead><tbody>
<?php if(!$zones): ?><tr><td colspan="6">No delivery zones yet. Create one below.</td></tr><?php endif; ?>
<?php foreach($zones as $z): ?><tr><td><?= $e($z['name']) ?></td><td><?= (int)$z['eta_min_days'] ?>&ndash;<?= (int)$z['eta_max_days'] ?></td><td><?= $m($z['shipping_minor']) ?></td><td><?= (int)$z['pincode_count'] ?></td><td><?= ((int)$z['active']===1?'Active':'Disabled') ?></td><td><a class="button button-small" href="<?= $u('admin/delivery?id='.$z['id']) ?>">Edit</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>

<section class="panel"><h2><?= $zone?'Edit zone: '.$e($zone['name']):'New zone' ?></h2>
<?php $zv=$values??[]; ?>
<form method="post" action="<?= $u('admin/delivery') ?>">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$zv['operation_key']??$key(),'id'=>$zone['id']??($zv['id']??'')]); ?>
<div class="form-grid"><?php
$field('name','Zone name',$zv['name']??($zone['name']??''),'text','required maxlength="120"');
$field('eta_min_days','ETA minimum days',$zv['eta_min_days']??($zone['eta_min_days']??''),'number','required min="1" max="365"');
$field('eta_max_days','ETA maximum days',$zv['eta_max_days']??($zone['eta_max_days']??''),'number','required min="1" max="365"');
$field('shipping','Delivery charge (INR, display only for now)',$zv['shipping']??($zone?AdminReadService::decimal($zone['shipping_minor'],2):'0'),'number','min="0" step="0.01"'); ?>
<label><input type="checkbox" name="active" value="1" <?= (!$zone||(int)$zone['active']===1||!empty($zv['active']))?'checked':'' ?>> Active</label></div>
<button class="button"><?= $zone?'Save zone':'Create zone' ?></button></form></section>

<?php if($zone): ?>
<section class="panel"><h2>PIN codes in <?= $e($zone['name']) ?></h2>
<p class="help-text">Paste 6-digit PIN codes separated by spaces, commas or new lines. Existing PIN codes are moved to this zone. Invalid entries are reported and skipped.</p>
<form method="post" action="<?= $u('admin/delivery/pincodes') ?>">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$key(),'zone_id'=>$zone['id']]); ?>
<label class="field full"><span>Add PIN codes</span><textarea name="pincodes" rows="4" placeholder="722101, 722102&#10;700001"></textarea></label>
<button class="button">Add PIN codes</button></form>
<?php if($zone['pincodes']): ?><p class="help-text"><?= count($zone['pincodes']) ?> active PIN code(s): <?= $e(implode(', ',array_slice($zone['pincodes'],0,200))) ?><?= count($zone['pincodes'])>200?' …':'' ?></p><?php else: ?><p class="help-text">No PIN codes mapped to this zone yet.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
