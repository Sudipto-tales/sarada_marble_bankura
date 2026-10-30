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
$navWishlist = [];
$navAddress = null;
if (!isset($status) && isset($GLOBALS['pdo'])) {
    $navActor = CommerceAccess::actor();
    $navWishlist = array_map('intval', (new WishlistService($GLOBALS['pdo']))->ids($navActor['id'] ?? null));
    $navCounts['wishlist'] = count($navWishlist);
    // $navCounts['compare'] = count($_SESSION['compare_products'] ?? []);
    $navPrincipal = CartService::principal(false);
    if ($navPrincipal !== null) $navCounts['cart'] = (new CartService($GLOBALS['pdo']))->itemCount($navPrincipal);
    // The default address is the delivery address checkout preselects, so it is the one to show.
    if ($navActor !== null) $navAddress = CommerceDatabase::query($GLOBALS['pdo'],
        'SELECT label,city,state,postal_code FROM addresses WHERE user_id=? AND enabled=1 ORDER BY is_default DESC,id LIMIT 1',
        [$navActor['id']])->fetch() ?: null;
}
// shortcut: language choice is stored per browser and only sets <html lang>; wire it to real
// translations when storefront copy is externalised.
$navLanguages = ['en'=>'English', 'bn'=>'বাংলা', 'hi'=>'हिन्दी'];
$navLang = isset($_COOKIE['lang'], $navLanguages[$_COOKIE['lang']]) ? $_COOKIE['lang'] : 'en';
// Header count badge. An empty bag/shortlist renders the element but keeps it hidden, so the
// wishlist script can reveal it again without rebuilding the markup.
$navBadge = static function (int $count) use ($e): string {
    $shown = max(0, min(99, $count));
    return '<b' . ($count < 1 ? ' class="is-zero"' : '') . ' aria-hidden="' . ($count < 1 ? 'true' : 'false') . '">'
        . $e($count > 99 ? '99+' : (string)$shown) . '</b>';
};
?>
<!doctype html><html lang="<?= $e($navLang) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#082c40"><meta name="description" content="Explore Maa Sarada marble and granite, plan your space and browse brand catalogues.">
<title><?= $e($title ?? 'Maa Sarada Marble') ?></title><link rel="icon" href="<?= $a('assets/brand/logo_mark.webp') ?>">
<?php foreach (['storefront-base.css', 'storefront.css', 'php.css', $page === 'home' ? 'home.css' : 'storefront-pages.css', 'html-parity.css', 'refresh.css', ...($pageStyles ?? []), 'storefront-ui.css'] as $css): ?><link rel="stylesheet" href="<?= $a($css) ?>"><?php endforeach; ?>
<script src="<?= $a('php.js') ?>" defer></script><?php foreach ($pageScripts ?? [] as $script): ?><script src="<?= $a($script) ?>" defer></script><?php endforeach; ?></head><body data-page="<?= $e($page) ?>">
<a class="skip-link" href="#main">Skip to content</a><div class="utility"><div class="wrap utility-inner"><span>Bankura, West Bengal</span><span>Natural stone. Thoughtfully selected.</span><a href="<?= $u('catalogues') ?>">Brand catalogues ↗</a></div></div>
<header><div class="wrap navigation"><a class="brand" href="<?= $u() ?>" aria-label="Maa Sarada home"><img src="<?= $a('assets/brand/logo_full.webp') ?>" alt="Maa Sarada Marble &amp; Sanitation"></a><button class="icon mobile-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="site-drawer">☰</button><form class="search" action="<?= $u('shop') ?>" role="search" data-api="<?= $u('api/v1/products') ?>" data-base="<?= $u() ?>"><input type="search" name="q" maxlength="80" aria-label="Search products" placeholder="Find your next surface…" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="search-suggest"><button aria-label="Search"><?= $icon('search') ?></button></form><div class="header-locale"><a class="locale-address" href="<?= $u(($navActor ?? null) ? 'account' : 'account/login') ?>"><?= $icon('pin') ?><span><b>Deliver to</b><small><?= $e($navAddress ? trim(($navAddress['city'] ?: $navAddress['label']) . ' ' . $navAddress['postal_code']) : 'Select an address') ?></small></span></a><button class="locale-lang" type="button" data-language data-codes="<?= $e(implode(',', array_keys($navLanguages))) ?>" aria-label="Language: <?= $e($navLanguages[$navLang]) ?>. Click to change"><?= $icon('globe') ?><b><?= $e(strtoupper($navLang)) ?></b></button></div><div id="site-drawer"><button class="drawer-scrim" type="button" data-close-drawer tabindex="-1" aria-hidden="true"></button><div class="drawer-panel"><button class="icon drawer-close" type="button" data-close-drawer aria-label="Close navigation"><?= $icon('close') ?></button><nav id="site-nav" aria-label="Main navigation"><?php foreach ([['', 'Home', 'home'], ['offers', "Today's Offer", 'offers'], ['shop', 'Shop', 'products'], ['catalogues', 'Catalogs', 'catalogues']] as [$navRoute, $navLabel, $navMatch]): ?><a href="<?= $u($navRoute) ?>"<?= $page === $navMatch ? ' aria-current="page"' : '' ?>><?= $e($navLabel) ?></a><?php endforeach; ?></nav><div class="header-actions"><?php /* Compare hidden storefront-wide:
<a class="icon" href="<?= $u('compare') ?>" aria-label="Compare materials"><?= $icon('compare') ?><b><?= $navCounts['compare'] ?></b></a>
*/ ?><a class="icon" href="<?= $u('wishlist') ?>" aria-label="Saved materials"><?= $icon('heart') ?><?= $navBadge($navCounts['wishlist']) ?><em>Favourites</em></a><a class="icon" href="<?= $u('account') ?>" aria-label="Your account"><?= $icon('user') ?><em>Account</em></a><a class="icon" href="<?= $u('cart') ?>" aria-label="Shopping bag"><?= $icon('bag') ?><?= $navBadge($navCounts['cart']) ?><em>Cart</em></a></div></div></div></div></header><main id="main" class="wrap" tabindex="-1"><?php if (isset($_SESSION['cart_notice'])): ?><p class="notice cart-notice" role="status"><?= $e($_SESSION['cart_notice']) ?> <a href="<?= $u('cart') ?>">View bag ↗</a></p><?php unset($_SESSION['cart_notice']); endif; ?>
