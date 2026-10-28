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
 'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM checkout_requests')->fetchColumn(), 'jobs'=>(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('order_confirmation','purchase_event')")->fetchColumn(),
 'order_rows'=>$pdo->query('SELECT id,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor FROM orders ORDER BY id')->fetchAll()]); exit;
}
if (($argv[1] ?? '') === 'extra') {
 $catalog=new ProductService($pdo);$adminId=(int)$pdo->query("SELECT id FROM users_tbl WHERE email='http-admin@example.test'")->fetchColumn();
 $category=(int)$pdo->query("SELECT id FROM categories WHERE slug='http-stones'")->fetchColumn();$ids=[];
 foreach (range(1,4) as $n) { $row=$catalog->save($adminId,['slug'=>'extra-'.$n,'name'=>'Extra '.$n,'category_id'=>$category,'status'=>'active','variants'=>[['sku'=>'EXTRA-'.$n,'sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>100]]],0,'http:extra:'.$n);$ids[]=$row['id']; }
 echo json_encode($ids);exit;
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
 ['sku'=>'HTTP-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>500,'unit_price_minor'=>101,'thickness_mm_milli'=>18500,'length_mm_milli'=>2400000,'width_mm_milli'=>1200000],
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
            status, headers, body = guest.request('/')
            check(status == 200 and 'Beautiful spaces.' in body and 'Marble &lt;script&gt;' in body
                  and '<script>alert(1)</script>' not in body, 'Home renders the design and escaped live database products without JavaScript.')
            check('/assets/storefront/home.css' in body and 'storefront-data.js' not in body
                  and '.html' not in body and '../Html' not in body, 'PHP home uses local production assets and PHP destinations only.')
            check(guest.request('/', 'POST')[0] == 405, 'Home rejects unsupported mutations.')
            check(guest.request('/catalogues')[0] == 200 and 'KasaMood' in guest.request('/catalogues')[2], 'Catalogue library renders server-side.')
            check(guest.request('/catalogue/kasamood')[0] == 200 and 'application/pdf' in guest.request('/catalogue/kasamood')[2], 'Original catalogue reader renders without JavaScript.')
            check(guest.request('/catalogue/missing')[0] == 404, 'Unknown catalogue returns 404.')
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
            try:
                connection.request('GET', '/assets/storefront/assets/catalogs/kasamood/KasaMood.pdf')
                pdf = connection.getresponse()
                check(pdf.status == 200 and pdf.getheader('Content-Type').startswith('application/pdf')
                      and pdf.read().startswith(b'%PDF-'), 'Vayu serves the actual local PDF with the correct content type.')
            finally:
                connection.close()
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
            check(status == 200 and all(section in body for section in ['id="about-stone"', 'id="specifications"', 'id="delivery"', 'id="reviews"']), 'Product detail renders its information sections without JavaScript.')
            check('18.5 mm' in body and '2400 mm' in body and '1200 mm' in body and '16 sqft' in body, 'Specifications expose actual dimensions and variant coverage with correct units.')
            check('data-mobile-purchase' in body and 'product-detail.js' in body and 'product-detail.css' in body
                  and 'No reviews yet' in body and 'verified-purchase review' not in body and '25% off' not in body, 'Product purchase enhancements load without fabricated reviews or discounts.')
            add = form(body, 'variant_id')
            add['quantity'] = '1.5'
            invalid = dict(add, csrf='invalid')
            check(guest.request('/cart', 'POST', invalid)[0] == 403, 'Cart mutation requires CSRF.')
            check(guest.request('/cart', 'POST', add)[0] == 303, 'Guest can add a real variant.')
            check('commerce_guest' in guest.cookies, 'Guest proof persists in a protected cookie.')
            status, headers, body = guest.request('/cart')
            check('1.5' in body and '₹1.52' in body, 'Cart uses decimal sell units and canonical half-up money.')
            owned_cart = form(body, 'variant_id')
            check('Your cart is empty' in other.request('/cart')[2], 'Second guest sees an independent cart.')
            other_csrf = form(other.request('/account')[2], 'email')['csrf']
            check(other.request('/cart', 'POST', dict(owned_cart, csrf=other_csrf))[0] == 404, 'Stolen cart ID cannot cross guest ownership.')
            check(add.get('action') == 'add' and 'cart_revision' not in add, 'Product page add form uses additive add-to-bag action.')
            accumulate = dict(add, csrf=other_csrf, quantity='1', return_to='//evil.example/x')
            status, headers, _ = other.request('/cart', 'POST', accumulate)
            check(status == 303 and headers.get('Location') == '/cart', 'Unsafe add-to-bag return paths fall back to the cart.')
            status, headers, _ = other.request('/cart', 'POST', dict(accumulate, return_to='product/http-marble'))
            check(status == 303 and headers.get('Location') == '/product/http-marble', 'Add to bag can return to the browsing page.')
            other_body = other.request('/product/http-marble')[2]
            check('now in your bag' in other_body and '₹2.02' in other.request('/cart')[2], 'Repeated add to bag accumulates quantity and shows a notice.')
            check(other.request('/cart', 'POST', dict(accumulate, quantity='10000'))[0] == 409, 'Add to bag cannot exceed available stock.')
            check(guest.request('/cart', 'POST', owned_cart)[0] == 303, 'Cart page update uses the current revision.')
            check(guest.request('/cart', 'POST', owned_cart)[0] == 409, 'Stale cart revision returns conflict.')
            check(guest.request('/cart/change/1/remove')[0] == 404, 'Legacy GET remove URL cannot mutate cart.')
            check(guest.request('/checkout')[0] == 303, 'Guest checkout redirects to verified account sign-in.')
            login = form(guest.request('/account')[2], 'email')
            login.update(email='http-a@example.test', password='Disposable-HTTP-Customer-Password')
            check(guest.request('/account', 'POST', login)[0] == 303, 'Real Auth login succeeds and merges guest cart.')
            check('commerce_guest' not in guest.cookies, 'Merged guest proof is cleared.')
            status, headers, body = guest.request('/cart')
            check('₹1.52' in body and 'Your cart is empty' not in body, 'Merged cart retains one copy of guest quantity.')
            account = guest.request('/account')[2]
            address_form = form(account, 'recipient')
            address_form.update(recipient='HTTP Customer', phone='9999988888', line1='Station Road', city='Bankura', state='West Bengal', postal_code='722101')
            check(guest.request('/account/addresses', 'POST', dict(address_form, csrf='bad'))[0] == 403, 'Address creation requires CSRF.')
            check(guest.request('/account/addresses', 'POST', address_form)[0] == 303, 'Owned address creation reaches checkout.')
            status, headers, body = guest.request('/checkout')
            check(status == 200 and 'Cash on delivery' in body and 'simulation' not in body, 'Checkout shows only enabled payment modes.')
            # Read the address from the first address radio, without selecting the other customer's record.
            import re
            address_id = re.search(r'<input type="radio" name="address_id" value="([0-9]+)"', body)[1]
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
            check(status == 200 and 'Station Road' in body and 'Unpaid' in body and '₹4.17' in body, 'Confirmation renders accepted server prices and ignores browser totals.')
            invoice_url = order_url + '/invoice'
            status, headers, invoice = guest.request(invoice_url)
            check(status == 200 and 'Print / Save PDF' in invoice and '@media print' in invoice, 'Owner can open a print-friendly HTML invoice.')
            check('Station Road' in invoice and '&lt;script&gt;' in invoice and '<script>alert(1)</script>' not in invoice, 'Invoice escapes stored material names and uses the address snapshot.')
            invoice_row = run_fixture('inspect')['order_rows'][0]
            for field in ['subtotal_minor','discount_minor','shipping_minor','tax_minor','total_minor']:
                amount = int(invoice_row[field])
                check(f'data-total="{field}">₹{amount//100}.{amount%100:02d}' in invoice, 'Invoice ' + field + ' matches the persisted order without recomputing it.')
            check('Your cart is empty' in guest.request('/cart')[2], 'Successful checkout closes cart without controller-side clearing.')
            other_login = form(other.request('/account')[2], 'email')
            other_login.update(email='http-b@example.test', password='Disposable-HTTP-Customer-Password')
            check(other.request('/account', 'POST', other_login)[0] == 303, 'Independent customer sign-in succeeds.')
            status, headers, body = other.request(order_url)
            check(status == 404 and 'Station Road' not in body, 'Another customer cannot read private order snapshot.')
            check(other.request(invoice_url)[0] == 404 and 'Station Road' not in other.request(invoice_url)[2], 'Invoice enforces customer ownership.')
            check(Client(port).request(invoice_url)[0] == 401, 'Invoice requires an authenticated commerce identity.')
            check(guest.request(invoice_url,'POST',{'csrf':place['csrf']})[0] == 405, 'Invoice route is read-only.')
            check(guest.request('/checkout', 'PUT')[0] == 405 and guest.request('/account/logout')[0] == 405, 'Method maps reject unsupported checkout/logout methods.')
            logout = form(guest.request('/account')[2], 'csrf')
            check(guest.request('/account/logout', 'POST', dict(logout, csrf='bad'))[0] == 403, 'Logout requires CSRF.')
            check(guest.request('/account/logout', 'POST', logout)[0] == 303 and guest.request(order_url)[0] == 401, 'Logout removes commerce order access.')
            state = run_fixture('inspect')
            check(state['orders'] == 1 and state['requests'] == 1 and state['jobs'] == 2 and int(state['stock'][0]['on_hand_milli']) == 8500
                  and int(state['stock'][0]['reserved_milli']) == 0, 'HTTP retry leaves one order/key, one set of intents and one stock deduction.')
            # Remaining prototype pages use the same live services/guards.
            for path, title in [('/calculator','Every square foot'),('/compare','The details make'),('/wishlist','The ones you love'),('/visualizer','visualizer-config'),('/saved-designs','Ideas to come back'),('/visualizer/help','See your ideas')]:
                status, headers, tool = guest.request(path)
                check(status == 200 and title in tool and headers['Cache-Control'] == 'private, no-store', 'Working PHP tool route: '+path)
            check('SARADA' not in guest.request('/shop')[2] and 'visualizer-editor.js' not in guest.request('/shop')[2], 'Heavy photo scripts are not loaded by catalog pages.')
            check(guest.request('/shop?finish=missing')[0] == 200 and 'No products found' in guest.request('/shop?finish=missing')[2], 'Finish filter renders an honest empty state.')
            check(guest.request('/shop?in_stock=2')[0] == 422, 'Invalid stock filter is rejected.')
            check(guest.request('/orders')[0] == 401, 'Order history requires a verified account.')
            check(guest.request('/account/register')[0] == 200 and 'currently unavailable' in guest.request('/account/register')[2], 'Signup explains its disabled verification transport.')
            check(guest.request('/auth/verify_email?token=forged')[0] == 422, 'Forged verification link is rejected.')
            detail = guest.request('/product/http-marble')[2]
            token = form(detail, 'variant_id')['csrf']
            check(guest.request('/wishlist','POST',{'csrf':'bad','product_id':seed['variants'][0]})[0] == 403, 'Wishlist mutations require CSRF.')
            check(guest.request('/compare','POST',{'csrf':'bad','product_id':'1'})[0] == 403, 'Comparison mutations require CSRF.')
            # Fixture has one product; derive its database ID from its own guarded form.
            product_id = next(f['inputs']['product_id'] for f in Forms(detail).forms if f['action']=='/wishlist')
            pick = {'csrf':token,'product_id':product_id}
            check(guest.request('/wishlist','POST',pick)[0] == 303 and 'Marble &lt;script&gt;' in guest.request('/wishlist')[2], 'Guest saves a real escaped material.')
            check(other.request('/wishlist')[2].count('class="stone-card"') == 0, 'Signed-in customer cannot see guest saved items.')
            check(guest.request('/compare','POST',pick)[0] == 303 and 'Variants &amp; selling units' in guest.request('/compare')[2], 'Comparison uses real material variants and units.')
            check(guest.request('/compare','POST',dict(pick,action='remove'))[0] == 303 and 'A little perspective helps' in guest.request('/compare')[2], 'Comparison remove returns useful empty state.')
            calculate = '/calculator?' + urlencode({'variant_id':seed['variants'][0],'surfaces[0][name]':'Floor','surfaces[0][length]':'1','surfaces[0][width]':'2','surfaces[0][unit]':'ft','surfaces[0][cutout]':'0','waste_bps':'1000'})
            calculator = guest.request(calculate)
            check(calculator[0] == 200 and '2.2 sqft' in calculator[2], 'Calculator renders rational coverage and wastage.')
            handoff = form(calculator[2], 'variant_id')
            check(handoff['quantity']=='2.5' and guest.request('/cart','POST',handoff)[0] == 303 and '2.5' in guest.request('/cart')[2], 'Calculator rounds to real variant increments and hands off to the owned cart.')
            check(guest.request('/calculator?surfaces[0][length]=-1')[0] == 422, 'Calculator rejects invalid measurements.')
            login = form(guest.request('/account')[2], 'email')
            login.update(email='http-a@example.test',password='Disposable-HTTP-Customer-Password')
            check(guest.request('/account','POST',login)[0] == 303, 'Customer signs back in through actual Auth.')
            token = form(guest.request('/account')[2], 'csrf')['csrf']
            owned_pick={'csrf':token,'product_id':product_id,'user_id':str(seed['users'][1])}
            check(guest.request('/wishlist','POST',owned_pick)[0] == 303 and guest.request('/wishlist','POST',owned_pick)[0] == 303 and guest.request('/wishlist')[2].count('class="stone-card"') == 1, 'Signed-in repeated saves create one owned selection and ignore forged owner.')
            check(other.request('/wishlist')[2].count('class="stone-card"') == 0, 'Account wishlist isolation is preserved.')
            other_token=form(other.request('/account')[2], 'csrf')['csrf']
            check(other.request('/wishlist','POST',{'csrf':other_token,'product_id':product_id,'action':'remove','user_id':str(seed['users'][0])})[0] == 303 and guest.request('/wishlist')[2].count('class="stone-card"') == 1, 'Another customer remove cannot delete the first account selection.')
            check(guest.request('/orders')[0] == 200 and order_url in guest.request('/orders')[2], 'History lists the real owned order.')
            check(order_url not in other.request('/orders')[2], 'History hides another customer order.')
            account = guest.request('/account')[2]
            edit = next(f['inputs'] for f in Forms(account).forms if f['action']=='/account/addresses' and 'id' in f['inputs'])
            forged=dict(edit,id=str(seed['other_address']))
            check(guest.request('/account/addresses','POST',forged)[0] == 404, 'Address edit checks server ownership.')
            check(guest.request('/account/addresses/archive','POST',forged)[0] == 404, 'Address archive checks server ownership.')
            check(guest.request('/account/addresses','POST',dict(edit,line1='Updated Road'))[0] == 303, 'Owned address edit accepts current revision.')
            check(guest.request('/account/addresses','POST',edit)[0] == 409, 'Stale address revision cannot overwrite edits.')
            check(guest.request('/account/addresses/archive','POST',edit)[0] == 409, 'Stale address archive cannot remove updated data.')
            fresh = next(f['inputs'] for f in Forms(guest.request('/account')[2]).forms if f['action']=='/account/addresses' and 'id' in f['inputs'])
            check(guest.request('/account/addresses/archive','POST',fresh)[0] == 303 and 'Updated Road' not in guest.request('/account')[2], 'Owned address archive removes it from active addresses.')
            check('Station Road' in guest.request(order_url)[2] and 'Updated Road' not in guest.request(order_url)[2], 'Existing order retains its immutable delivery snapshot after address edits/archive.')
            check('Track your order' in guest.request(order_url)[2], 'Order detail renders recorded transition history.')
            extra_ids = run_fixture('extra')
            for selection_id in [product_id] + extra_ids[:3]:
                check(guest.request('/compare','POST',{'csrf':token,'product_id':str(selection_id)})[0] == 303, 'Comparison accepts each of four real materials.')
            check(guest.request('/compare','POST',{'csrf':token,'product_id':str(extra_ids[3])})[0] == 422 and '4 of 4' in guest.request('/compare')[2], 'Comparison bounds the shortlist at four without dropping prior selections.')
            third = Client(port)
            third_login=form(third.request('/account')[2], 'email')
            third_login.update(email='http-a@example.test',password='Disposable-HTTP-Customer-Password')
            check(third.request('/account','POST',third_login)[0] == 303 and third.request('/wishlist')[2].count('class="stone-card"') == 1, 'Account wishlist persists across independent browser sessions.')
            check('A little perspective helps' in third.request('/compare')[2], 'Comparison remains private to each browser session.')
            check(guest.request('/shop?min_price=1.001')[0] == 422 and guest.request('/shop?min_price=2&max_price=1')[0] == 422, 'Readable price filters reject malformed or inverted ranges.')
            check(guest.request('/admin/products')[0] == 403 and guest.request('/admin/product?id='+product_id)[0] == 403, 'Customer cannot access staff catalog or product details.')
            staff=Client(port)
            staff_login=form(staff.request('/account')[2], 'email')
            staff_login.update(email='http-admin@example.test',password='Disposable-HTTP-Admin-Password')
            check(staff.request('/account','POST',staff_login)[0] == 303, 'Provisioned commerce staff uses the commerce account session.')
            check(staff.request('/admin/products')[0] == 200 and 'Catalog overview' in staff.request('/admin/products')[2], 'Staff catalog calls the real service without legacy namespace errors.')
            check(staff.request('/admin/product?id='+product_id)[0] == 200 and 'HTTP-SQFT' in staff.request('/admin/product?id='+product_id)[2], 'Staff detail hydrates the requested persisted product.')
            check(staff.request('/admin/products','POST',{'csrf':token})[0] == 405, 'Read-only staff route rejects undocumented writes.')
            settings_form = form(staff.request('/admin/settings')[2], 'shipping')
            settings_form.update(shipping='1.25', tax_bps='500', cod_enabled='1', razorpay_enabled='0')
            check(staff.request('/admin/settings', 'POST', dict(settings_form, csrf='bad'))[0] == 403, 'Settings changes require CSRF.')
            check(guest.request('/admin/settings', 'POST', settings_form)[0] == 403, 'Customer cannot change checkout settings.')
            check(staff.request('/admin/settings', 'POST', dict(settings_form, tax_bps='10001'))[0] == 422, 'Settings form reports invalid tax rate.')
            check(staff.request('/admin/settings', 'POST', settings_form)[0] == 303, 'Staff settings form persists typed checkout settings.')
            check('value="1.25"' in staff.request('/admin/settings')[2], 'Settings form displays the stored delivery charge.')
            coupon_form = form(staff.request('/admin/coupon')[2], 'code')
            coupon_form.update(code='HTTP10', kind='fixed', value='0.10', min_order='0', max_discount='', usage_limit='1', per_user_limit='1', enabled='1')
            check(staff.request('/admin/coupon','POST',dict(coupon_form,csrf='bad'))[0] == 403, 'Coupon mutations require CSRF.')
            status, headers, body = staff.request('/admin/coupon','POST',coupon_form)
            check(status == 303 and headers['Location'].startswith('/admin/coupon?id='), 'Staff coupon form creates a persisted coupon.')
            coupon_url = headers['Location']
            check(staff.request(coupon_url)[0] == 200 and 'HTTP10' in staff.request('/admin/coupons')[2], 'Coupon edit and list pages load real records.')
            check(guest.request('/admin/coupons')[0] == 403 and guest.request('/admin/reports')[0] == 403, 'Coupon administration and reports enforce grants.')
            new_address = form(guest.request('/account')[2], 'recipient')
            new_address.update(recipient='Coupon Buyer',phone='9999988888',line1='Coupon Road',city='Bankura',state='West Bengal',postal_code='722101')
            check(guest.request('/account/addresses','POST',new_address)[0] == 303, 'Coupon checkout uses an owned delivery address.')
            add_form = form(guest.request('/product/http-marble')[2], 'variant_id')
            add_form.update(variant_id=str(seed['variants'][0]),quantity='1')
            check(guest.request('/cart','POST',add_form)[0] == 303, 'Coupon checkout uses the real owned cart.')
            checkout = guest.request('/checkout')[2]
            coupon_address = re.search(r'<input type="radio" name="address_id" value="([0-9]+)"', checkout)[1]
            coupon_review_url = '/checkout?' + urlencode({'address_id':coupon_address,'payment_mode':'cod'})
            coupon_apply = next(item['inputs'] for item in Forms(guest.request(coupon_review_url)[2]).forms
                                if item['action'] and item['action'].endswith('/checkout/coupon'))
            coupon_apply['coupon_code'] = 'http10'
            check(guest.request('/checkout/coupon','POST',dict(coupon_apply,csrf='bad'))[0] == 403, 'Applying a coupon requires CSRF.')
            check(guest.request('/checkout/coupon','POST',coupon_apply)[0] == 303, 'Valid coupon is stored in the private reviewed checkout session.')
            coupon_place = form(guest.request(coupon_review_url)[2], 'checkout_key')
            check(guest.request('/checkout/coupon','POST',dict(coupon_apply,coupon_code=''))[0] == 303, 'Coupon can be removed.')
            check(guest.request('/checkout','POST',coupon_place)[0] == 409, 'Removing the coupon invalidates a stale reviewed submission.')
            check(guest.request('/checkout/coupon','POST',coupon_apply)[0] == 303, 'Coupon can be applied again before order placement.')
            coupon_place = form(guest.request(coupon_review_url)[2], 'checkout_key')
            status, headers, body = guest.request('/checkout','POST',coupon_place)
            check(status == 303 and headers['Location'].startswith('/order/'), 'Coupon checkout places a discounted order through the existing engine.')
            coupon_order = headers['Location']
            order_html = guest.request(coupon_order)[2]
            coupon_row = next(row for row in run_fixture('inspect')['order_rows'] if str(row['id']) == coupon_order.rsplit('/',1)[1])
            taxable = int(coupon_row['subtotal_minor']) - 10
            expected_tax = (taxable * 500 + 5000) // 10000
            expected_total = taxable + 125 + expected_tax
            check(int(coupon_row['discount_minor']) == 10 and int(coupon_row['tax_minor']) == expected_tax and int(coupon_row['total_minor']) == expected_total
                  and f'₹{expected_total//100}.{expected_total%100:02d}' in order_html and '₹0.10' in order_html, 'Order renders authoritative discount, delivery and post-discount tax.')
            check(guest.request('/checkout','POST',coupon_place)[1].get('Location') == coupon_order, 'Discounted HTTP retry returns the same order despite exhausted usage limit.')
            check(staff.request('/admin/reports')[0] == 200 and 'Paid order revenue' in staff.request('/admin/reports')[2], 'Sales reports render bounded real aggregates.')
            check(staff.request('/admin/reports?from=2020-01-01&to=2026-10-16')[0] == 422, 'Reports reject an excessive date range.')
            archive = next(item['inputs'] for item in Forms(staff.request(coupon_url)[2]).forms if item['action']=='/admin/coupon/archive')
            check(staff.request('/admin/coupon/archive','POST',archive)[0] == 303, 'Coupon archive preserves prior redemptions and orders.')
            status, headers, discounted_invoice = guest.request(coupon_order + '/invoice')
            check(status == 200 and 'Coupon Road' in discounted_invoice, 'Discounted order invoice retains its owned delivery snapshot.')
            for field in ['subtotal_minor','discount_minor','shipping_minor','tax_minor','total_minor']:
                amount = int(coupon_row[field])
                check(f'data-total="{field}">₹{amount//100}.{amount%100:02d}' in discounted_invoice, 'Discounted invoice ' + field + ' matches the persisted order.')
            signin = Client(port).request('/account')[2]
            check('/account/forgot' in signin and Client(port).request('/account/forgot')[0] == 200, 'Existing password reset entry remains reachable from sign-in.')
            category_page = staff.request('/admin/categories')[2]
            category_id = re.search(r'/admin/categories\?edit=([0-9]+)', category_page)[1]
            category_url = '/admin/categories?edit=' + category_id
            category_tax = form(staff.request(category_url)[2], 'tax_rate')
            category_tax.update(tax_rate='3.00', enabled='1', parent_id='')
            check(staff.request('/admin/taxonomy/categories','POST',dict(category_tax,csrf='bad'))[0] == 403, 'Category GST changes require CSRF.')
            check(staff.request('/admin/taxonomy/categories','POST',dict(category_tax,tax_rate='100.01'))[0] == 422, 'Category GST form rejects a rate above 100 percent.')
            check(staff.request('/admin/taxonomy/categories','POST',category_tax)[0] == 303, 'Category GST override saves through the audited catalog service.')
            newer_tax = form(staff.request(category_url)[2], 'tax_rate')
            newer_tax['tax_rate'] = '4.00'
            check(staff.request('/admin/taxonomy/categories','POST',newer_tax)[0] == 303, 'A newer category GST edit persists.')
            check(staff.request('/admin/taxonomy/categories','POST',category_tax)[0] == 303
                  and 'value="4.00"' in staff.request(category_url)[2], 'Replaying an old GST form cannot overwrite the newer category rate.')
            check('<td>5.00%</td>' in guest.request(coupon_order + '/invoice')[2], 'Invoice retains the snapshotted rate after category GST edits.')
            check('PHP Warning' not in log_path.read_text() and 'PHP Fatal' not in log_path.read_text(), 'Shopping requests complete without PHP warnings/fatal errors.')
    finally:
        if process is not None:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)
        if args.mysql:
            run_fixture('cleanup')
print(f"Storefront HTTP ({'mysql' if args.mysql else 'sqlite'}): {checks} checks passed.")
