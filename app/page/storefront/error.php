<?php $title = 'Store request'; require __DIR__ . '/header.php'; ?>
<h1><?= $e($status) ?></h1><p role="alert"><?= $e($message) ?></p><p><a href="<?= $u('cart') ?>">Review cart</a> · <a href="<?= $u('account') ?>">Account</a> · <a href="<?= $u('shop') ?>">Continue shopping</a></p>
<?php require __DIR__ . '/footer.php'; ?>
