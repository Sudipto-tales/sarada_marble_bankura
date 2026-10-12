<?php
$e = [StorefrontController::class, 'escape'];
$u = static fn(string $path = ''): string => $e(StorefrontController::url($path));
$m = [StorefrontController::class, 'money'];
$q = [StorefrontController::class, 'quantity'];
$a = static fn(string $path): string => $u('assets/storefront/' . $path);
$page = $page ?? 'commerce';
$csrf = RequestSecurity::csrfToken();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#647158">
<title><?= $e($title ?? 'Maa Sarada Marble') ?></title><link rel="icon" href="<?= $a('assets/brand/logo_mark.webp') ?>">
<?php foreach (['storefront-base.css', 'storefront.css', $page === 'home' ? 'home.css' : 'storefront-pages.css', 'php.css', ...($pageStyles ?? [])] as $css): ?><link rel="stylesheet" href="<?= $a($css) ?>"><?php endforeach; ?>
<script src="<?= $a('php.js') ?>" defer></script><?php foreach ($pageScripts ?? [] as $script): ?><script src="<?= $a($script) ?>" defer></script><?php endforeach; ?></head><body data-page="<?= $e($page) ?>">
<a class="skip-link" href="#main">Skip to content</a><div class="utility"><div class="wrap utility-inner"><span>Bankura, West Bengal</span><span>Natural stone. Thoughtfully selected.</span><a href="<?= $u('catalogues') ?>">Brand catalogues ↗</a></div></div>
<header><div class="wrap navigation"><a class="brand" href="<?= $u() ?>" aria-label="Maa Sarada home"><img src="<?= $a('assets/brand/logo_full.webp') ?>" alt="Maa Sarada Marble &amp; Sanitation"></a><button class="icon mobile-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="site-nav">☰</button><nav id="site-nav" aria-label="Main navigation"><a href="<?= $u('shop') ?>">Collections</a><a href="<?= $u('shop') ?>">Shop stone</a><a href="<?= $u('catalogues') ?>">Catalogues</a><a href="<?= $u('calculator') ?>">Calculator</a><a href="<?= $u('visualizer') ?>">Visualizer</a></nav><form class="search" action="<?= $u('shop') ?>" role="search"><input type="search" name="q" maxlength="80" aria-label="Search products" placeholder="Find your next surface…"><button aria-label="Search">⌕</button></form><div class="header-actions"><a class="icon" href="<?= $u('wishlist') ?>" aria-label="Saved materials">♡</a><a class="icon" href="<?= $u('compare') ?>" aria-label="Compare materials">⇄</a><a class="icon" href="<?= $u('account') ?>">Account</a><a class="icon" href="<?= $u('cart') ?>">Bag ↗</a></div></div></header><main id="main" class="wrap" tabindex="-1">
