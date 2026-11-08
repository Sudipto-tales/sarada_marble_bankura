"""WEB-03 versioned JSON API over a disposable SQLite/MariaDB store, proving effects and ownership.

Exercises the customer cart/address/order API and the staff product/inventory/order-status API against
the same seeded state the HTML flow uses, with no auth shortcuts: real cookie sessions and CSRF tokens.
"""
import argparse
import http.client
from http.cookies import SimpleCookie
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('TEST_PHP_BINARY') or shutil.which('php')
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


class Forms(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current = {'action': attrs.get('action'), 'inputs': {}}
            self.forms.append(self.current)
        elif tag == 'input' and self.current is not None and 'name' in attrs:
            if attrs.get('type') in ('checkbox', 'radio') and 'checked' not in attrs:
                return
            self.current['inputs'][attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None


def form(body, field):
    return next(item['inputs'] for item in Forms(body).forms if field in item['inputs'])


class Client:
    def __init__(self, port):
        self.port = port
        self.cookies = {}

    def request(self, path, method='GET', data=None, json_body=None, headers=None):
        conn = http.client.HTTPConnection('127.0.0.1', self.port, timeout=10)
        h = {'Cookie': '; '.join(f'{k}={v}' for k, v in self.cookies.items())}
        if headers:
            h.update(headers)
        body = None
        if json_body is not None:
            body = json_body if isinstance(json_body, str) else json.dumps(json_body)
            h.setdefault('Content-Type', 'application/json')
        elif data is not None:
            body = urlencode(data)
            h['Content-Type'] = 'application/x-www-form-urlencoded'
        try:
            conn.request(method, path, body, h)
            response = conn.getresponse()
            for key, value in response.getheaders():
                if key.lower() == 'set-cookie':
                    cookie = SimpleCookie(value)
                    for name, item in cookie.items():
                        expired = bool(item['expires']) and parsedate_to_datetime(item['expires']).timestamp() <= time.time()
                        if item.value and not expired and item['max-age'] != '0':
                            self.cookies[name] = item.value
                        else:
                            self.cookies.pop(name, None)
            return response.status, dict(response.getheaders()), response.read().decode()
        finally:
            conn.close()

    def api(self, path, method='GET', body=None, csrf=None, idempotency=None, content_type=None):
        headers = {}
        if csrf is not None:
            headers['X-CSRF-Token'] = csrf
        if idempotency is not None:
            headers['Idempotency-Key'] = idempotency
        if content_type is not None:
            headers['Content-Type'] = content_type
        status, resp_headers, text = self.request(path, method, json_body=body, headers=headers)
        payload = None
        if text:
            try:
                payload = json.loads(text)
            except json.JSONDecodeError:
                payload = None
        return status, resp_headers, payload


parser = argparse.ArgumentParser()
parser.add_argument('--mysql', action='store_true')
args = parser.parse_args()

with tempfile.TemporaryDirectory(prefix='sarada-api-http-') as directory:
    temp = Path(directory)
    schema = 'sarada_api_test_' + secrets.token_hex(8)
    env = dict(os.environ, APP_ENV='development', APP_DEBUG='false', PRIVATE_STORAGE_PATH=str(temp / 'private'),
               DB_TYPE='sqlite', DB_DATABASE=str(temp / 'api.sqlite'), MAIL_TRANSPORT='disabled',
               CHECKOUT_COD_ENABLED='true', CHECKOUT_SIMULATION_ENABLED='false', CHECKOUT_SHIPPING_MINOR='100', CHECKOUT_TAX_BPS='500')
    if args.mysql:
        env.update(DB_TYPE='mysql', DB_DATABASE=schema, DB_HOST=os.getenv('TEST_MYSQL_HOST', '127.0.0.1'),
                   DB_PORT=os.getenv('TEST_MYSQL_PORT', '3306'), DB_USERNAME=os.getenv('TEST_MYSQL_USER', 'root'),
                   DB_PASSWORD=os.getenv('TEST_MYSQL_PASSWORD', ''))
    fixture = temp / 'fixture.php'
    fixture.write_text('''<?php
if (getenv('DB_TYPE') === 'mysql') {
 $admin = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $schema = getenv('DB_DATABASE'); if (!preg_match('/\\Asarada_api_test_[a-f0-9]{16}\\z/', $schema)) throw new RuntimeException('Unsafe test schema.');
 if (($argv[1] ?? '') === 'cleanup') { $admin->exec('DROP DATABASE `'.$schema.'`'); exit; }
 if (($argv[1] ?? '') === 'seed') $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
require %s;
if (($argv[1] ?? '') === 'inspect') {
 echo json_encode(['orders'=>(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
  'stock'=>$pdo->query('SELECT variant_id,on_hand_milli,reserved_milli FROM inventory ORDER BY variant_id')->fetchAll(PDO::FETCH_ASSOC),
  'statuses'=>$pdo->query('SELECT status FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)]); exit;
}
require %s;
(new MigrationRunner($pdo,%s,static function($line){}))->run();
$adminId=CommerceAccess::provision('api-admin@example.test','Disposable-API-Admin-Password');
$users=[];
foreach(['a','b'] as $name) {
 $pdo->prepare("INSERT INTO users_tbl (firstname,lastname,name,email,password,email_verify,status) VALUES ('API','Customer','API Customer',?,?,1,1)")->execute(['api-'.$name.'@example.test',password_hash('Disposable-API-Customer-Password',PASSWORD_DEFAULT)]);
 $users[]=(int)$pdo->lastInsertId();
}
$products=new ProductService($pdo);
$category=$products->saveCategory($adminId,['slug'=>'api-stones','name'=>'API Stones'],'category:api:stones');
$product=$products->save($adminId,['slug'=>'api-marble','name'=>'API Marble','description'=>'API product','category_id'=>$category,'status'=>'active','variants'=>[
 ['sku'=>'API-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>500,'unit_price_minor'=>101]]],0,'product:api:marble');
foreach($product['variant_ids'] as $n=>$variant) (new InventoryService($pdo))->adjust($adminId,$variant,10000,'API fixture','stock:api:'.$n);
echo json_encode(['admin'=>$adminId,'users'=>$users,'category'=>$category,'variant'=>$product['variant_ids'][0]]);
''' % (repr(str(ROOT / 'config/bootstarp.php')), repr(str(ROOT / 'config/migrate.php')), repr(str(ROOT / 'database/migrations'))))

    def run_fixture(mode):
        result = subprocess.run([PHP, str(fixture), mode], cwd=ROOT, env=env, capture_output=True, text=True)
        if result.returncode:
            raise AssertionError(result.stdout + result.stderr)
        return json.loads(result.stdout) if result.stdout else None

    process = None
    try:
        seed = run_fixture('seed')
        variant_id = str(seed['variant'])
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        log_path = temp / 'server.log'
        with log_path.open('w+') as log:
            process = subprocess.Popen([PHP, 'vayu', 'run', f'--port={port}'], cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True)
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    raise AssertionError('Server exited: ' + log_path.read_text())
                try:
                    with socket.create_connection(('127.0.0.1', port), .2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise AssertionError('API server did not become ready.')

        a, b, admin, anon = Client(port), Client(port), Client(port), Client(port)

        def login(client, email):
            fields = form(client.request('/account/login')[2], 'email')
            fields.update(email=email, password='Disposable-API-Customer-Password')
            check(client.request('/account/login', 'POST', fields)[0] == 303, f'{email} signs in.')

        login(a, 'api-a@example.test')
        login(b, 'api-b@example.test')

        # --- Customer cart API ---
        status, headers, payload = a.api('/api/v1/cart')
        check(status == 200 and headers['Content-Type'].startswith('application/json')
              and headers['Cache-Control'] == 'private, no-store', 'Cart API is JSON and private.')
        check(payload['data']['items'] == [] and payload['data']['currency'] == 'INR'
              and isinstance(payload['csrf_token'], str) and payload['csrf_token'], 'Empty cart returns a CSRF bootstrap token.')
        csrf = payload['csrf_token']

        add = {'action': 'add', 'variant_id': variant_id, 'quantity': '1.5'}
        check(a.api('/api/v1/cart', 'POST', add)[0] == 403, 'Cart mutation without CSRF is rejected.')
        check(a.api('/api/v1/cart', 'POST', add, csrf='wrong-token')[0] == 403, 'Cart mutation with a bad CSRF token is rejected.')
        check(a.api('/api/v1/cart', 'POST', 'not json', csrf=csrf)[0] == 400, 'Malformed JSON body is a 400.')
        check(a.api('/api/v1/cart', 'POST', urlencode(add), csrf=csrf,
                    content_type='application/x-www-form-urlencoded')[0] == 415, 'Non-JSON content type is a 415.')
        status, _, payload = a.api('/api/v1/cart', 'POST', dict(add, variant_id='not-an-id'), csrf=csrf)
        check(status == 422 and payload['error']['code'] == 'invalid_input', 'Invalid variant id is a 422 validation error.')
        status, _, payload = a.api('/api/v1/cart', 'POST', dict(add, variant_id='999999'), csrf=csrf)
        check(status == 409 and payload['error']['code'] == 'variant_unavailable', 'An unknown variant is a 409 conflict.')
        over_limit = json.dumps(add) + ' ' * 17000
        check(a.api('/api/v1/cart', 'POST', over_limit, csrf=csrf)[0] == 413, 'Oversized JSON body is a 413.')

        status, _, payload = a.api('/api/v1/cart', 'POST', add, csrf=csrf)
        check(status == 200 and len(payload['data']['items']) == 1
              and payload['data']['items'][0]['variant_id'] == variant_id
              and payload['data']['items'][0]['qty_milli'] == 1500, 'Add returns the updated cart with string IDs and milli quantity.')
        cart_id = payload['data']['id']
        cart_revision = payload['data']['revision']
        check(isinstance(cart_id, str) and payload['data']['subtotal_minor'] == '152', 'Line total is canonical minor-unit money as a string.')

        # --- Customer address API ---
        status, _, payload = a.api('/api/v1/addresses')
        check(status == 200 and payload['data'] == [], "New customer has no addresses.")
        new_address = {'recipient': 'API Recipient', 'phone': '9876500000', 'line1': 'Station Road',
                       'city': 'Bankura', 'state': 'West Bengal', 'postal_code': '722101'}
        status, _, payload = a.api('/api/v1/addresses', 'POST', new_address, csrf=csrf)
        check(status == 201 and isinstance(payload['data']['id'], str) and payload['data']['is_default'] == 1, 'Address create returns 201 and a default address.')
        address_id = payload['data']['id']
        check(len(a.api('/api/v1/addresses')[2]['data']) == 1, 'Created address appears in the owned listing.')

        # --- Customer order API (idempotent placement) ---
        order_body = {'cart_id': cart_id, 'cart_revision': cart_revision, 'address_id': address_id, 'payment_mode': 'cod'}
        check(a.api('/api/v1/orders', 'POST', order_body, csrf=csrf)[0] == 422, 'Placement without a valid Idempotency-Key is a 422 (missing operation key).')
        status, _, payload = a.api('/api/v1/orders', 'POST', order_body, csrf=csrf, idempotency='api-order-key-0001')
        check(status == 201 and payload['data']['status'] == 'placed' and payload['data']['order_id'] == payload['data']['id']
              and payload['data']['payment_mode'] == 'cod', 'COD placement returns 201 with the persisted order id.')
        order_id = payload['data']['order_id']
        status, _, replay = a.api('/api/v1/orders', 'POST', order_body, csrf=csrf, idempotency='api-order-key-0001')
        check(status == 201 and replay['data']['order_id'] == order_id, 'Replaying the idempotency key returns the same order.')
        check(run_fixture('inspect')['orders'] == 1, 'The idempotent retry created exactly one order.')

        status, _, payload = a.api(f'/api/v1/orders/{order_id}')
        check(status == 200 and payload['data']['id'] == order_id and payload['data']['items'][0]['variant_id'] == variant_id
              and payload['data']['address']['recipient'] == 'API Recipient', 'Owner reads the order snapshot and history.')
        listing = a.api('/api/v1/orders')[2]
        check(len(listing['data']) == 1 and listing['data'][0]['id'] == order_id, 'Order listing shows the owned order.')

        # --- Ownership and method guards ---
        check(b.api(f'/api/v1/orders/{order_id}')[0] == 404, "Another customer cannot read the order.")
        check(b.api('/api/v1/orders')[2]['data'] == [], "Another customer's order listing is empty.")
        check(anon.api('/api/v1/orders')[0] == 401, 'Anonymous order access is 401.')
        check(anon.api('/api/v1/addresses')[0] == 401, 'Anonymous address access is 401.')
        status, headers, _ = anon.request('/api/v1/orders', 'PUT')
        check(status == 405 and 'GET' in headers.get('Allow', '') and 'POST' in headers.get('Allow', ''), 'Unsupported method is 405 with an Allow header.')
        check(anon.request('/api/v1/orders/' + order_id, 'POST')[0] == 405, 'Order detail rejects POST.')

        # HTML/API equivalence: the API order is visible in the HTML surfaces for its owner.
        check('/order/' + order_id in a.request('/orders')[2], 'API-placed order appears in the HTML order history.')
        check(a.request('/order/' + order_id)[0] == 200 and b.request('/order/' + order_id)[0] == 404, 'HTML order view enforces the same ownership.')

        # --- Public analytics events (EVT-01) ---
        product_id = anon.api('/api/v1/products')[2]['data'][0]['id']
        check(a.api('/api/v1/events', 'POST', {'type': 'product_view', 'product_id': product_id})[0] == 403, 'Event ingestion requires CSRF.')
        status, _, payload = a.api('/api/v1/events', 'POST', {'type': 'product_view', 'product_id': product_id}, csrf=csrf)
        check(status == 202 and payload['data']['status'] == 'accepted', 'A product_view event is accepted.')
        check(a.api('/api/v1/events', 'POST', {'type': 'purchase', 'order_id': order_id}, csrf=csrf)[0] == 422, 'A browser cannot post a trusted purchase event.')
        check(a.api('/api/v1/events', 'POST', {'type': 'product_view', 'product_id': '999999'}, csrf=csrf)[0] == 404, 'A product_view for an unknown product is 404.')

        # --- Staff API ---
        admin_form = form(admin.request('/admin/login')[2], 'email')
        admin_form.update(email='api-admin@example.test', password='Disposable-API-Admin-Password')
        check(admin.request('/admin/login', 'POST', admin_form)[1].get('Location', '').endswith('/admin'), 'Staff signs in to the console.')

        check(anon.api('/api/admin/products')[0] == 401, 'Anonymous staff product read is 401.')
        check(a.api('/api/admin/products')[0] == 403, 'A customer without the grant is forbidden from the staff API.')
        status, _, payload = admin.api('/api/admin/products')
        check(status == 200 and payload['meta']['total'] == 1 and payload['data'][0]['slug'] == 'api-marble'
              and isinstance(payload['csrf_token'], str), 'Staff product listing returns the catalog and a CSRF token.')
        admin_csrf = payload['csrf_token']

        # Inventory adjustment via API, with CSRF and an operation key.
        before = {row['variant_id']: int(row['on_hand_milli']) for row in run_fixture('inspect')['stock']}
        adjust = {'variant_id': variant_id, 'delta_milli': 2000, 'reason': 'API restock', 'operation_key': 'api-adjust-key-0001'}
        check(admin.api('/api/admin/inventory', 'POST', adjust)[0] == 403, 'Inventory adjust requires CSRF.')
        status, _, payload = admin.api('/api/admin/inventory', 'POST', adjust, csrf=admin_csrf)
        check(status == 200 and int(payload['data']['on_hand_milli']) == before[int(variant_id)] + 2000, 'Inventory adjust applies and returns the new stock.')
        check(int({row['variant_id']: row['on_hand_milli'] for row in run_fixture('inspect')['stock']}[int(variant_id)]) == before[int(variant_id)] + 2000, 'Stock change is persisted.')
        # Replaying the operation key does not double-apply.
        status, _, payload = admin.api('/api/admin/inventory', 'POST', adjust, csrf=admin_csrf)
        check(status == 200 and int({row['variant_id']: row['on_hand_milli'] for row in run_fixture('inspect')['stock']}[int(variant_id)]) == before[int(variant_id)] + 2000, 'Replayed adjustment key is a no-op.')

        # Order status transitions via API follow the real state machine (placed -> awaiting_verify -> confirmed).
        gate = {'target': 'awaiting_verify', 'revision': 1, 'operation_key': 'api-status-key-0001', 'reason': 'Gate via API'}
        check(admin.api(f'/api/admin/orders/{order_id}/status', 'POST', gate)[0] == 403, 'Order transition requires CSRF.')
        check(admin.api(f'/api/admin/orders/{order_id}/status', 'POST', {'target': 'delivered', 'revision': 1, 'operation_key': 'api-status-bad-0001'}, csrf=admin_csrf)[0] == 409, 'An out-of-order transition is a 409 conflict.')
        status, _, payload = admin.api(f'/api/admin/orders/{order_id}/status', 'POST', gate, csrf=admin_csrf)
        check(status == 200 and payload['data']['status'] == 'awaiting_verify' and payload['data']['revision'] == 2, 'Staff advances the order into the verification gate.')
        status, _, replay = admin.api(f'/api/admin/orders/{order_id}/status', 'POST', gate, csrf=admin_csrf)
        check(status == 200 and replay['data']['status'] == 'awaiting_verify', 'Replaying the transition key is idempotent.')
        confirm = {'target': 'confirmed', 'revision': 2, 'operation_key': 'api-status-key-0002', 'reason': 'Confirmed via API'}
        status, _, payload = admin.api(f'/api/admin/orders/{order_id}/status', 'POST', confirm, csrf=admin_csrf)
        check(status == 200 and payload['data']['status'] == 'confirmed' and payload['data']['revision'] == 3, 'Staff confirms the order through the API.')
        check(a.api(f'/api/v1/orders/{order_id}')[2]['data']['status'] == 'confirmed', 'The customer API reflects the staff transition.')
        check(run_fixture('inspect')['statuses'] == ['confirmed'], 'The persisted order status is confirmed.')

        # Product create via staff API is visible on the public API (write equivalence).
        new_product = {'slug': 'api-granite', 'name': 'API Granite', 'category_id': seed['category'], 'status': 'active',
                       'operation_key': 'api-product-key-0001', 'revision': 0,
                       'variants': [{'sku': 'API-GRANITE', 'sell_unit': 'sqft', 'qty_increment_milli': 1000, 'unit_price_minor': 250}]}
        status, _, payload = admin.api('/api/admin/products', 'POST', new_product, csrf=admin_csrf)
        check(status == 200 and isinstance(payload['data']['id'], str) and payload['data']['revision'] == 1, 'Staff creates a product through the API.')
        public = anon.api('/api/v1/products')[2]
        check(any(item['slug'] == 'api-granite' for item in public['data']) and public['meta']['total'] == 2, 'The new product is live on the public API.')

        print(f'API HTTP ({"mysql" if args.mysql else "sqlite"}): {checks} checks passed.')
    finally:
        if process and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
        if args.mysql:
            try:
                run_fixture('cleanup')
            except Exception:
                pass
