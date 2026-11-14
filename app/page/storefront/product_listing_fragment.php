<?php
/**
 * XHR-only response for the shop page: the results block alone, with no document shell, so
 * product-listing.js can swap #catalog-results in place. It wires up the same view helpers header.php
 * would (escape/url/money/quantity, icons, the viewer's wishlist) and then includes the shared
 * results partial, so a card renders identically whether it arrived with the page or over fetch.
 */
$e = [StorefrontController::class, 'escape'];
$u = static fn(string $path = ''): string => $e(StorefrontController::url($path));
$m = [StorefrontController::class, 'money'];
$q = [StorefrontController::class, 'quantity'];
require __DIR__ . '/icons.php';
$navActor = CommerceAccess::actor();
$navWishlist = array_map('intval', (new WishlistService($GLOBALS['pdo']))->ids($navActor['id'] ?? null));
require __DIR__ . '/product_listing_results.php';
