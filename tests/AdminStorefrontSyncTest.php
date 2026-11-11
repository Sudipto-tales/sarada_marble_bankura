<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
$check = [CommerceTest::class, 'check'];
$admin = CommerceAccess::provision('sync-admin@example.test', 'Disposable-Sync-Test-Password');
$service = new ProductService($pdo);
$read = new CatalogReadService($pdo);
$category = ['slug'=>'sync-stone', 'name'=>'Sync Stone', 'enabled'=>1];
$category['id'] = $service->saveCategory($admin, $category, 'sync:category');
$brand = ['slug'=>'sync-brand', 'name'=>'Sync Brand', 'enabled'=>1];
$brand['id'] = $service->saveBrand($admin, $brand, 'sync:brand');
$input = ['name'=>'Original Material', 'slug'=>'original-material', 'category_id'=>$category['id'],
    'brand_id'=>$brand['id'], 'status'=>'active', 'variants'=>[
        ['sku'=>'SYNC-A', 'sell_unit'=>'sqft', 'unit_price_minor'=>12500, 'qty_increment_milli'=>1000, 'enabled'=>1],
        ['sku'=>'SYNC-B', 'sell_unit'=>'sqft', 'unit_price_minor'=>15000, 'qty_increment_milli'=>1000, 'enabled'=>0]]];
$saved = $service->save($admin, $input, 0, 'sync:product');
$input['id'] = $saved['id'];
foreach ($input['variants'] as $index=>&$variant) $variant['id'] = $saved['variant_ids'][$index];
unset($variant);
$check($read->visualizerMaterials() === [], 'A published material needs a photo for the visualizer.');
$photo = static fn(int $position): array => ['path'=>'assets/products/'.bin2hex(random_bytes(16)).'.png',
    'mime'=>'image/png', 'width'=>256, 'height'=>256, 'alt_text'=>'Original surface', 'position'=>$position];
$disabled = $photo(0); $disabled['variant_id'] = $saved['variant_ids'][1];
$service->addImage($admin, $saved['id'], $disabled, 'sync:disabled-photo');
$check($read->visualizerMaterials() === [], 'A disabled variant photo cannot represent a published material.');
$primary = $photo(1);
$service->addImage($admin, $saved['id'], $primary, 'sync:primary-photo');
$materials = $read->visualizerMaterials();
$check(count($materials) === 1 && $materials[0]['image_path'] === $primary['path'], 'Picker uses the first eligible original photo.');
$check(array_keys($materials[0]) === ['id','name','slug','image_path'], 'Public texture projection excludes staff and identity fields.');
$replacement = $photo(0);
$service->addImage($admin, $saved['id'], $replacement, 'sync:replacement-photo');
$check($read->visualizerMaterials()[0]['image_path'] === $replacement['path'], 'Gallery order changes immediately reach the picker.');
$category['enabled'] = 0; $service->saveCategory($admin, $category, 'sync:hide-category');
$check($read->visualizerMaterials() === [] && $read->listing()['total'] === 0, 'Disabled category hides public shop and visualizer material.');
$category['enabled'] = 1; $service->saveCategory($admin, $category, 'sync:show-category');
$brand['enabled'] = 0; $service->saveBrand($admin, $brand, 'sync:hide-brand');
$check($read->visualizerMaterials() === [] && $read->listing()['total'] === 0, 'Disabled brand hides public shop and visualizer material.');
$brand['enabled'] = 1; $service->saveBrand($admin, $brand, 'sync:show-brand');
$input['status'] = 'draft'; $changed = $service->save($admin, $input, 1, 'sync:draft');
$check($read->visualizerMaterials() === [], 'Draft products are excluded from the visualizer.');
$input['status'] = 'active'; $input['name'] = 'Renamed Material';
$changed = $service->save($admin, $input, $changed['revision'], 'sync:publish');
$check($read->visualizerMaterials()[0]['name'] === 'Renamed Material', 'Republished product uses current admin name.');
for ($index=1; $index<=51; $index++) {
    $new = $input; unset($new['id']);
    $new['name'] = 'Other Material '.$index; $new['slug'] = 'other-material-'.$index;
    $new['variants'] = [['sku'=>'SYNC-OTHER-'.$index, 'sell_unit'=>'sqft', 'unit_price_minor'=>12500, 'qty_increment_milli'=>1000]];
    $result = $service->save($admin, $new, 0, 'sync:other:'.$index);
    $service->addImage($admin, $result['id'], $photo(0), 'sync:other-photo:'.$index);
}
$check(count($read->visualizerMaterials()) === 50, 'Material picker bounds public work to fifty store materials.');
$check(!in_array($saved['id'], array_map('intval', array_column($read->visualizerMaterials(), 'id')), true), 'Older material lies beyond default picker page.');
$selected = $read->visualizerMaterials($saved['id']);
$check(count($selected) === 50 && (int)$selected[0]['id'] === $saved['id'], 'Product deep link includes and selects an older material beyond the bound.');
$check($read->visualizerMaterials(2147483647) !== [], 'An absent selection never disables normal material discovery.');
CommerceTest::rejects(fn()=>$read->visualizerMaterials(0), 422, 'Selected product ID is validated.');
$service->archive($admin, $saved['id'], $changed['revision'], 'sync:archive');
$check(!in_array($saved['id'], array_map('intval', array_column($read->visualizerMaterials($saved['id']), 'id')), true), 'Archived deep-linked material cannot be exposed by selection.');
CommerceTest::finish('Admin/storefront sync');
