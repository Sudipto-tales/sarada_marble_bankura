<?php
$e=[StorefrontController::class,'escape'];$u=static fn($path='')=>$e(StorefrontController::url($path));$m=[StorefrontController::class,'money'];$q=[StorefrontController::class,'quantity'];
$csrf=RequestSecurity::csrfToken();$can=static fn($permission)=>in_array($permission,$actor['permissions']??[],true);
$val=static fn($value)=>is_scalar($value)?(string)$value:'';
$hidden=static function(array $fields)use($e,$val){foreach($fields as $name=>$value)echo '<input type="hidden" name="'.$e($name).'" value="'.$e($val($value)).'">';};
$field=static function(string $name,string $label,$value='',string $type='text',string $attributes='',?string $errorKey=null)use($e,$val,$errors){$id='field-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name);$error=$errors[$errorKey??$name]??null;echo '<label class="field" for="'.$e($id).'"><span>'.$e($label).'</span><input id="'.$e($id).'" name="'.$e($name).'" type="'.$e($type).'" value="'.$e($val($value)).'" '.$attributes.($error?' aria-invalid="true" aria-describedby="'.$e($id).'-error"':'').'>';if($error)echo '<small class="field-error" id="'.$e($id).'-error">'.$e($error).'</small>';echo '</label>';};
$key=static fn()=>bin2hex(random_bytes(16));
$module=$module??'';$title=$title??'Store overview';$description=$description??'Manage your materials and daily store operations.';
$navigation=[
    ['DASHBOARD',[
        ['Overview','admin','','', 'overview'],['Analytics','admin/analytics','analytics','admin.analytics.view','analytics'],
    ]],
    ['PRODUCTS',[
        ['Product List','admin/products','products','admin.products.view','products'],['Categories','admin/categories','categories','admin.products.view','categories'],
        ['Category Types','admin/category-types','categories','admin.products.view','category-types'],
        ['Brands','admin/brands','brands','admin.products.view','brands'],['Inventory','admin/inventory','inventory','admin.inventory.view','inventory'],
    ]],
    ['ORDERS',[
        ['All Orders','admin/orders','orders','admin.orders.view','orders'],['Delivery Zones','admin/delivery','delivery','admin.settings.edit','delivery'],
    ]],
    ['CUSTOMERS',[
        ['Customers','admin/customers','customers','admin.customers.view','customers'],['Enquiries','admin/enquiries','enquiries','admin.orders.view','enquiries'],
    ]],
    ['MARKETING',[
        ['Coupons','admin/coupons','coupons','admin.pricing.edit','coupons'],['Product Reviews','admin/product-reviews','product-reviews','admin.products.view','reviews'],
    ]],
    ['REPORTS & SETTINGS',[
        ['Sales Reports','admin/reports','reports','admin.analytics.view','reports'],['Store Settings','admin/settings','settings','admin.settings.view','settings'],
        ['Staff Access','admin/staff','staff','admin.access.view','staff'],
    ]],
];
$navPaths=[
    'overview'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'analytics'=>'<path d="M3 3v18h18M7 14l4-4 4 3 6-7"/><path d="M17 6h4v4"/>',
    'products'=>'<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5M12 8v13"/>',
    'categories'=>'<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
    'category-types'=>'<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
    'brands'=>'<path d="M20.6 13.4 11 3.8A2.7 2.7 0 0 0 9.1 3H4a1 1 0 0 0-1 1v5.1c0 .7.3 1.4.8 1.9l9.6 9.6a2 2 0 0 0 2.8 0l4.4-4.4a2 2 0 0 0 0-2.8Z"/><circle cx="7.5" cy="7.5" r="1"/>',
    'inventory'=>'<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01M16 7h2M16 17h2"/>',
    'orders'=>'<path d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M15 3v5h5M9 13h6M9 17h6"/>',
    'delivery'=>'<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    'customers'=>'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 5a3.5 3.5 0 0 1 0 6.8M18 14a5 5 0 0 1 3.5 4.8"/>',
    'enquiries'=>'<path d="M4 5h16v12H9l-5 4V5Z"/><path d="M8 9h8M8 13h5"/>',
    'coupons'=>'<path d="M4 5h16v5a2 2 0 0 0 0 4v5H4v-5a2 2 0 0 0 0-4V5Z"/><path d="M12 7v2m0 2v2m0 2v2"/>',
    'reviews'=>'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    'reports'=>'<path d="M4 20V10m8 10V4m8 16v-7"/><path d="M2 20h20"/>',
    'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
    'staff'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
];
$navIcon=static fn(string $key)=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($navPaths[$key]??'').'</svg>';
?>
<!doctype html><html lang="en" class="layout-menu-fixed layout-compact" data-theme="light" data-layout="vertical" data-layout-mode="fluid" data-layout-position="fixed" data-topbar-color="light" data-menu-color="light" data-sidenav-size="default"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f5f5f9"><meta name="color-scheme" content="light"><title><?= $e($title) ?> · Maa Sarada Admin</title><link rel="icon" href="<?= $u('assets/storefront/assets/brand/logo_mark.webp') ?>"><link rel="stylesheet" href="<?= $u('assets/admin/admin.css') ?>"><link href="<?= $u('assets/admin/hyper/css/app-saas.min.css') ?>" rel="stylesheet" id="app-style"><link href="<?= $u('assets/admin/hyper/css/icons.min.css') ?>" rel="stylesheet"><link rel="stylesheet" href="<?= $u('assets/admin/vendor/dataTables-2.3.4.min.css') ?>"><link rel="stylesheet" href="<?= $u('assets/admin/admin-theme.css') ?>"><?php if($module==='analytics'): ?><link rel="stylesheet" href="<?= $u('assets/admin/hyper/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.css') ?>"><?php endif; ?><script defer src="<?= $u('assets/admin/hyper/js/vendor.min.js') ?>"></script><?php if($module==='analytics'): ?><script defer src="<?= $u('assets/admin/hyper/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.min.js') ?>"></script><script defer src="<?= $u('assets/admin/hyper/vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-in-mill-en.js') ?>"></script><?php endif; ?><script defer src="<?= $u('assets/admin/hyper/vendor/apexcharts/apexcharts.min.js') ?>"></script><script defer src="<?= $u('assets/admin/vendor/dataTables-2.3.4.min.js') ?>"></script><script defer src="<?= $u('assets/admin/admin.js') ?>"></script></head><body data-admin-base="<?= $u('admin/') ?>">
<a class="skip-link visually-hidden-focusable" href="#admin-main">Skip to content</a>
<div class="wrapper layout-wrapper layout-container">
<div class="navbar-custom layout-navbar"><div class="topbar container-fluid"><div class="d-flex align-items-center gap-1"><button type="button" class="drawer-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="leftside-menu"><i class="mdi mdi-menu" aria-hidden="true"></i></button><span class="navbar-section-label">Maa Sarada <span>/</span> Admin</span></div><ul class="topbar-menu d-flex align-items-center gap-3"><li class="d-none d-sm-inline-block"><a class="nav-link" href="<?= $u() ?>" target="_blank" rel="noopener" title="View storefront"><i class="ri-external-link-line font-22"></i></a></li><li class="dropdown"><a class="nav-link dropdown-toggle arrow-none nav-user px-2" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false"><span class="account-user-avatar"><span class="avatar-sm d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle"><?= $e(mb_strtoupper(mb_substr($actor['name'],0,1))) ?></span></span><span class="d-lg-flex flex-column gap-1 d-none"><h5 class="my-0"><?= $e($actor['name']) ?></h5><h6 class="my-0 fw-normal">Staff workspace</h6></span></a><div class="dropdown-menu dropdown-menu-end profile-dropdown"><div class="dropdown-header noti-title"><h6 class="text-overflow m-0"><?= $e($actor['name']) ?></h6></div><a href="<?= $u() ?>" target="_blank" rel="noopener" class="dropdown-item"><i class="mdi mdi-storefront-outline me-1"></i><span>View storefront</span></a><div class="dropdown-divider"></div><form method="post" action="<?= $u('admin/logout') ?>"><?php $hidden(['csrf'=>$csrf]); ?><button type="submit" class="dropdown-item"><i class="mdi mdi-logout me-1"></i><span>Sign out</span></button></form></div></li></ul></div></div>
<aside class="leftside-menu sidebar layout-menu menu-vertical" id="leftside-menu" aria-label="Admin navigation"><a href="<?= $u('admin') ?>" class="logo logo-light"><span class="logo-lg"><img src="<?= $u('assets/storefront/assets/brand/logo_full.webp') ?>" alt="Maa Sarada"></span><span class="logo-sm"><img src="<?= $u('assets/storefront/assets/brand/logo_mark.webp') ?>" alt="Maa Sarada"></span></a><button type="button" class="drawer-close" aria-label="Close navigation">×</button><div class="h-100" id="leftside-menu-container" data-simplebar><ul class="side-nav"><?php foreach($navigation as [$group,$items]): ?><li class="side-nav-title"><?= $e($group) ?></li><?php foreach($items as [$label,$path,$active,$permission,$icon]): if($permission!==''&&!$can($permission))continue; ?><li class="side-nav-item"><a href="<?= $u($path) ?>" class="side-nav-link<?= $module===$active?' active':'' ?>" <?= $module===$active?'aria-current="page"':'' ?>><span class="admin-nav-icon"><?= $navIcon($icon) ?></span><span><?= $e($label) ?></span></a></li><?php endforeach; ?><?php endforeach; ?></ul><div class="clearfix"></div></div></aside><button type="button" class="sidebar-backdrop" aria-label="Close navigation" tabindex="-1" hidden></button>
<div class="content-page layout-page"><div class="content content-wrapper container-xxl flex-grow-1 container-p-y"><div class="container-fluid">
<div class="row"><div class="col-12"><div class="page-title-box"><div class="page-title-right"><a class="btn btn-outline-primary" href="<?= $u() ?>" target="_blank" rel="noopener">View storefront <i class="mdi mdi-open-in-new"></i></a></div><h4 class="page-title"><?= $e($title) ?></h4><p class="text-muted mb-0"><?= $e($description) ?></p></div></div></div>
<main id="admin-main" tabindex="-1">
<?php if($notice): ?><div class="notice success" role="status"><?= $e($notice) ?></div><?php endif; ?>
<?php if($errors): ?><div class="notice error error-summary" role="alert" tabindex="-1"><strong>We couldn’t save these changes.</strong><ul><?php foreach(array_unique($errors) as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul><?php if(http_response_code()===409): ?><p>Reload the current record before submitting again. Your entered values are shown below.</p><?php endif; ?></div><?php endif; ?>
