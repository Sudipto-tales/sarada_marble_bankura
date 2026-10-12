<?php
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];
$actor=CommerceAccess::provision('image-maintenance@example.test','Disposable-Image-Maintenance-Password');
$catalog=new ProductService($pdo);$category=$catalog->saveCategory($actor,['name'=>'Maintenance','slug'=>'maintenance'],'maintenance:category');
$product=$catalog->save($actor,['name'=>'Maintenance material','slug'=>'maintenance-material','category_id'=>$category,'status'=>'draft','variants'=>[]],0,'maintenance:product');
$root=CommerceTest::$temp.'/sandbox';mkdir($root.'/app/Services',0700,true);mkdir($root.'/assets/products',0700,true);
// Execute the actual implementation with an isolated __DIR__ public root; no live files are scanned/deleted.
copy(dirname(__DIR__).'/app/Services/ProductImageService.php',$root.'/app/Services/ProductImageService.php');
require $root.'/app/Services/ProductImageService.php';
$old=str_repeat('a',32).'.png';$referenced=str_repeat('b',32).'.png';$young=str_repeat('c',32).'.png';
foreach([$old,$referenced,$young,'operator-note.txt'] as $file)file_put_contents($root.'/assets/products/'.$file,'test fixture');
touch($root.'/assets/products/'.$old,time()-172800);touch($root.'/assets/products/'.$referenced,time()-172800);touch($root.'/assets/products/operator-note.txt',time()-172800);
$catalog->addImage($actor,$product['id'],['path'=>'assets/products/'.$referenced,'mime'=>'image/png','width'=>1,'height'=>1,'alt_text'=>'Referenced fixture'],'maintenance:image');
$staging=PrivateStorage::directory('image-staging');file_put_contents($staging.'/raster-oldfixture','old');touch($staging.'/raster-oldfixture',time()-172800);file_put_contents($staging.'/raster-youngfixture','young');
$service=new ProductImageService($pdo);$dry=$service->reconcile();
$check($dry['stagingOrphans']===1&&$dry['stagingRemoved']===0&&file_exists($staging.'/raster-oldfixture'),'Dry-run leaves private staging files intact.');
$check($dry['orphans']===1&&$dry['removed']===0&&file_exists($root.'/assets/products/'.$old),'Dry-run reports the old unreferenced generated file without deleting.');
$deleted=$service->reconcile(true);
$check($deleted['stagingRemoved']===1&&!file_exists($staging.'/raster-oldfixture'),'Explicit cleanup removes a stale staged upload.');
$check(file_exists($staging.'/raster-youngfixture'),'An in-flight staged upload is preserved.');
$check($deleted['removed']===1&&!file_exists($root.'/assets/products/'.$old),'Explicit maintenance removes an old orphan in the isolated public root.');
$check(file_exists($root.'/assets/products/'.$referenced),'Referenced image is preserved.');
$check(file_exists($root.'/assets/products/'.$young),'Young generated file is preserved across a possible in-flight upload.');
$check(file_exists($root.'/assets/products/operator-note.txt'),'Non-generated files are preserved.');
$check($service->reconcile(true)['removed']===0,'A repeated maintenance pass is safe.');
CommerceTest::finish('Bounded image orphan maintenance');
