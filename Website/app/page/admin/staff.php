<?php $module='staff';$title='Staff access';$description='Create staff accounts and set each person\'s role. Only a superadmin can manage access.';require __DIR__.'/header.php';
$roleLabels=['superadmin'=>'Superadmin','admin'=>'Admin','catalog_manager'=>'Catalog manager','operations'=>'Operations','viewer'=>'Viewer','customer'=>'Remove staff access'];
$assignable=static fn()=>array_filter($roles,static fn($r)=>$r!=='superadmin'||$isSuper); ?>
<?php if($can('admin.access.edit')): ?>
<section class="panel"><div class="panel-heading"><div><h2>Create a staff account</h2><p>The person signs in with this email and password. Choose one role. Only a superadmin can create superadmins; no other role can create accounts.</p></div></div>
<form method="post" action="<?= $u('admin/staff') ?>" data-validate autocomplete="off">
<?php $hidden(['csrf'=>$csrf,'operation_key'=>$values['operation_key']??$key()]); ?>
<div class="form-grid"><?php $field('name','Full name',$values['name']??'','text','required maxlength="190"');$field('email','Email',$values['email']??'','email','required maxlength="190" autocomplete="off"');$field('password','Temporary password','','password','required minlength="12" maxlength="72" autocomplete="new-password"'); ?>
<label class="field"><span>Role</span><select name="role" required><option value="">Select role</option><?php foreach($assignable() as $r): ?><option value="<?= $e($r) ?>" <?= ($values['role']??'')===$r?'selected':'' ?>><?= $e($roleLabels[$r]??$r) ?></option><?php endforeach; ?></select></label></div>
<p class="help-text">Passwords must be 12–72 characters. Hand the credentials to the person privately; they can change the password after signing in.</p>
<div class="form-actions"><button class="button">Create staff account</button></div></form></section>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Staff accounts</h2><p><?= count($roster) ?> account(s) with store access. Developer access is managed separately.</p></div></div>
<div class="table-scroll"><table class="admin-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><?php if($can('admin.access.edit')): ?><th>Change role</th><?php endif; ?></tr></thead><tbody>
<?php foreach($roster as $member): ?><tr>
<td><strong><?= $e($member['name']) ?></strong></td><td><?= $e($member['email']) ?></td>
<td><span class="pill"><?= $e($roleLabels[$member['role']]??$member['role']) ?></span></td>
<td><?= ((int)$member['status']===1&&(int)$member['email_verify']===1)?'Active':'Inactive' ?></td>
<?php if($can('admin.access.edit')): ?><td><?php if($member['role']==='superadmin'&&!$isSuper): ?><span class="help-text">Superadmin — needs a superadmin to change</span><?php else: ?>
<form method="post" action="<?= $u('admin/staff/role') ?>" class="inline-role"><?php $hidden(['csrf'=>$csrf,'user_id'=>$member['id'],'operation_key'=>$key()]); ?>
<select name="role" aria-label="Role for <?= $e($member['name']) ?>"><?php foreach(array_merge($assignable(),['customer']) as $r): ?><option value="<?= $e($r) ?>" <?= $member['role']===$r?'selected':'' ?>><?= $e($roleLabels[$r]??$r) ?></option><?php endforeach; ?></select>
<button class="button secondary">Save</button></form><?php endif; ?></td><?php endif; ?>
</tr><?php endforeach; ?>
<?php if(!$roster): ?><tr><td colspan="5">No staff accounts yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require __DIR__.'/footer.php'; ?>
