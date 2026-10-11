"""Real Vayu shopping requests against disposable SQLite or MariaDB; no auth shortcuts."""
import argparse
import html
import http.client
from http.cookies import SimpleCookie
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
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

    def request(self, path, method='GET', data=None):
        conn = http.client.HTTPConnection('127.0.0.1', self.port, timeout=10)
        headers = {'Cookie': '; '.join(f'{k}={v}' for k, v in self.cookies.items())}
        body = None
        if data is not None:
            body = urlencode(data)
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        try:
            conn.request(method, path, body, headers)
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


parser = argparse.ArgumentParser()
parser.add_argument('--mysql', action='store_true')
args = parser.parse_args()
with tempfile.TemporaryDirectory(prefix='sarada-storefront-http-') as directory:
    temp = Path(directory)
    schema = 'sarada_http_test_' + secrets.token_hex(8)
    env = dict(os.environ, APP_ENV='development', APP_DEBUG='false', PRIVATE_STORAGE_PATH=str(temp / 'private'),
               DB_TYPE='sqlite', DB_DATABASE=str(temp / 'http.sqlite'), MAIL_TRANSPORT='disabled',
               CHECKOUT_COD_ENABLED='true', CHECKOUT_SIMULATION_ENABLED='false', CHECKOUT_SHIPPING_MINOR='100', CHECKOUT_TAX_BPS='500')
    if args.mysql:
        env.update(DB_TYPE='mysql', DB_DATABASE=schema, DB_HOST=os.getenv('TEST_MYSQL_HOST', '127.0.0.1'),
                   DB_PORT=os.getenv('TEST_MYSQL_PORT', '3306'), DB_USERNAME=os.getenv('TEST_MYSQL_USER', 'root'),
                   DB_PASSWORD=os.getenv('TEST_MYSQL_PASSWORD', ''))
    fixture = temp / 'fixture.php'
    fixture.write_text('''<?php
if (getenv('DB_TYPE') === 'mysql') {
 $admin = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $schema = getenv('DB_DATABASE'); if (!preg_match('/\\Asarada_http_test_[a-f0-9]{16}\\z/', $schema)) throw new RuntimeException('Unsafe test schema.');
 if (($argv[1] ?? '') === 'cleanup') { $admin->exec('DROP DATABASE `'.$schema.'`'); exit; }
 if (($argv[1] ?? '') === 'seed') $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
require %s;
if (($argv[1] ?? '') === 'price') {
 CommerceDatabase::transaction($pdo,static function()use($pdo):void {
  $variant = $pdo->query('SELECT MIN(id) FROM product_variants')->fetchColumn();
  $pdo->prepare('UPDATE product_variants SET unit_price_minor=201 WHERE id=?')->execute([$variant]);
  ProductService::advanceVersion($pdo);
 }); exit;
}
if (($argv[1] ?? '') === 'inspect') {
 echo json_encode(['orders'=>(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'stock'=>$pdo->query('SELECT on_hand_milli,reserved_milli FROM inventory ORDER BY variant_id')->fetchAll(),
 'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM checkout_requests')->fetchColumn(), 'jobs'=>(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('order_confirmation','purchase_event')")->fetchColumn()]); exit;
}
require %s;
(new MigrationRunner($pdo,%s,static function($line){}))->run();
$adminId=CommerceAccess::provision('http-admin@example.test','Disposable-HTTP-Admin-Password');
$users=[];
foreach(['a','b'] as $name) {
 $pdo->prepare("INSERT INTO users_tbl (firstname,lastname,name,email,password,email_verify,status) VALUES ('HTTP','Customer','HTTP Customer',?,?,1,1)")->execute(['http-'.$name.'@example.test',password_hash('Disposable-HTTP-Customer-Password',PASSWORD_DEFAULT)]);
 $users[]=(int)$pdo->lastInsertId();
}
$products=new ProductService($pdo);
$category=$products->saveCategory($adminId,['slug'=>'http-stones','name'=>'HTTP Stones'],'category:http:stones');
$product=$products->save($adminId,['slug'=>'http-marble','name'=>'Marble <script>alert(1)</script>','description'=>'HTTP product','category_id'=>$category,'status'=>'active','variants'=>[
 ['sku'=>'HTTP-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>500,'unit_price_minor'=>101],
 ['sku'=>'HTTP-BOX','sell_unit'=>'box','qty_increment_milli'=>1000,'coverage_sqft_milli'=>16000,'unit_price_minor'=>12500]]],0,'product:http:marble');
foreach($product['variant_ids'] as $n=>$variant) (new InventoryService($pdo))->adjust($adminId,$variant,10000,'HTTP fixture','stock:http:'.$n);
$other=(new AddressService($pdo))->save($users[1],['recipient'=>'Other Customer','phone'=>'9999988888','line1'=>'Other Private Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101']);
echo json_encode(['users'=>$users,'variants'=>$product['variant_ids'],'other_address'=>$other['id']]);
''' % (repr(str(ROOT / 'config/bootstarp.php')), repr(str(ROOT / 'config/migrate.php')), repr(str(ROOT / 'database/migrations'))))

    def run_fixture(mode):
        result = subprocess.run([PHP, str(fixture), mode], cwd=ROOT, env=env, capture_output=True, text=True)
        if result.returncode:
            raise AssertionError(result.stdout + result.stderr)
        return json.loads(result.stdout) if result.stdout else None

    process = None
    try:
        seed = run_fixture('seed')
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
                raise AssertionError('HTTP server did not become ready.')
            guest, other = Client(port), Client(port)
            status, headers, body = guest.request('/shop')
            check(status == 200 and 'Marble &lt;script&gt;' in body and '<script>alert(1)</script>' not in body, 'Catalog renders escaped database products.')
            check(guest.request('/category/http-stones')[0] == 200, 'Category route passes its slug to listing.')
            check(guest.request('/category/missing')[0] == 404, 'Unknown category returns 404.')
            check(guest.request('/shop?sort=invalid')[0] == 422, 'Invalid sort is a safe validation response.')
            check(guest.request('/shop?q=nothing-matches')[0] == 200, 'Empty search results render successfully.')
            status, headers, body = guest.request('/api/v1/products')
            payload = json.loads(body)
            check(status == 200 and headers['Content-Type'].startswith('application/json') and isinstance(payload['data'][0]['id'], str)
                  and payload['data'][0]['variants'][0]['unit_price_minor'] == '101' and payload['meta']['total'] == 1, 'Catalog API returns data/pagination and exact string IDs/money.')
            check(json.loads(guest.request('/api/v1/products/http-marble')[2])['data']['slug'] == 'http-marble', 'Detail API consumes the route slug.')
            status, headers, body = guest.request('/api/v1/products/missing')
            check(status == 404 and json.loads(body)['error']['code'] == 'not_found', 'Missing API product gives structured 404.')
            check(guest.request('/api/v1/products?per_page=999')[0] == 422, 'API page size is bounded.')
            status, headers, body = guest.request('/cart')
            check(status == 200 and 'Your cart is empty' in body and headers['Cache-Control'] == 'private, no-store', 'Empty cart renders without warnings and stays private.')
            status, headers, body = guest.request('/product/http-marble')
            add = form(body, 'variant_id')
            add['quantity'] = '1.5'
            invalid = dict(add, csrf='invalid')
            check(guest.request('/cart', 'POST', invalid)[0] == 403, 'Cart mutation requires CSRF.')
            check(guest.request('/cart', 'POST', add)[0] == 303, 'Guest can add a real variant.')
            check('commerce_guest' in guest.cookies, 'Guest proof persists in a protected cookie.')
            status, headers, body = guest.request('/cart')
            check('1.5' in body and 'INR 1.52' in body, 'Cart uses decimal sell units and canonical half-up money.')
            owned_cart = form(body, 'variant_id')
            check('Your cart is empty' in other.request('/cart')[2], 'Second guest sees an independent cart.')
            other_csrf = form(other.request('/account')[2], 'email')['csrf']
            check(other.request('/cart', 'POST', dict(owned_cart, csrf=other_csrf))[0] == 404, 'Stolen cart ID cannot cross guest ownership.')
            check(guest.request('/cart', 'POST', add)[0] == 409, 'Stale cart revision returns conflict.')
            check(guest.request('/cart/change/1/remove')[0] == 404, 'Legacy GET remove URL cannot mutate cart.')
            check(guest.request('/checkout')[0] == 303, 'Guest checkout redirects to verified account sign-in.')
            login = form(guest.request('/account')[2], 'email')
            login.update(email='http-a@example.test', password='Disposable-HTTP-Customer-Password')
            check(guest.request('/account', 'POST', login)[0] == 303, 'Real Auth login succeeds and merges guest cart.')
            check('commerce_guest' not in guest.cookies, 'Merged guest proof is cleared.')
            status, headers, body = guest.request('/cart')
            check('INR 1.52' in body and 'Your cart is empty' not in body, 'Merged cart retains one copy of guest quantity.')
            account = guest.request('/account')[2]
            address_form = form(account, 'recipient')
            address_form.update(recipient='HTTP Customer', phone='9999988888', line1='Station Road', city='Bankura', state='West Bengal', postal_code='722101')
            check(guest.request('/account/addresses', 'POST', dict(address_form, csrf='bad'))[0] == 403, 'Address creation requires CSRF.')
            check(guest.request('/account/addresses', 'POST', address_form)[0] == 303, 'Owned address creation reaches checkout.')
            status, headers, body = guest.request('/checkout')
            check(status == 200 and 'Cash on delivery' in body and 'simulation' not in body, 'Checkout shows only enabled payment modes.')
            # Read the address from the HTML option, without selecting the other customer's record.
            import re
            address_id = re.search(r'<select name="address_id" required><option value="([0-9]+)"', body)[1]
            review_url = '/checkout?' + urlencode({'address_id': address_id, 'payment_mode': 'cod'})
            review = guest.request(review_url)[2]
            place = form(review, 'checkout_key')
            check(place['checkout_key'] == form(guest.request(review_url)[2], 'checkout_key')['checkout_key'], 'Reloading the same checkout selection preserves its key.')
            check(guest.request('/checkout?' + urlencode({'address_id': seed['other_address'], 'payment_mode': 'cod'}))[0] == 404, 'Checkout selection cannot expose another address.')
            check(guest.request('/checkout', 'POST', dict(place, checkout_key='forged-checkout-key'))[0] == 409, 'Forged checkout review key is rejected.')
            run_fixture('price')
            check(guest.request('/checkout', 'POST', place)[0] == 409 and run_fixture('inspect')['orders'] == 0, 'Price change after review returns conflict without creating an order.')
            refreshed = form(guest.request(review_url)[2], 'checkout_key')
            check(refreshed['checkout_key'] != place['checkout_key'], 'Accepting changed prices creates a fresh review key.')
            place = dict(refreshed, total_minor='1')
            status, headers, body = guest.request('/checkout', 'POST', place)
            check(status == 303 and headers['Location'].startswith('/order/'), 'Checkout redirects to persisted owned order.')
            order_url = headers['Location']
            check(guest.request('/checkout', 'POST', place)[1].get('Location') == order_url, 'Lost-response HTTP retry returns the same order.')
            status, headers, body = guest.request(order_url)
            check(status == 200 and 'Station Road' in body and 'unpaid' in body and 'INR 4.17' in body, 'Confirmation renders accepted server prices and ignores browser totals.')
            check('Your cart is empty' in guest.request('/cart')[2], 'Successful checkout closes cart without controller-side clearing.')
            other_login = form(other.request('/account')[2], 'email')
            other_login.update(email='http-b@example.test', password='Disposable-HTTP-Customer-Password')
            check(other.request('/account', 'POST', other_login)[0] == 303, 'Independent customer sign-in succeeds.')
            status, headers, body = other.request(order_url)
            check(status == 404 and 'Station Road' not in body, 'Another customer cannot read private order snapshot.')
            check(guest.request('/checkout', 'PUT')[0] == 405 and guest.request('/account/logout')[0] == 405, 'Method maps reject unsupported checkout/logout methods.')
            logout = form(guest.request('/account')[2], 'csrf')
            check(guest.request('/account/logout', 'POST', dict(logout, csrf='bad'))[0] == 403, 'Logout requires CSRF.')
            check(guest.request('/account/logout', 'POST', logout)[0] == 303 and guest.request(order_url)[0] == 401, 'Logout removes commerce order access.')
            state = run_fixture('inspect')
            check(state['orders'] == 1 and state['requests'] == 1 and state['jobs'] == 2 and int(state['stock'][0]['on_hand_milli']) == 8500
                  and int(state['stock'][0]['reserved_milli']) == 0, 'HTTP retry leaves one order/key, one set of intents and one stock deduction.')
            check('PHP Warning' not in log_path.read_text() and 'PHP Fatal' not in log_path.read_text(), 'Shopping requests complete without PHP warnings/fatal errors.')
    finally:
        if process is not None:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)
        if args.mysql:
            run_fixture('cleanup')
print(f"Storefront HTTP ({'mysql' if args.mysql else 'sqlite'}): {checks} checks passed.")
