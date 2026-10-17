<?php

require_once __DIR__ . '/../core/RouteProvider.php';

class ViewRouteProvider extends RouteProvider
{
    public static function routes(): array
    {
        return [
            'default' => ['GET' => ['StorefrontProduct', 'home']],
            'hello' => ['Welcome', 'hello'],
            ... (DEVELOPER_ENABLED ? [
                'developer' => ['Developer', 'index'],
                'developer/login' => ['Developer', 'login'],
                'developer/logout' => ['Developer', 'logout'],
                ...array_fill_keys(array_map(fn($page) => 'developer/' . $page,
                    ['overview', 'apis', 'server', 'workflows', 'knowledge', 'incidents', 'activity', 'access', 'settings']), ['Developer', 'index']),
            ] : []),
            // Storefront routes
            'calculator' => ['GET' => ['StorefrontTools', 'calculator']],
            'compare' => ['GET' => ['StorefrontTools', 'comparison'], 'POST' => ['StorefrontTools', 'compareChange']],
            'wishlist' => ['GET' => ['StorefrontTools', 'wishlist'], 'POST' => ['StorefrontTools', 'saveWishlist']],
            'visualizer/help' => ['GET' => ['StorefrontTools', 'help']],
            'visualizer' => ['GET' => ['StorefrontTools', 'visualizer']],
            'saved-designs' => ['GET' => ['StorefrontTools', 'designs']],
            'catalogues' => ['GET' => ['StorefrontProduct', 'catalogues']],
            'catalogue/{slug}' => ['GET' => ['StorefrontProduct', 'catalogue']],
            'shop' => ['GET' => ['StorefrontProduct', 'listing']],
            'category/{slug}' => ['GET' => ['StorefrontProduct', 'listing']],
            'product/{slug}/reviews' => ['GET' => ['StorefrontProduct', 'reviews'], 'POST' => ['StorefrontProduct', 'reviews']],
            'buy-now' => ['POST' => ['Cart', 'buyNow']],
            'cart/pair' => ['POST' => ['Cart', 'addPair']],
            'admin/product-reviews' => ['GET' => ['Admin', 'reviews'], 'POST' => ['Admin', 'reviews']],
            'product/{slug}' => ['GET' => ['StorefrontProduct', 'detail']],
            'cart' => ['GET' => ['Cart', 'index'], 'POST' => ['Cart', 'change']],
            'checkout' => ['GET' => ['Cart', 'checkout'], 'POST' => ['Cart', 'placeOrder']],
            'checkout/coupon' => ['POST'=>['Cart','applyCoupon']],
            'order/{id}' => ['GET' => ['Cart', 'order']],
            'order/{id}/invoice' => ['GET'=>['Cart','invoice']],
            'account' => ['GET' => ['CustomerAccount', 'index'], 'POST' => ['CustomerAccount', 'login']],
            'account/register' => ['GET' => ['CustomerAccount', 'registration'], 'POST' => ['CustomerAccount', 'register']],
            'account/otp' => ['POST' => ['CustomerAccount', 'otp']],
            'account/otp/resend' => ['POST' => ['CustomerAccount', 'resendOtp']],
            'account/forgot' => ['GET' => ['CustomerAccount', 'forgot'], 'POST' => ['CustomerAccount', 'forgot']],
            'account/reset' => ['POST' => ['CustomerAccount', 'reset']],
            'account/pin' => ['POST' => ['CustomerAccount', 'setPin']],
            'account/pin-login' => ['POST' => ['CustomerAccount', 'pinLogin']],
            'account/devices/revoke' => ['POST' => ['CustomerAccount', 'revokeDevices']],
            'auth/verify_email' => ['GET' => ['CustomerAccount', 'verify']],
            'account/addresses/archive' => ['POST' => ['CustomerAccount', 'archiveAddress']],
            'orders' => ['GET' => ['CustomerAccount', 'orders']],
            'account/logout' => ['POST' => ['CustomerAccount', 'logout']],
            'account/delete' => ['POST' => ['CustomerAccount', 'deleteStart']],
            'account/delete/confirm' => ['POST' => ['CustomerAccount', 'deleteConfirm']],
            'account/addresses' => ['POST' => ['CustomerAccount', 'address']],
            // Admin routes
            'payment/verify' => ['POST' => ['Payment', 'verify']],
            'admin/login' => ['GET' => ['Admin', 'login'], 'POST' => ['Admin', 'login']],
            'admin/logout' => ['POST' => ['Admin', 'logout']],
            'admin/data/{slug}' => ['GET' => ['Admin', 'data']],
            'admin/customer' => ['GET' => ['Admin', 'customerForm']],
            'admin/customer/devices' => ['POST' => ['Admin', 'revokeDevices']],
            'admin/taxonomy/{slug}' => ['POST' => ['Admin', 'saveTaxonomy']],
            'admin/{slug}' => ['GET' => ['Admin', 'table']],
            'admin/stock' => ['GET' => ['Admin', 'stockForm'], 'POST' => ['Admin', 'adjustStock']],
            'admin/order' => ['GET' => ['Admin', 'orderForm'], 'POST' => ['Admin', 'transitionOrder']],
            'admin/product/archive' => ['POST' => ['Admin', 'archiveProduct']],
            'admin/product/image' => ['POST' => ['Admin', 'image']],
            'admin/settings' => ['GET' => ['Admin', 'settings'],'POST'=>['Admin','saveSettings']],
            'admin/coupon' => ['GET'=>['Admin','couponForm'],'POST'=>['Admin','saveCoupon']],
            'admin/coupon/archive' => ['POST'=>['Admin','archiveCoupon']],
            'admin/reports' => ['GET'=>['Admin','reports']],
            'admin' => ['GET' => ['Admin', 'index']],
            'admin/products' => ['GET' => ['Admin', 'products']],
            'admin/product' => ['GET' => ['Admin', 'productForm'], 'POST' => ['Admin', 'saveProduct']],
        ];
    }
}

return ViewRouteProvider::routes();
