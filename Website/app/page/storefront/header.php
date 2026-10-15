<?php
$e = [StorefrontController::class, 'escape'];
$u = static fn(string $path = ''): string => $e(StorefrontController::url($path));
$m = [StorefrontController::class, 'money'];
$q = [StorefrontController::class, 'quantity'];
$a = static fn(string $path): string => $u('assets/storefront/' . $path);
$page = $page ?? 'commerce';
$csrf = RequestSecurity::csrfToken();
require __DIR__ . '/icons.php';
$navCounts = ['wishlist'=>0, 'compare'=>0, 'cart'=>0];
if (!isset($status) && isset($GLOBALS['pdo'])) {
    $navActor = CommerceAccess::actor();
    $navCounts['wishlist'] = count((new WishlistService($GLOBALS['pdo']))->ids($navActor['id'] ?? null));
    $navCounts['compare'] = count($_SESSION['compare_products'] ?? []);
    $navPrincipal = CartService::principal(false);
    if ($navPrincipal !== null) $navCounts['cart'] = (new CartService($GLOBALS['pdo']))->itemCount($navPrincipal);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#647158"><meta name="description" content="Explore Maa Sarada marble and granite, plan your space and browse brand catalogues.">
<title><?= $e($title ?? 'Maa Sarada Marble') ?></title><link rel="icon" href="<?= $a('assets/brand/logo_mark.webp') ?>">
<?php foreach (['storefront-base.css', 'storefront.css', 'php.css', $page === 'home' ? 'home.css' : 'storefront-pages.css', 'html-parity.css', ...($pageStyles ?? [])] as $css): ?><link rel="stylesheet" href="<?= $a($css) ?>"><?php endforeach; ?>
<script src="<?= $a('php.js') ?>" defer></script><?php foreach ($pageScripts ?? [] as $script): ?><script src="<?= $a($script) ?>" defer></script><?php endforeach; ?></head><body data-page="<?= $e($page) ?>">
<a class="skip-link" href="#main">Skip to content</a><div class="utility"><div class="wrap utility-inner"><span>Bankura, West Bengal</span><span>Natural stone. Thoughtfully selected.</span><a href="<?= $u('catalogues') ?>">Brand catalogues ↗</a></div></div>
<header><div class="wrap navigation"><a class="brand" href="<?= $u() ?>" aria-label="Maa Sarada home"><img src="<?= $a('assets/brand/logo_full.webp') ?>" alt="Maa Sarada Marble &amp; Sanitation"></a><button class="icon mobile-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="site-nav">☰</button><nav id="site-nav" aria-label="Main navigation"><a href="<?= $u('shop') ?>">Collections</a><a href="<?= $u('shop') ?>">Shop stone</a><a href="<?= $u('catalogues') ?>">Catalogues</a><a href="<?= $u() ?>#home-offers">Offers</a><a href="<?= $u('visualizer') ?>">Visualizer</a></nav><form class="search" action="<?= $u('shop') ?>" role="search"><input type="search" name="q" maxlength="80" aria-label="Search products" placeholder="Find your next surface…"><button aria-label="Search"><?= $icon('search') ?></button></form><div class="header-actions"><a class="icon" href="<?= $u('compare') ?>" aria-label="Compare materials">⇄<b><?= $navCounts['compare'] ?></b></a><a class="icon" href="<?= $u('wishlist') ?>" aria-label="Saved materials"><?= $icon('heart') ?><b><?= $navCounts['wishlist'] ?></b></a><a class="icon" href="<?= $u('account') ?>" aria-label="Your account"><?= $icon('user') ?></a><a class="icon" href="<?= $u('cart') ?>" aria-label="Shopping bag"><?= $icon('bag') ?><b><?= $navCounts['cart'] ?></b></a></div></div></header><main id="main" class="wrap" tabindex="-1">
