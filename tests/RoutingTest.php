<?php
// php tests/RoutingTest.php; no application database is opened.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/RouteManager.php';
require dirname(__DIR__) . '/core/ClassLoader.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function fails(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $error) { check(true, $message); return; }
    throw new RuntimeException($message);
}
class RoutingProbe
{
    public function home(): void { echo 'home'; }
    public function show(string $slug): void { echo 'slug:' . $slug; }
    public function order(int $id): void { echo 'id:' . $id; }
    public function legacy(): void { echo 'legacy'; }
    public function pair(string $slug, int $id): void { echo $slug . ':' . $id; }
}
function request(array $routes, string $path, string $method = 'GET', array $query = [], string $script = '/index.php'): array
{
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $query;
    http_response_code(200);
    ob_start();
    try { RouteManager::dispatch($routes); return [http_response_code(), ob_get_contents()]; }
    finally { ob_end_clean(); }
}
$routes = [
    'default' => ['RoutingProbe', 'home'],
    'legacy' => ['RoutingProbe', 'legacy'],
    'product/special' => ['GET' => ['RoutingProbe', 'home']],
    'product/{slug}' => ['GET' => ['RoutingProbe', 'show']],
    'order/{id}' => ['GET' => ['RoutingProbe', 'order']],
    'api/v1/pair/{slug}/{id}' => ['GET' => ['RoutingProbe', 'pair']],
    'api/v1/cart' => ['GET' => ['RoutingProbe', 'home'], 'POST' => ['RoutingProbe', 'legacy']],
];
check(request($routes, '/') === [200, 'home'], 'Root default route.');
check(request($routes, '/legacy', 'POST') === [200, 'legacy'], 'Legacy method policy is preserved.');
check(request($routes, '/product/special') === [200, 'home'], 'Exact path wins before template.');
check(request($routes, '/product/white-marble') === [200, 'slug:white-marble'], 'Valid slug parameter.');
check(request($routes, '/order/12') === [200, 'id:12'], 'ID is converted to integer.');
check(request($routes, '/api/v1/pair/white/12') === [200, 'white:12'], 'Parameters passed in declared order.');
check(request($routes, '/api/v1/cart', 'POST') === [200, 'legacy'], 'Method map selects handler.');
foreach (['0', '-1', '01', '1e2', '9223372036854775808'] as $id) {
    check(request($routes, '/order/' . $id)[0] === 404, 'Invalid or overflowing ID is rejected.');
}
foreach (['White', 'white--marble', '-white', 'white-', str_repeat('a', 121)] as $slug) {
    check(request($routes, '/product/' . $slug)[0] === 404, 'Invalid slug is rejected.');
}
[$status, $body] = request($routes, '/api/v1/cart', 'DELETE');
$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
check($status === 405 && $decoded['error']['code'] === 'method_not_allowed' && strlen($decoded['request_id']) === 32, 'API method error has structured contract.');
[$status, $body] = request($routes, '/api/v1/missing');
check($status === 404 && json_decode($body, true)['error']['code'] === 'not_found', 'API unknown path is JSON.');
check(request($routes, '/missing')[0] === 404, 'HTML unknown path.');
check(request($routes, '/shop/product/white', 'GET', [], '/shop/index.php') === [200, 'slug:white'], 'Subdirectory route stripping.');
check(request($routes, '/shop', 'GET', [], '/shop/index.php') === [200, 'home'], 'Bare subdirectory root.');
check(request($routes, '/shopper/product/white', 'GET', [], '/shop/index.php')[0] === 404, 'Base prefix cannot strip another segment.');
check(request($routes, '/index.php?route=product/white', 'GET', ['route' => 'product/white']) === [200, 'slug:white'], 'Legacy query-route mode.');
check(request($routes, '/', 'GET', ['route' => '']) === [200, 'home'], 'Empty query route defaults.');
foreach (['/product/a%2fb', '/product/a%5cb', '/product/%00', '/product/%', '/product/%2e%2e',
    '/product/%252f', '/product/../white', '/product/./white', "/product/white\n", '/product//white'] as $path) {
    check(request($routes, $path)[0] === 400, 'Unsafe path is rejected.');
}
check(request($routes, '/', 'GET', ['route' => ['product/white']])[0] === 400, 'Array query route rejected.');
check(request($routes, '/', 'GET', ['route' => 'product/%2f'])[0] === 400, 'Query route is not double decoded.');
foreach ([
    ['a/{id}' => ['GET' => ['RoutingProbe', 'order']], 'a/{slug}' => ['GET' => ['RoutingProbe', 'show']]],
    ['{slug}/one' => ['GET' => ['RoutingProbe', 'show']], 'one/{slug}' => ['GET' => ['RoutingProbe', 'show']]],
    ['a/{unknown}' => ['GET' => ['RoutingProbe', 'show']]],
    ['a/{id}/{id}' => ['GET' => ['RoutingProbe', 'order']]],
    ['a' => ['TRACE' => ['RoutingProbe', 'home']]],
    ['a' => ['GET' => ['RoutingProbe']]],
] as $invalid) {
    fails(fn() => RouteManager::compile($invalid), 'Invalid/ambiguous registration is fatal.');
}
RouteManager::compile(['a/{id}' => ['GET' => ['RoutingProbe', 'order']], 'b/{id}' => ['GET' => ['RoutingProbe', 'order']]]);
check(true, 'Disjoint templates accepted.');

$temp = sys_get_temp_dir() . '/vayu-routing-test-' . bin2hex(random_bytes(8));
mkdir($temp); mkdir($temp . '/Admin'); mkdir($temp . '/Storefront');
try {
    file_put_contents($temp . '/Admin/Probe.php', '<?php class NestedAdminRoutingProbe { public function show(int $id): void { echo "nested:" . $id; } }');
    file_put_contents($temp . '/Storefront/Probe.php', '<?php class NestedStorefrontRoutingProbe {}');
    ClassLoader::register(['NestedAdminRoutingProbe' => 'Admin/Probe.php', 'NestedStorefrontRoutingProbe' => 'Storefront/Probe.php'], $temp);
    check(request(['admin/{id}' => ['GET' => ['NestedAdminRoutingProbe', 'show']]], '/admin/9') === [200, 'nested:9'], 'Nested controller loads from fixed map.');
    check(class_exists('NestedStorefrontRoutingProbe'), 'Distinct global class names in separate folders load.');
    fails(fn() => ClassLoader::register(['nestedadminroutingprobe' => 'Storefront/Probe.php'], $temp), 'Case-insensitive class collision is rejected.');
    fails(fn() => ClassLoader::register(['RoutingProbe' => 'Storefront/Probe.php'], $temp), 'Already-defined class collision is rejected.');
    fails(fn() => ClassLoader::register(['OtherProbe' => '../elsewhere.php'], $temp), 'Files outside trusted root rejected.');
    check(!class_exists('ClientSelectedUnknownController'), 'Unregistered class does not resolve from URL.');
} finally {
    unlink($temp . '/Admin/Probe.php'); unlink($temp . '/Storefront/Probe.php');
    rmdir($temp . '/Admin'); rmdir($temp . '/Storefront'); rmdir($temp);
}
echo "Routing: {$checks} checks passed.\n";
