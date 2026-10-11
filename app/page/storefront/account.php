<?php $title = 'Your account'; require __DIR__ . '/header.php'; $csrf = RequestSecurity::csrfToken(); ?>
<h1>Your account</h1>
<?php if (!$actor): ?><form method="post" action="<?= $u('account') ?>"><h2>Sign in</h2><p>Use your verified store account to continue checkout.</p><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<label>Email<input type="email" name="email" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form>
<?php else: ?><p><?= $e($actor['name']) ?></p><form method="post" action="<?= $u('account/logout') ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><button>Sign out</button></form>
<h2>Delivery addresses</h2><?php foreach ($addresses as $address): ?><article><?= $e($address['recipient'] . ', ' . $address['line1'] . ', ' . $address['city'] . ' ' . $address['postal_code']) ?></article><?php endforeach; ?>
<form method="post" action="<?= $u('account/addresses') ?>"><h2>Add delivery address</h2><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php foreach (['recipient'=>'Recipient','phone'=>'Phone','line1'=>'Address line 1','line2'=>'Address line 2 (optional)','city'=>'City','state'=>'State','postal_code'=>'PIN code'] as $field=>$label): ?><label><?= $e($label) ?><input name="<?= $e($field) ?>" <?= $field === 'line2' ? '' : 'required' ?> maxlength="190"></label><?php endforeach; ?><button>Save address</button></form><a href="<?= $u('checkout') ?>">Continue checkout</a>
<?php endif; require __DIR__ . '/footer.php'; ?>
