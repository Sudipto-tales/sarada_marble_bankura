<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
$check = [CommerceTest::class, 'check'];
$admin = CommerceAccess::provision('staff@example.test', 'Disposable-Guard-Staff-Password');
$a = CommerceTest::user('a@example.test'); $b = CommerceTest::user('b@example.test');
$pdo->exec('CREATE TABLE iam_owned_probe (id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL, kind VARCHAR(20) NOT NULL, value INT NOT NULL)');
foreach (['address','cart','order'] as $n => $kind) {
    $pdo->prepare('INSERT INTO iam_owned_probe (id, user_id, kind, value) VALUES (?, ?, ?, 0)')->execute([$n+1,$b,$kind]);
    $query = $pdo->prepare('SELECT * FROM iam_owned_probe WHERE id = ? AND user_id = ? AND kind = ?');
    $query->execute([$n+1, $a, $kind]);
    CommerceTest::rejects(fn() => CommerceAccess::requireOwned($query->fetch(), $a), 404, 'Another owner record is scoped out: ' . $kind);
    $query->execute([$n+1, $b, $kind]);
    $check(CommerceAccess::requireOwned($query->fetch(), $b)['kind'] === $kind, 'Owned result passes: ' . $kind);
    $query = $pdo->prepare('UPDATE iam_owned_probe SET value = 1 WHERE id = ? AND user_id = ?');
    $query->execute([$n+1, $a]);
    $check($query->rowCount() === 0, 'Cross-owner write has no effect: ' . $kind);
}
$_SERVER['REQUEST_METHOD'] = 'POST'; RequestSecurity::session();
$_POST = ['csrf' => RequestSecurity::csrfToken()];
$_SESSION['user_id'] = $a;
foreach (['html','json'] as $surface) {
    RequestSecurity::requireMutation();
    CommerceTest::rejects(fn() => CommerceAccess::requirePermission('admin.products.edit'), 403, 'Identical controller grants deny customer on ' . $surface);
}
$_SESSION['user_id'] = $admin;
$check(CommerceAccess::requirePermission('admin.products.edit')['id'] === $admin, 'Staff current identity resolves through same guard.');
CommerceTest::fails(fn() => StaffAudit::record($pdo,$admin,'product.save','product',1,'no-transaction',[]), 'Required audit outside transaction denied.');
$pdo->beginTransaction();
$pdo->exec('UPDATE iam_owned_probe SET value = 2 WHERE id = 1');
$id = StaffAudit::record($pdo,$admin,'product.save','product',1,'product:save:1',['status'=>'active','password'=>'never-store','token'=>'never-store','address'=>['private'=>'never-store']]);
$same = StaffAudit::record($pdo,$admin,'product.save','product',1,'product:save:1',['status'=>'active']);
$pdo->commit();
$check($id === $same && (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === 1, 'Matching replay writes exactly one required audit.');
$row = $pdo->query('SELECT * FROM admin_activity_log')->fetch();
$check($row['changes_json'] === '{"status":"active"}' && !str_contains(json_encode($row),'never-store'), 'Audit drops password/token/address/request secrets.');
$check(preg_match('/\A[a-f0-9]{32}\z/',$row['request_id']) === 1 && str_ends_with($row['created_at'],'Z'), 'Audit includes bounded request correlation and UTC time.');
$pdo->beginTransaction();
CommerceTest::rejects(fn() => StaffAudit::record($pdo,$admin,'product.save','product',1,'product:save:1',['status'=>'archived']),409,'Conflicting audit key input denied.');
$pdo->rollBack();
$pdo->beginTransaction();
CommerceTest::rejects(fn() => StaffAudit::record($pdo,$a,'product.save','product',1,'customer:audit',[]),403,'Customer cannot write privileged audit.');
$pdo->rollBack();
$pdo->beginTransaction();
$pdo->exec('UPDATE iam_owned_probe SET value = 3 WHERE id = 1');
try { $pdo->exec('INSERT INTO table_that_does_not_exist VALUES (1)'); } catch (PDOException $e) { $pdo->rollBack(); }
$check((int)$pdo->query('SELECT value FROM iam_owned_probe WHERE id = 1')->fetchColumn() === 2
    && (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === 1, 'Failed business SQL leaves no effect or success audit.');
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$pdo->exec($mysql ? "CREATE TRIGGER audit_failure BEFORE INSERT ON admin_activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'"
    : "CREATE TRIGGER audit_failure BEFORE INSERT ON admin_activity_log BEGIN SELECT RAISE(ABORT,'injected'); END");
$pdo->beginTransaction();
$pdo->exec('UPDATE iam_owned_probe SET value = 4 WHERE id = 1');
try { StaffAudit::record($pdo,$admin,'product.save','product',1,'audit:failure',['revision'=>2]); }
catch (PDOException $e) { $pdo->rollBack(); }
$pdo->exec('DROP TRIGGER audit_failure');
$check((int)$pdo->query('SELECT value FROM iam_owned_probe WHERE id = 1')->fetchColumn() === 2
    && (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === 1, 'Required audit failure rolls back business mutation.');
$pdo->beginTransaction();
CommerceTest::rejects(fn() => StaffAudit::record($pdo,$admin,'product.save','product',1,'oversized:audit',['reason'=>str_repeat('x',191)]),422,'Oversized audit strings denied.');
CommerceTest::rejects(fn() => StaffAudit::record($pdo,$admin,'product.save','wrong',1,'wrong:audit',[]),422,'Unknown action/target contract denied.');
$pdo->rollBack();
$pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$admin]);
CommerceTest::rejects(fn() => CommerceAccess::requirePermission('admin.products.edit'),403,'Revoked staff loses active-session grant immediately.');
CommerceTest::finish('Commerce guards and audit');
