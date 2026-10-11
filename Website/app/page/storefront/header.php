<?php
$e = [StorefrontController::class, 'escape'];
$u = static fn(string $path = ''): string => $e(StorefrontController::url($path));
$m = [StorefrontController::class, 'money'];
$q = [StorefrontController::class, 'quantity'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'Maa Sarada Marble') ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#faf8f5;color:#252525;font:16px/1.5 system-ui,sans-serif}header,main,footer{max-width:1100px;margin:auto;padding:1.25rem}nav{display:flex;gap:1rem;flex-wrap:wrap}a{color:#92402e}h1,h2{line-height:1.2}form,article,.notice{padding:1rem;border:1px solid #ded8cf;border-radius:12px;background:#fff;margin-bottom:1rem}label{display:block;margin:.6rem 0}input,select,button{font:inherit;max-width:100%;padding:.5rem;border:1px solid #aaa;border-radius:6px}input:not([type=hidden]),select{width:100%}button{cursor:pointer;background:#943f30;color:white;border:0;margin-top:.5rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:1rem}img{max-width:100%;height:auto}.muted{color:#626262}small{display:block}dl{display:grid;grid-template-columns:1fr 1fr;gap:.4rem}dd{margin:0;text-align:right}
</style></head><body><header><nav aria-label="Main navigation"><a href="<?= $u('shop') ?>">Maa Sarada Marble</a><a href="<?= $u('cart') ?>">Cart</a><a href="<?= $u('account') ?>">Account</a></nav></header><main>
