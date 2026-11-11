<?php
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];$cart=new CartService($pdo);$addresses=new AddressService($pdo);$products=new ProductService($pdo);$inventory=new InventoryService($pdo);
$admin=CommerceAccess::provision('cart-admin@example.test','Disposable-Cart-Password');$a=CommerceTest::user('cart-a@example.test');$b=CommerceTest::user('cart-b@example.test');
$category=$products->saveCategory($admin,['slug'=>'cart-stones','name'=>'Cart Stones'],'category:cart:stones');
$p=$products->save($admin,['slug'=>'cart-marble','name'=>'Cart Marble','category_id'=>$category,'status'=>'active','variants'=>[
    ['sku'=>'CART-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>500,'unit_price_minor'=>101],['sku'=>'CART-BOX','sell_unit'=>'box','qty_increment_milli'=>1000,'unit_price_minor'=>5000,'coverage_sqft_milli'=>16000]]],0,'product:cart:create');[$v1,$v2]=$p['variant_ids'];
$inventory->adjust($admin,$v1,20000,'Cart stock','stock:cart:first');$inventory->adjust($admin,$v2,20000,'Cart stock','stock:cart:second');
$ua='user:'.$a;$ub='user:'.$b;$guest='guest:'.hash('sha256','disposable guest proof');
$check($cart->get($ua)['revision']===0&&(int)$pdo->query('SELECT COUNT(*) FROM carts')->fetchColumn()===0,'Read does not create an empty persistent cart.');
$first=$cart->change($ua,$v1,1500,0);$check($first['subtotal_minor']===152&&$first['revision']===2,'Cart total uses canonical half-up integer rounding.');
$check((int)$pdo->query('SELECT SUM(reserved_milli) FROM inventory')->fetchColumn()===0,'Adding a cart line reserves no stock.');
CommerceTest::rejects(fn()=>$cart->get($ub,$first['id']),404,'Guessed cart ID cannot disclose another user cart.');
CommerceTest::rejects(fn()=>$cart->change($ub,$v1,1000,2,$first['id']),404,'Another user cannot mutate a cart.');
CommerceTest::rejects(fn()=>$cart->change($ua,$v1,1000,1,$first['id']),409,'Stale revision cannot overwrite a line.');
CommerceTest::rejects(fn()=>$cart->change($ua,$v1,-1,2,$first['id']),422,'Negative quantity is rejected instead of interpreted as removal.');
CommerceTest::rejects(fn()=>$cart->change($ua,$v2,1500,2,$first['id']),422,'Fractional whole-unit box is rejected.');
$pdo->prepare('UPDATE product_variants SET unit_price_minor=201 WHERE id=?')->execute([$v1]);
$check($cart->get($ua)['subtotal_minor']===302,'Saved cart does not freeze or accept client prices.');
$g=$cart->change($guest,$v1,1000,0);$g=$cart->change($guest,$v2,1000,$g['revision'],$g['id']);
$pdo->prepare('UPDATE product_variants SET enabled=0 WHERE id=?')->execute([$v2]);
$merged=$cart->merge($guest,$a);$combined=$cart->get($ua);
$check(count($merged['excluded'])===1&&$combined['items'][0]['qty_milli']===2500,'Merge combines matching lines and visibly reports unavailable variants.');
$replay=$cart->merge($guest,$a);$check($replay['replayed']&&$cart->get($ua)['items'][0]['qty_milli']===2500,'Merge replay does not double quantities.');
CommerceTest::rejects(fn()=>$cart->merge($guest,$b),404,'Revoked guest proof cannot be merged into another account.');
CommerceTest::rejects(fn()=>$cart->get($guest,$g['id']),404,'Merged guest cart is no longer accessible as an active cart.');
CommerceTest::rejects(fn()=>$cart->change($guest,$v1,1000,0),409,'Closed guest proof cannot create a replacement cart.');
$hold=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve($ua,[['variant_id'=>$v1,'qty_milli'=>2500]],'reserve:cart:hold',$combined['id'],$combined['revision']));
CommerceTest::rejects(fn()=>$cart->change($ua,$v1,3000,$combined['revision'],$combined['id']),409,'Cart with a live reservation cannot change.');
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve($ua,[['variant_id'=>$v1,'qty_milli'=>2500]],'reserve:cart:other',$combined['id'],$combined['revision'])),409,'One active reservation per cart is enforced by the locked-cart service.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->release((int)$hold['id'],$ua));
$removed=$cart->change($ua,$v1,null,$combined['revision'],$combined['id'],true);$check($removed['items']===[],'Explicit remove action removes the owned line.');
$input=['label'=>'Home','recipient'=>'Customer A','phone'=>'+91 99999 88888','line1'=>'Station Road','line2'=>'','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101','country_code'=>'IN','user_id'=>$b];
$address=$addresses->save($a,$input);$check((int)$address['user_id']===$a&&(int)$address['is_default']===1&&$address['phone']==='+919999988888','Server actor owns address; first address defaults and phone is normalized.');
CommerceTest::rejects(fn()=>$addresses->get($b,(int)$address['id']),404,'Another account cannot read private delivery details.');
$update=$input+['id'=>$address['id']];CommerceTest::rejects(fn()=>$addresses->save($b,$update,1),404,'Another account cannot edit private delivery details.');
CommerceTest::rejects(fn()=>$addresses->save($a,$update,0),409,'Stale address revision rejected.');
$bad=$input;$bad['postal_code']='bad';CommerceTest::rejects(fn()=>$addresses->save($a,$bad),422,'Invalid India postal code rejected.');
$second=$addresses->save($a,array_replace($input,['label'=>'Work','is_default'=>1]));
$check((int)$pdo->query("SELECT COUNT(*) FROM addresses WHERE user_id=$a AND enabled=1 AND is_default=1")->fetchColumn()===1,'Setting a new default clears the previous default.');
CommerceTest::fails(fn()=>$pdo->exec("UPDATE addresses SET is_default=1 WHERE user_id=$a"),'Database generated uniqueness independently rejects duplicate defaults.');
$addresses->archive($a,(int)$second['id'],(int)$second['revision']);
$check(count($addresses->listing($a))===1&&(int)$addresses->listing($a)[0]['is_default']===1,'Archiving default address selects a remaining owned address.');
$check($addresses->listing($b)===[],'Account switch exposes no prior account address state.');
$pdo->prepare('UPDATE users_tbl SET status=0 WHERE id=?')->execute([$b]);CommerceTest::rejects(fn()=>$addresses->listing($b),403,'Disabled account loses address access immediately.');
CommerceTest::fails(fn()=>$pdo->exec("UPDATE inventory_reservations SET cart_id=2147483647 WHERE id=".$hold['id']),'Forward cart link rejects nonexistent carts.');
$fixture=CommerceTest::$temp.'/cart-race.php';
file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'.<<<'CHILD'
$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))) {if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
try {
 if($in['mode']==='cart')$result=(new CartService($pdo))->change($in['principal'],$in['variant'],$in['qty'],$in['revision'],$in['id']);
 else $result=(new AddressService($pdo))->save($in['user'],$in['input']);
 echo json_encode(['status'=>200]);
} catch(RequestRejected $e) {echo json_encode(['status'=>$e->status]);}
CHILD);
$race=CommerceTest::race([
 ['mode'=>'cart','principal'=>$ua,'variant'=>$v1,'qty'=>1000,'revision'=>$removed['revision'],'id'=>$removed['id']],
 ['mode'=>'cart','principal'=>$ua,'variant'=>$v1,'qty'=>2000,'revision'=>$removed['revision'],'id'=>$removed['id']]],$fixture);
$status=array_column($race,'status');sort($status);$check($status===[200,409],'Parallel cart writes require revision retry rather than lost updates.');
$check((int)$pdo->query('SELECT COUNT(*) FROM cart_items WHERE cart_id='.$removed['id'])->fetchColumn()===1,'Parallel writes preserve unique line identity.');
$race=CommerceTest::race([
 ['mode'=>'address','user'=>$a,'input'=>array_replace($input,['label'=>'Race One','is_default'=>1])],
 ['mode'=>'address','user'=>$a,'input'=>array_replace($input,['label'=>'Race Two','is_default'=>1])]],$fixture);
$check(array_column($race,'status')===[200,200]&&(int)$pdo->query("SELECT COUNT(*) FROM addresses WHERE user_id=$a AND enabled=1 AND is_default=1")->fetchColumn()===1,'Concurrent default saves commit with exactly one default.');
CommerceTest::finish('Owned carts and delivery addresses');
