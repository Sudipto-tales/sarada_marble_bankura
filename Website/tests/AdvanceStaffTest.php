<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_COD_ENABLED'=>'true','CHECKOUT_RAZORPAY_ENABLED'=>'true','CHECKOUT_SHIPPING_MINOR'=>'0','CHECKOUT_TAX_BPS'=>'0','CHECKOUT_ADVANCE_BPS'=>'2000']
    as $name=>$value) { $_ENV[$name]=$value; putenv($name.'='.$value); }

$root = CommerceAccess::provision('adv-root@example.test', 'Disposable-Root-Password', 'superadmin');
$admin = CommerceAccess::provision('adv-admin@example.test', 'Disposable-Admin-Password'); // default role: admin
$buyer = CommerceTest::user('adv-buyer@example.test');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo);
$carts = new CartService($pdo); $orders = new OrderService($pdo); $addresses = new AddressService($pdo);
$category = $products->saveCategory($admin, ['slug'=>'adv-stones','name'=>'Advance Stones'], 'advtest:cat');
$input = ['slug'=>'adv-marble','name'=>'Advance Marble','category_id'=>$category,'status'=>'active',
    'variants'=>[['sku'=>'ADV-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>10000]]];
$product = $products->save($admin, $input, 0, 'advtest:product'); $v = $product['variant_ids'][0];
$inventory->adjust($admin, $v, 100000, 'Initial stock', 'advtest:stock');
$addr = $addresses->save($buyer, ['recipient'=>'Buyer','phone'=>'9999900000','line1'=>'Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101']);
$order = static function (string $mode, string $key) use ($carts, $orders, $buyer, $v, $addr): array {
    $cart = $carts->change('user:'.$buyer, $v, 1000, 0);
    return $orders->place($buyer, $cart['id'], $cart['revision'], $addr['id'], $mode, $key);
};

// Global 20% advance applies to an online order; the balance is the remainder.
$o = $order('razorpay', 'advtest:r1');
$check($o['total_minor']===10000 && $o['amount_due_minor']===2000, 'Online order collects the 20% global advance now.');
// COD never collects an advance online — the full amount is due on delivery.
$check($order('cod', 'advtest:c1')['amount_due_minor']===10000, 'COD is unaffected by advance; full amount due on delivery.');
// Category override beats the global default.
$pdo->exec("UPDATE categories SET advance_bps=5000 WHERE id=$category");
$check($order('razorpay', 'advtest:r2')['amount_due_minor']===5000, 'Category advance rate overrides the store default.');
// Product override of 0 forces full upfront payment, beating category and global.
$pdo->exec("UPDATE products SET advance_bps=0 WHERE id=".(int)$product['id']);
$check($order('razorpay', 'advtest:r3')['amount_due_minor']===10000, 'A product advance of 0 forces full payment upfront.');
// The product form column round-trips through save() (variant id kept so the SKU is updated, not re-inserted).
$edit = array_merge($input, ['id'=>$product['id'], 'advance_bps'=>3000]); $edit['variants'][0]['id'] = $v;
$products->save($admin, $edit, $product['revision'], 'advtest:product:edit');
$check((int)$pdo->query('SELECT advance_bps FROM products WHERE id='.(int)$product['id'])->fetchColumn()===3000, 'Saved product advance rate persists.');

// Verification gate: placed must pass through awaiting_verification before confirmed.
$placed = $order('razorpay', 'advtest:verify');
CommerceTest::rejects(fn()=>$orders->transition($admin, $placed['id'], 'confirmed', 1, 'advtest:skip'), 409, 'Order cannot skip verification.');
$orders->transition($admin, $placed['id'], 'awaiting_verification', 1, 'advtest:toverify');
$confirmed = $orders->transition($admin, $placed['id'], 'confirmed', 2, 'advtest:confirm');
$check($confirmed['status']==='confirmed', 'Order confirms after verification.');

// Advance balance recording: only an advance_paid order has a balance, and it settles to paid.
$pdo->exec("UPDATE orders SET payment_status='advance_paid' WHERE id=".(int)$placed['id']);
$withBalance = (int)$pdo->query('SELECT revision FROM orders WHERE id='.(int)$placed['id'])->fetchColumn();
$settled = $orders->recordBalance($admin, $placed['id'], $withBalance, 'advtest:balance');
$check($settled['payment_status']==='paid', 'Operator records advance balance as collected.');
CommerceTest::rejects(fn()=>$orders->recordBalance($admin, $placed['id'], $settled['revision'], 'advtest:balance:again'), 409, 'A fully paid order has no balance to record.');

// Staff access: only a superadmin manages staff.
$staff = new StaffAccessService($pdo);
CommerceTest::rejects(fn()=>$staff->roster($admin), 403, 'Admin cannot view staff access.');
$check(count($staff->roster($root))>=2, 'Superadmin sees the staff roster.');
CommerceTest::rejects(fn()=>$staff->createStaff($admin, ['email'=>'x@example.test','name'=>'X','role'=>'viewer','password'=>'Disposable-Password-1'], 'advtest:bad'), 403, 'Admin cannot create staff.');
$ops = $staff->createStaff($root, ['email'=>'adv-ops@example.test','name'=>'Ops','role'=>'operations','password'=>'Disposable-Password-2'], 'advtest:ops');
$check(CommerceAccess::can('admin.orders.edit', $ops) && !CommerceAccess::isSuperadmin($ops), 'Superadmin creates an operations account.');
$second = $staff->createStaff($root, ['email'=>'adv-super2@example.test','name'=>'Super Two','role'=>'superadmin','password'=>'Disposable-Password-3'], 'advtest:super2');
$check(CommerceAccess::isSuperadmin($second), 'Superadmin can create another superadmin.');
$staff->setRole($root, $second, 'admin', 'advtest:demote2');
$check(!CommerceAccess::isSuperadmin($second), 'A superadmin can be demoted while another remains.');
CommerceTest::rejects(fn()=>$staff->setRole($root, $root, 'admin', 'advtest:demote-last'), 409, 'The last superadmin cannot be demoted.');
CommerceTest::rejects(fn()=>$staff->setRole($ops, $second, 'viewer', 'advtest:ops-denied'), 403, 'A non-superadmin cannot manage staff access.');

CommerceTest::finish('AdvanceStaffTest');
