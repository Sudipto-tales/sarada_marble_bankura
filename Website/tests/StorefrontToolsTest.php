<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
$check = [CommerceTest::class, 'check'];
$base = ['surfaces'=>[['name'=>'Floor','length'=>'14','width'=>'12','unit'=>'ft','cutout'=>'0']], 'waste_bps'=>1000];
$sqft = ['qty_increment_milli'=>500,'sell_unit'=>'sqft','unit_price_minor'=>101,'coverage_sqft_milli'=>null];
$box = ['qty_increment_milli'=>1000,'sell_unit'=>'box','unit_price_minor'=>5000,'coverage_sqft_milli'=>16000];
$r = AreaCalculator::estimate($base, $sqft);
$check($r['net_area_milli'] === 168000 && $r['required_area_milli'] === 184800 && $r['qty_milli'] === 185000 && $r['material_minor'] === 18685, 'Coverage, wastage, selling increment and money use exact integers.');
$r = AreaCalculator::estimate($base, $box);
$check($r['qty_milli'] === 12000 && $r['material_minor'] === 60000, 'Box estimate rounds up to twelve complete 16-sqft boxes.');
$one = ['surfaces'=>[['name'=>'Wall','length'=>'1','width'=>'1','unit'=>'m','cutout'=>'0']], 'waste_bps'=>0];
$check(AreaCalculator::estimate($one, null)['net_area_milli'] === 10764, 'Square metre conversion conservatively rounds to milli-sqft.');
$inch = ['surfaces'=>[['name'=>'Wall','length'=>'12','width'=>'12','unit'=>'in','cutout'=>'0']], 'waste_bps'=>0];
$check(AreaCalculator::estimate($inch, null)['net_area_milli'] === 1000, 'Twelve inches squared is one square foot.');
$cutout = $base; $cutout['surfaces'][0]['cutout'] = '8';
$check(AreaCalculator::estimate($cutout, null)['net_area_milli'] === 160000, 'Cut-outs subtract square feet before wastage.');
$multi = $base; $multi['surfaces'][] = ['name'=>'Wall','length'=>'10','width'=>'8','unit'=>'ft','cutout'=>'2'];
$check(AreaCalculator::estimate($multi, null)['net_area_milli'] === 246000, 'Named surfaces independently calculate and combine coverage.');
$r = AreaCalculator::estimate($base + ['polish_rate'=>'28','install_rate'=>'65'], $sqft);
$check($r['polish_minor'] === 517440 && $r['install_minor'] === 1092000 && $r['estimated_total_minor'] === 1628125, 'Optional labour estimates remain exact and separate from checkout tax.');
$zero = ['surfaces'=>[['name'=>'Small','length'=>'0.001','width'=>'0.001','unit'=>'ft','cutout'=>'0']], 'waste_bps'=>0];
$check(AreaCalculator::estimate($zero, null)['net_area_milli'] === 1, 'Positive tiny coverage never rounds to zero.');
foreach (['length'=>'-1','width'=>'0','unit'=>'bad','cutout'=>'168','name'=>[]] as $field=>$value) {
    $bad = $base; $bad['surfaces'][0][$field] = $value;
    CommerceTest::rejects(fn()=>AreaCalculator::estimate($bad, $sqft), 422, 'Invalid surface field is rejected: '.$field);
}
foreach ([[], array_fill(0,21,$base['surfaces'][0])] as $surfaces)
    CommerceTest::rejects(fn()=>AreaCalculator::estimate(['surfaces'=>$surfaces], null), 422, 'Surface count is bounded.');
CommerceTest::rejects(fn()=>AreaCalculator::estimate($base + ['polish_rate'=>'1.001'], null), 422, 'Money rejects excess decimals.');
CommerceTest::rejects(fn()=>AreaCalculator::estimate($base, array_replace($box,['coverage_sqft_milli'=>null])), 422, 'Missing whole-unit coverage cannot invent a purchase quantity.');
CommerceTest::rejects(fn()=>AreaCalculator::estimate($base, array_replace($sqft,['sell_unit'=>'piece'])), 422, 'Piece variants require a defined coverage policy.');
$admin = CommerceAccess::provision('tools-admin@example.test','Disposable-Tools-Password');
$a = CommerceTest::user('tools-a@example.test'); $b = CommerceTest::user('tools-b@example.test');
$catalog = new ProductService($pdo);
$category = $catalog->saveCategory($admin,['slug'=>'tools-stones','name'=>'Tools Stones'],'category:tools:new');
$product = $catalog->save($admin,['slug'=>'tools-stone','name'=>'Tools Stone','category_id'=>$category,'status'=>'active','variants'=>[
    ['sku'=>'TOOLS-SQFT','sell_unit'=>'sqft','finish'=>'Polished','qty_increment_milli'=>500,'unit_price_minor'=>101]]],0,'product:tools:new');
$id = $product['id'];
$wish = new WishlistService($pdo);
$wish->change($a,$id,false); $wish->change($a,$id,false);
$check($wish->ids($a) === [$id] && $wish->ids($b) === [], 'Repeated save is idempotent and customer B cannot read A selections.');
$wish->change($b,$id,true);
$check($wish->ids($a) === [$id], 'Customer B remove cannot delete A selections.');
$wish->change(null,$id,false);
$check($wish->ids(null) === [$id] && $wish->ids($b) === [], 'Guest wishlist is session-local and separate from signed-in account.');
$wish->change(null,$id,true);
$check($wish->ids(null) === [], 'Guest removal clears the selected item.');
CommerceTest::rejects(fn()=>$wish->change($a,2147483647,false),404,'Missing products cannot enter wishlist.');
$pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute(['archived',$id]);
$check($catalog->selection($wish->ids($a)) === [], 'Archived wishlist materials are hidden.');
$wish->change($a,$id,true);
$check($wish->ids($a) === [], 'An archived saved product can still be removed.');
$pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute(['active',$id]);
$check(count($catalog->selection([$id,$id])) === 1, 'Bulk selection deduplicates IDs.');
CommerceTest::rejects(fn()=>$catalog->selection(array_fill(0,101,$id)),422,'Bulk selection bounds inputs.');
$check($catalog->listing(['finish'=>'Matte'])['total'] === 0 && $catalog->listing(['finish'=>'Polished'])['total'] === 1, 'Finish filter is applied through prepared variant predicates.');
$normalized = CatalogReadService::filters(['min_price'=>'1.01','max_price'=>'20.50']);
$check($normalized['min_price_minor'] === 101 && $normalized['max_price_minor'] === 2050, 'Readable price filters normalize to exact integer minor units.');
CommerceTest::rejects(fn()=>CatalogReadService::filters(['min_price'=>'1.001']),422,'Price filters reject excess decimals.');
CommerceTest::rejects(fn()=>CatalogReadService::filters(['min_price'=>'20','max_price'=>'1']),422,'Price filters reject inverted ranges.');
$read = new CatalogReadService($pdo);
$check($read->listing(['finish'=>'Matte'])['total'] === 0 && $read->listing(['finish'=>'Polished'])['total'] === 1,
    'Cached catalog keys preserve finish-filter isolation.');
$check($read->listing(['in_stock'=>1])['total'] === 0 && $read->listing()['total'] === 1,
    'Cached catalog keys preserve stock-filter isolation.');
$check($catalog->listing(['in_stock'=>1])['total'] === 0, 'Stock filter hides inventory-less variants.');
(new InventoryService($pdo))->adjust($admin,$product['variant_ids'][0],1000,'Tools fixture','stock:tools:new');
$check($catalog->listing(['in_stock'=>1])['total'] === 1, 'Stock filter uses actual available inventory.');
CommerceTest::rejects(fn()=>CatalogReadService::filters(['in_stock'=>2]),422,'Invalid boolean filters are rejected.');
$fixture = CommerceTest::$temp.'/wishlist-race.php';
file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'.<<<'CHILD'
$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
(new WishlistService($pdo))->change($in['user'],$in['product'],false);echo json_encode(['status'=>200]);
CHILD);
$results = CommerceTest::race([['user'=>$a,'product'=>$id],['user'=>$a,'product'=>$id]],$fixture);
$check(array_column($results,'status') === [200,200] && $wish->ids($a) === [$id], 'Concurrent repeated saves serialize on owner and retain one association.');
$before = [(int)$pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()];
// Disposable schema only: simulate an installation before the new wishlist migration.
$pdo->exec('DROP TABLE wishlists');
$pdo->exec("DELETE FROM migrations WHERE migration='0011_customer_wishlist'");
$check((new MigrationRunner($pdo,dirname(__DIR__).'/database/migrations',static function($line){}))->run() === 1,
    'Existing installation applies only the new wishlist migration.');
$check($before === [(int)$pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()],
    'Wishlist upgrade preserves existing commerce identities and products.');
$check((new MigrationRunner($pdo,dirname(__DIR__).'/database/migrations',static function($line){}))->run() === 0, 'New wishlist schema verifies and migrations rerun without changes.');
echo 'Storefront tools: '.CommerceTest::$checks.' checks passed ('.CommerceTest::$version.").\n";
