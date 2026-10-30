"""Real staff workflows against a disposable database. --browser also checks DataTables/layout.
TEST_PHP_BINARY may point to a wrapper enabling a test-only GD extension.
"""
import argparse, html, http.client, json, os, re, secrets, shutil, signal, socket, subprocess, sys, tempfile, time
from http.cookies import SimpleCookie
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('TEST_PHP_BINARY') or shutil.which('php')
count=0
initial_images=set((ROOT/'assets/products').glob('*.png'))
class Inputs(HTMLParser):
 def __init__(self,body):
  super().__init__();self.forms=[];self.current=None;self.feed(body)
 def handle_starttag(self,tag,attrs):
  attrs=dict(attrs)
  if tag=='form':self.current={'action':attrs.get('action'),'inputs':{}};self.forms.append(self.current)
  if tag=='input' and self.current is not None and 'name' in attrs:
   if attrs.get('type') in ('checkbox','radio') and 'checked' not in attrs:return
   self.current['inputs'][attrs['name']]=attrs.get('value','')
 def handle_endtag(self,tag):
  if tag=='form':self.current=None
class Client:
 def __init__(self,port):self.port=port;self.cookies={}
 def request(self,path,method='GET',data=None,upload=None):
  conn=http.client.HTTPConnection('127.0.0.1',self.port,timeout=20);headers={'Cookie':'; '.join(f'{k}={v}' for k,v in self.cookies.items())};body=None
  if upload:
   boundary='test'+secrets.token_hex(10);parts=[]
   for key,value in data.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
   name,mime,bytes_=upload;parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+bytes_+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode());body=b''.join(parts);headers['Content-Type']='multipart/form-data; boundary='+boundary
  elif data is not None:body=urlencode(data);headers['Content-Type']='application/x-www-form-urlencoded'
  try:
   conn.request(method,path,body,headers);r=conn.getresponse()
   for key,value in r.getheaders():
    if key.lower()=='set-cookie':
     c=SimpleCookie(value)
     for name,item in c.items():self.cookies[name]=item.value
   return r.status,dict(r.getheaders()),r.read().decode(errors='replace')
  finally:conn.close()
def check(condition,message):
 global count
 if not condition:raise AssertionError(message)
 count+=1
def fields(body,action):return next(f['inputs'] for f in Inputs(body).forms if f['action']==action)
def visual_config(client,path='/visualizer'):
 status,_,body=client.request(path)
 assert status==200,(status,body[:300])
 return json.loads(re.search(r'<script id="visualizer-config" type="application/json">(.*?)</script>',body,re.S).group(1))

parser=argparse.ArgumentParser();parser.add_argument('--browser',action='store_true');parser.add_argument('--mysql',action='store_true');args=parser.parse_args()
with tempfile.TemporaryDirectory(prefix='sarada-admin-test-') as directory:
 temp=Path(directory);env=dict(os.environ,APP_ENV='development',APP_DEBUG='false',DB_TYPE='sqlite',DB_DATABASE=str(temp/'db.sqlite'),PRIVATE_STORAGE_PATH=str(temp/'private'),MAIL_TRANSPORT='disabled',CHECKOUT_COD_ENABLED='true',CHECKOUT_SHIPPING_MINOR='0',CHECKOUT_TAX_BPS='0')
 if args.mysql:
  env.update(DB_TYPE='mysql',DB_DATABASE='sarada_admin_test_'+secrets.token_hex(8),DB_HOST=os.getenv('TEST_MYSQL_HOST','127.0.0.1'),DB_PORT=os.getenv('TEST_MYSQL_PORT','3306'),DB_USERNAME=os.getenv('TEST_MYSQL_USER','root'),DB_PASSWORD=os.getenv('TEST_MYSQL_PASSWORD',''))
 fixture=temp/'fixture.php'
 fixture.write_text('''<?php
if(getenv('DB_TYPE')==='mysql'){
 $schema=getenv('DB_DATABASE');if(!preg_match('/\\Asarada_admin_test_[a-f0-9]{16}\\z/',$schema))throw new RuntimeException('Unsafe test schema.');
 $db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if(($argv[1]??'')==='cleanup'){$db->exec('DROP DATABASE `'.$schema.'`');exit;}
 if(($argv[1]??'')==='seed')$db->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
require %s;require %s;
if(($argv[1]??'')==='seed'){
 (new MigrationRunner($pdo,%s,static function($s){}))->run();
 foreach(['admin','viewer','operations'] as $role)$roles[$role]=CommerceAccess::provision($role.'@admin-test.invalid','Disposable-Admin-Test-Password',$role,'Test '.ucfirst($role));
 $pdo->prepare("INSERT INTO users_tbl (firstname,lastname,name,email,password,email_verify,status) VALUES ('Test','Customer','Test Customer',?,?,1,1)")->execute(['customer@admin-test.invalid',password_hash('Disposable-Admin-Test-Password',PASSWORD_DEFAULT)]);
 $customer=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO user_roles (user_id,role_id) SELECT ?,id FROM roles WHERE name='customer'")->execute([$customer]);$service=new ProductService($pdo);$category=$service->saveCategory($roles['admin'],['name'=>'Marble','slug'=>'marble'],'fixture:category');
 for($n=1;$n<=55;$n++)$service->save($roles['admin'],['name'=>'Material '.str_pad($n,3,'0',STR_PAD_LEFT),'slug'=>'material-'.$n,'category_id'=>$category,'status'=>'draft','variants'=>[['sku'=>'FIX-'.$n,'sell_unit'=>'sqft','unit_price_minor'=>10000,'qty_increment_milli'=>1000]]],0,'fixture:product:'.$n);
 echo json_encode(['roles'=>$roles,'customer'=>$customer,'category'=>$category,'gd'=>ProductImageService::available()]);exit;
}
if(($argv[1]??'')==='order'){
 $admin=(int)$pdo->query("SELECT id FROM users_tbl WHERE email='admin@admin-test.invalid'")->fetchColumn();$customer=(int)$pdo->query("SELECT id FROM users_tbl WHERE email='customer@admin-test.invalid'")->fetchColumn();
 $variant=(int)$pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.slug='verified-marble'")->fetchColumn();
 $address=(new AddressService($pdo))->save($customer,['recipient'=>'Test Customer','phone'=>'9999988888','line1'=>'Snapshot Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101']);$cart=(new CartService($pdo))->get('user:'.$customer);$cart=(new CartService($pdo))->change('user:'.$customer,$variant,1000,$cart['revision']);
 echo json_encode((new OrderService($pdo))->place($customer,$cart['id'],$cart['revision'],$address['id'],'cod','fixture:order:place'));exit;
}
if(($argv[1]??'')==='subdirectory'){
 $_SERVER['SCRIPT_NAME']='/stone/index.php';$actor=CommerceAccess::actor((int)$pdo->query("SELECT id FROM users_tbl WHERE email='admin@admin-test.invalid'")->fetchColumn());
 $product=(new ProductService($pdo))->adminDetail($actor['id'],(int)$pdo->query("SELECT id FROM products WHERE slug='verified-marble'")->fetchColumn());$values=AdminCatalogForm::display($product);$taxonomies=(new ProductService($pdo))->taxonomies($actor['id']);$errors=[];$notice=null;
 ob_start();require __BASEDIR__.'/app/page/admin/product.php';$body=ob_get_clean();echo json_encode($body);exit;
}
if(($argv[1]??'')==='inspect'){
 echo json_encode(['products'=>$pdo->query("SELECT id,name,status,revision FROM products WHERE slug='verified-marble'")->fetchAll(),'variant'=>$pdo->query("SELECT v.*,i.on_hand_milli,i.reserved_milli FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.slug='verified-marble'")->fetchAll(),'images'=>$pdo->query('SELECT * FROM product_images')->fetchAll(),'movements'=>$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reason='Received stock'")->fetchColumn(),'history'=>$pdo->query('SELECT * FROM order_status_history')->fetchAll()]);exit;
}
'''%(repr(str(ROOT/'config/bootstarp.php')),repr(str(ROOT/'config/migrate.php')),repr(str(ROOT/'database/migrations'))))
 def run(mode):
  r=subprocess.run([PHP,str(fixture),mode],env=env,cwd=ROOT,text=True,capture_output=True);assert r.returncode==0,r.stdout+r.stderr;return json.loads(r.stdout)
 seed=run('seed');sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();server=None;chrome=None;uploaded_paths=[]
 try:
  server=subprocess.Popen([PHP,'vayu','run','--host=127.0.0.1',f'--port={port}'],env=env,cwd=ROOT,stdout=(temp/'server.log').open('w'),stderr=subprocess.STDOUT,start_new_session=True)
  for _ in range(100):
   try:
    if Client(port).request('/admin/login')[0]==200:break
   except OSError:time.sleep(.05)
  def login(role):
   c=Client(port);status,_,body=c.request('/admin/login');data=fields(body,'/admin/login');data.update(email=role+'@admin-test.invalid',password='Disposable-Admin-Test-Password');result=c.request('/admin/login','POST',data);return c,result
  anonymous=Client(port);check(anonymous.request('/admin')[0]==303,'Anonymous admin request redirects to staff sign-in')
  check(anonymous.request('/admin/data/products')[0]==401,'Anonymous table JSON denied')
  admin,result=login('admin');check(result[0]==303,'Staff login succeeds')
  viewer,result=login('viewer');check(result[0]==303,'View-only login succeeds')
  customer,result=login('customer');check(result[0]==403,'Customer cannot enter staff workspace')
  check(customer.request('/admin/products')[0]==403,'Customer cannot read staff catalog')
  shopper=Client(port);shop=fields(shopper.request('/account/login')[2],'/account/login');shop.update(email='admin@admin-test.invalid',password='Disposable-Admin-Test-Password')
  check(shopper.request('/account/login','POST',shop)[0]==303,'Staff may also shop with the same account')
  check(shopper.request('/admin')[0]==303 and shopper.request('/admin/data/products')[0]==401,'A storefront login carries no staff console authority')
  for module in ['products','categories','brands','inventory','orders','customers']:
   check(admin.request('/admin/'+module)[0]==200,'Admin table renders: '+module)
   check(viewer.request('/admin/'+module)[0]==200,'Viewer table renders: '+module)
  result=json.loads(admin.request('/admin/data/products?length=10&start=50')[2]);check(result['recordsTotal']==55 and len(result['data'])==5,'Server table pages the entire catalog')
  result=json.loads(admin.request('/admin/data/products?'+urlencode({'search[value]':'Material 055'}))[2]);check(result['recordsFiltered']==1 and result['data'][0]['name']=='Material 055','Search finds material beyond first page')
  check(admin.request('/admin/data/products?length=-1')[0]==422,'Unbounded table length rejected')
  check(admin.request('/admin/data/products?order[0][column]=999')[0]==422,'Unsafe sort column rejected')
  result=json.loads(admin.request('/admin/data/products?'+urlencode({'search[value]':"%' OR 1=1 --"}))[2]);check(result['recordsFiltered']==0,'SQL-like search is literal')
  data=fields(admin.request('/admin/categories')[2],'/admin/taxonomy/categories');data.update(name='Floor stones',slug='floor-stones',enabled='1',parent_id='');check(admin.request('/admin/taxonomy/categories','POST',data)[0]==303,'Category form saves')
  check(viewer.request('/admin/taxonomy/categories','POST',data)[0]==403,'Viewer taxonomy mutation denied')
  check(admin.request('/admin/taxonomy/categories','POST',{**data,'csrf':'bad'})[0]==403,'Taxonomy CSRF enforced')
  check(admin.request('/admin/taxonomy/categories','POST',{**data,'operation_key':secrets.token_hex(16)})[0]==409,'Duplicate category rejected')
  category_row=json.loads(admin.request('/admin/data/categories?'+urlencode({'search[value]':'floor-stones'}))[2])['data'][0]
  category_edit=fields(admin.request('/admin/categories?edit='+category_row['id'])[2],'/admin/taxonomy/categories');category_edit.update(name='Floor Stone Collection',slug='floor-stones',enabled='1',parent_id=str(seed['category']))
  check(admin.request('/admin/taxonomy/categories','POST',category_edit)[0]==303,'Category edit persists')
  check(admin.request('/admin/taxonomy/categories','POST',{**category_edit,'operation_key':secrets.token_hex(16),'parent_id':category_row['id']})[0]==422,'Category self-parent cycle rejected')
  data=fields(admin.request('/admin/brands')[2],'/admin/taxonomy/brands');data.update(name='Original Stone',slug='original-stone',enabled='1');check(admin.request('/admin/taxonomy/brands','POST',data)[0]==303,'Brand form saves')
  brand_row=json.loads(admin.request('/admin/data/brands')[2])['data'][0]
  brand_edit=fields(admin.request('/admin/brands?edit='+brand_row['id'])[2],'/admin/taxonomy/brands');brand_edit.update(name='Original Stone Company',slug='original-stone',enabled='1')
  check(admin.request('/admin/taxonomy/brands','POST',brand_edit)[0]==303,'Brand edit persists')
  status,_,body=admin.request('/admin/taxonomy/brands','POST',{**brand_edit,'name':'','operation_key':secrets.token_hex(16)})
  check(status==422 and 'name="slug"' in body and 'value="original-stone"' in body,'Brand validation preserves form input')
  original=fields(admin.request('/admin/product')[2],'/admin/product');data={**original,'name':'Verified marble <script>alert(1)</script>','slug':'verified-marble','description':'Original description','tags':'marble','category_id':str(seed['category']),'brand_id':'','featured':'0','status':'active','variants[0][sku]':'VERIFIED-SQFT','variants[0][finish]':'Polished','variants[0][sell_unit]':'sqft','variants[0][price]':'125.55','variants[0][increment]':'0.5','variants[0][coverage]':'','variants[0][thickness]':'18','variants[0][length]':'','variants[0][width]':'','variants[0][enabled]':'1','variant_count':'1','form_complete':'1'}
  status,_,body=admin.request('/admin/product','POST',{**data,'variants[0][price]':'1e3'});check(status==422 and 'value="1e3"' in body and '&lt;script&gt;' in body,'Invalid price retains escaped form input')
  check(admin.request('/admin/product','POST',{**data,'variants[0][price]':'-1'})[0]==422,'Negative price rejected')
  check(admin.request('/admin/product','POST',{**data,'form_complete':'0'})[0]==422,'Truncated variant form rejected')
  check(admin.request('/admin/product','POST',{**data,'variants[0][sell_unit]':'box'})[0]==422,'Whole-unit increment and coverage required')
  check(admin.request('/admin/product','POST',{**data,'variants[0][sku]':'FIX-1'})[0]==409,'Duplicate SKU rejected atomically')
  check(admin.request('/admin/product','POST',{**data,'variants[0][enabled]':'0'})[0]==422,'Publishing requires an enabled variant')
  check(viewer.request('/admin/product','POST',data)[0]==403,'Viewer product mutation denied')
  check(admin.request('/admin/product','POST',{**data,'csrf':'wrong'})[0]==403,'Product CSRF enforced')
  check(admin.request('/admin/product','POST',data)[0]==303,'Product form persists')
  state=run('inspect');product=state['products'][0];variant=state['variant'][0];pid=str(product['id']);vid=str(variant['id']);uploaded_paths.extend(i['path'] for i in state['images'])
  check(variant['unit_price_minor']==12555 and variant['qty_increment_milli']==500 and variant['thickness_mm_milli']==18000,'Human fields convert to exact integer values')
  public=Client(port)
  for path in ['/','/shop','/category/marble','/product/verified-marble','/calculator']:
   status,_,body=public.request(path);check(status==200 and 'Verified marble' in body,'Admin-created material appears on '+path)
  check(public.request('/product/verified-marble')[2].count('Currently unavailable')==1,'Zero admin stock prevents buying')
  check(not any(m['source']=='catalog' for m in visual_config(public)['materials']),'Products without photos excluded from visualizer')
  check(public.request('/visualizer?product_id='+pid)[0]==404,'Missing material photo produces honest 404')
  check(public.request('/visualizer?product_id[]=1')[0]==422,'Malformed visualizer selection rejected')
  edit=fields(admin.request('/admin/product?id='+pid)[2],'/admin/product');edit.update({k:v for k,v in data.items() if k not in ['id','revision','operation_key']});edit['name']='Changed marble';edit['variants[0][price]']='144.75';check(admin.request('/admin/product','POST',edit)[0]==303,'Product edit persists')
  check(admin.request('/admin/product','POST',edit)[0]==409,'Stale product update rejected')
  for path in ['/','/shop','/category/marble','/product/verified-marble','/calculator']:
   status,_,body=public.request(path);check(status==200 and 'Changed marble' in body and '144.75' in body,'Admin name/price replaces warm storefront data on '+path)
  check('Changed marble' in public.request('/shop?q=Changed')[2],'Store search reads changed admin product')

  foreign={**edit,'revision':str(int(edit['revision'])+1),'operation_key':secrets.token_hex(16),'variants[0][id]':'1'};check(admin.request('/admin/product','POST',foreign)[0]==422,'Foreign variant ownership rejected')
  stock=fields(admin.request('/admin/stock?id='+vid)[2],'/admin/stock');stock.update(quantity='10',reason='Received stock');check(admin.request('/admin/stock','POST',stock)[0]==303,'Stock adjustment persists')
  check(admin.request('/admin/stock','POST',stock)[0]==303 and run('inspect')['movements']==1,'Stock retry changes inventory once')
  check('Available: 10' in public.request('/product/verified-marble')[2],'Stock adjustment refreshes cached public availability')
  check('Changed marble' in public.request('/shop?in_stock=1')[2],'Stock adjustment updates public stock filter')

  check(admin.request('/admin/stock','POST',{**stock,'operation_key':secrets.token_hex(16),'quantity':'2'})[0]==409,'Stale stock adjustment rejected')
  stock=fields(admin.request('/admin/stock?id='+vid)[2],'/admin/stock');check(admin.request('/admin/stock','POST',{**stock,'quantity':'0','reason':'Zero'})[0]==422,'Zero stock delta rejected')
  check(admin.request('/admin/stock','POST',{**stock,'quantity':'0.1','reason':'Bad increment'})[0]==422,'Noncanonical stock increment rejected')
  check(admin.request('/admin/stock','POST',{**stock,'quantity':'-100','reason':'Too low'})[0]==409,'Inventory cannot become negative')
  check(viewer.request('/admin/stock','POST',{**stock,'quantity':'1','reason':'Denied'})[0]==403,'Viewer stock mutation denied')
  order=run('order');oid=str(order['id'])
  gate=fields(admin.request('/admin/order?id='+oid)[2],'/admin/order');gate.update(target='awaiting_verification',reason='Verifying by call');check(admin.request('/admin/order','POST',gate)[0]==303,'Order enters verification gate')
  data=fields(admin.request('/admin/order?id='+oid)[2],'/admin/order');data.update(target='confirmed',reason='Verified order');check(admin.request('/admin/order','POST',data)[0]==303,'Order transition persists')
  check(admin.request('/admin/order','POST',data)[0]==303,'Order transition retry idempotent')
  status,_,body=customer.request('/order/'+oid);check(status==200 and 'Confirmed' in body and 'Verified order' in body,'Admin confirmation reaches customer order timeline')
  check('confirmed' in customer.request('/orders')[2],'Admin status reaches customer order list')

  check(admin.request('/admin/order','POST',{**data,'operation_key':secrets.token_hex(16)})[0]==409,'Stale order transition rejected')
  data=fields(admin.request('/admin/order?id='+oid)[2],'/admin/order');check(admin.request('/admin/order','POST',{**data,'target':'delivered','reason':'Skip'})[0]==409,'Invalid fulfillment jump rejected')
  data.update(target='cancelled',reason='Customer requested cancellation');check(admin.request('/admin/order','POST',data)[0]==303,'Cancellation persists')
  check(admin.request('/admin/order','POST',data)[0]==303,'Cancellation retry idempotent')
  status,_,body=customer.request('/order/'+oid);check(status==200 and 'Customer requested cancellation' in body and 'ancelled' in body,'Admin cancellation reaches customer timeline')
  check('Available: 10' in public.request('/product/verified-marble')[2],'Order cancellation restores public stock availability')

  check(run('inspect')['variant'][0]['on_hand_milli']==10000,'Cancellation restores consumed stock exactly once')
  result=json.loads(admin.request('/admin/data/customers')[2]);check(result['recordsTotal']==1 and set(result['data'][0])=={'id','name','email','status','email_verify'},'Customer projection excludes staff and secrets')
  check(admin.request('/admin/settings')[0]==200,'Effective settings render')
  operations,result=login('operations');check(result[0]==303,'Operations staff login succeeds')
  check(operations.request('/admin/products')[0]==403 and operations.request('/admin/data/products')[0]==403,'Operations staff cannot access catalog grants')
  check(operations.request('/admin')[0]==200 and 'href="/admin/products"' not in operations.request('/admin')[2],'Navigation hides ungranted modules')
  check(admin.request('/admin/stock','POST',{**stock,'quantity':'1','reason':'Denied','csrf':'bad'})[0]==403,'Stock CSRF enforced')
  check(admin.request('/admin/order','POST',{**data,'csrf':'bad'})[0]==403,'Order CSRF enforced')
  data=fields(admin.request('/admin/product?id='+pid)[2],'/admin/product');image_data={'csrf':data['csrf'],'id':pid,'operation_key':secrets.token_hex(16),'alt_text':'Original marble','position':'0','variant_id':vid}
  check(admin.request('/admin/product/image','POST',{**image_data,'csrf':'bad'},('photo.png','image/png',b'bad'))[0]==403,'Image CSRF enforced')
  probe=ROOT/'assets/products'/('execution-test-'+secrets.token_hex(8)+'.php');probe.write_text('<?php echo \"must-not-execute\";')
  try:check(admin.request('/assets/products/'+probe.name)[0]==404,'Vayu denies execution in product upload directory')
  finally:probe.unlink()
  cleanup=subprocess.run([PHP,'vayu','catalog:images:clean'],env=env,cwd=ROOT,capture_output=True,text=True);check(cleanup.returncode==0 and json.loads(cleanup.stdout)['removed']==0,'Orphan maintenance defaults to bounded dry-run')
  if seed['gd']:
   png=(ROOT/'assets/storefront/assets/catalogs/kasamood/cover.png').read_bytes()
   check(admin.request('/admin/product/image','POST',image_data,('photo.png','image/png',png))[0]==303,'Safe original image uploads')
   state=run('inspect');uploaded_paths.extend(i['path'] for i in state['images']);image=state['images'][0]
   check(image['path'].startswith('assets/products/') and image['mime']=='image/png','Generated image metadata persisted')
   check(admin.request('/'+image['path'])[0]==200,'Reencoded image served as static asset')
   for path in ['/','/shop','/product/verified-marble']:
    check('/'+image['path'] in public.request(path)[2],'Uploaded admin photo replaces warm storefront gallery on '+path)
   config=visual_config(public,'/visualizer?product_id='+pid);material=next(m for m in config['materials'] if m['id']=='store_'+pid)
   check(material['textureUrl']=='/'+image['path'] and material['detailUrl']=='/product/verified-marble' and config['selectedMaterial']==material['id'],'Visualizer uses original published admin photo and details URL')
   check('visualizer?product_id='+pid in public.request('/product/verified-marble')[2],'Product page links to selected visualizer material')
   if args.browser:
    sys.path.insert(0,str(ROOT/'tests/support'));from browser_client import Browser
    chrome=subprocess.Popen([shutil.which('google-chrome'),'--headless=new','--no-sandbox','--enable-unsafe-swiftshader','--use-angle=swiftshader','--remote-debugging-port=9222','--user-data-dir='+str(temp/'visualizer-chrome'),'about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(100):
     try:b=Browser();break
     except Exception:time.sleep(.1)
    b.go(f'http://127.0.0.1:{port}/visualizer?product_id='+pid);b.wait("document.querySelector('#photo-stage')!==null || document.querySelector('.photo-stage')!==null")
    b.wait("document.querySelector('#photo-status')?.textContent.includes('ready')")
    check(b.js("document.querySelector('#visual-stone').value==='store_"+pid+"'"),'Browser selects real admin material')
    check(b.js("document.querySelector('#visual-details').getAttribute('href')==='/product/verified-marble'"),'Browser links selected material back to website')
    b.js("document.querySelector('[data-texture=store_"+pid+"] img').scrollIntoView()")
    b.wait("document.querySelector('[data-texture=store_"+pid+"] img').complete && document.querySelector('[data-texture=store_"+pid+"] img').naturalWidth>0")
    check(b.js("document.querySelector('[data-texture=store_"+pid+"] img').complete && document.querySelector('[data-texture=store_"+pid+"] img').naturalWidth>0"),'Browser renders uploaded swatch photo')
    check(not b.errors,'Real catalog visualizer has no runtime errors')
    b.call('Browser.close');chrome.wait(timeout=10);chrome=None

   image_data['operation_key']=secrets.token_hex(16)
   check(admin.request('/admin/product/image','POST',image_data,('evil.png','image/png',b'<?php echo 1;?>'))[0]==422,'Disguised executable rejected')
   check(admin.request('/admin/product/image','POST',image_data,('evil.svg','image/svg+xml',b'<svg/>'))[0]==422,'SVG upload rejected')
   image_data['variant_id']='1';check(admin.request('/admin/product/image','POST',image_data,('photo.png','image/png',png))[0]==422,'Foreign image variant rolls back')
   check(len(set((ROOT/'assets/products').glob('*.png'))-initial_images)==len(uploaded_paths),'Failed metadata write leaves no new image')
   image_data.update(action='remove',image_id=str(image['id']),variant_id=vid,operation_key=secrets.token_hex(16));check(admin.request('/admin/product/image','POST',image_data)[0]==303,'Image removal wired')
   check(not (ROOT/image['path']).exists(),'Removed image cleaned after commit')
   check(not any(m['source']=='catalog' for m in visual_config(public)['materials']),'Removed admin photo disappears from visualizer')
   check('Preview in your room' not in public.request('/product/verified-marble')[2],'Removed image refreshes public gallery and preview link')

  else:
   check('PHP GD' in admin.request('/admin/product?id='+pid)[2],'Missing decoder shown honestly')
   check(admin.request('/admin/product/image','POST',image_data,('photo.png','image/png',b'bad'))[0]==503,'Missing decoder disables unsafe uploads')
  if args.browser:
   sys.path.insert(0,str(ROOT/'tests/support'));from browser_client import Browser
   chrome=subprocess.Popen([shutil.which('google-chrome'),'--headless=new','--no-sandbox','--enable-unsafe-swiftshader','--use-angle=swiftshader','--remote-debugging-port=9222','--user-data-dir='+str(temp/'chrome'),'about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   for _ in range(100):
    try:b=Browser();break
    except Exception:time.sleep(.1)
   base=f'http://127.0.0.1:{port}';b.call('Emulation.setDeviceMetricsOverride',{'width':1440,'height':1100,'deviceScaleFactor':1,'mobile':False});b.go(base+'/admin/login');b.wait("document.querySelector('input[name=email]')!==null")
   b.js("document.querySelector('[name=email]').value='admin@admin-test.invalid';document.querySelector('[name=password]').value='Disposable-Admin-Test-Password';document.querySelector('form').requestSubmit()")
   b.wait("document.querySelector('.welcome-panel')!==null")
   b.shot('/tmp/sarada-admin-overview.png')
   b.go(base+'/admin/products');b.wait("window.adminTable?.page.info().recordsTotal===56")
   b.js("void adminTable.search('Material 055').draw()")
   b.wait("adminTable.page.info().recordsDisplay===1")
   check(b.js("document.querySelector('#records-table tbody').textContent.includes('Material 055')"),'Real DataTables searches beyond first page')
   b.js("void adminTable.search('').draw()");b.wait('adminTable.page.info().recordsDisplay===56');b.js('void adminTable.page(2).draw(false)');b.wait("adminTable.page.info().page===2 && adminTable.rows({page:'current'}).count()===16");check(b.js("adminTable.rows({page:'current'}).count()===16"),'DataTables pagination works')
   b.shot('/tmp/sarada-admin-products.png')
   for width in [360,390,768,1024,1440]:
    b.call('Emulation.setDeviceMetricsOverride',{'width':width,'height':1100,'deviceScaleFactor':1,'mobile':False})
    for route in ['/admin','/admin/products','/admin/categories','/admin/brands','/admin/inventory','/admin/orders','/admin/customers','/admin/settings','/admin/product?id='+pid,'/admin/stock?id='+vid,'/admin/order?id='+oid]:
     b.go(base+route);b.wait("document.querySelector('#admin-main')!==null");check(b.js('document.documentElement.scrollWidth<=innerWidth'),f'No page overflow at {width}: {route}')
   b.call('Emulation.setDeviceMetricsOverride',{'width':390,'height':1100,'deviceScaleFactor':1,'mobile':False});b.go(base+'/admin');b.wait("document.querySelector('.drawer-toggle')!==null")
   b.js("document.querySelector('.drawer-toggle').click()");check(b.js("!document.querySelector('.sidebar').inert && document.querySelector('.drawer-toggle').getAttribute('aria-expanded')==='true'"),'Mobile drawer opens accessibly')
   b.js("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))");check(b.js("document.querySelector('.sidebar').inert && document.querySelector('.drawer-toggle').getAttribute('aria-expanded')==='false'"),'Escape closes drawer')
   b.shot('/tmp/sarada-admin-mobile.png')
   b.go(base+'/admin/product');b.wait("document.querySelector('#add-variant')!==null");b.js("document.querySelector('#add-variant').click()");check(b.js("document.querySelectorAll('.variant-row').length===2 && document.querySelector('#variant-count').value==='2'"),'Variant add wired')
   b.js("document.querySelector('.remove-variant').click()");check(b.js("document.querySelectorAll('.variant-row').length===1 && document.querySelector('#variant-count').value==='1' && !!document.querySelector('[name=\"variants[0][sku]\"]')"),'Variant remove renumbers safely')
   b.js("document.querySelector('.variant-unit').value='box';document.querySelector('.variant-unit').dispatchEvent(new Event('change',{bubbles:true}))");check(b.js("document.querySelector('[name=\"variants[0][coverage]\"]').required && document.querySelector('[name=\"variants[0][increment]\"]').step==='1'"),'Box fields require whole increments and coverage')
   check(b.js("!document.querySelector('#product-form').checkValidity()"),'Empty required product fields fail browser validation')
   check(not b.errors,'Admin has no JavaScript runtime errors');b.call('Browser.close')
  subdirectory=run('subdirectory')
  check('href="/stone/assets/admin/admin.css"' in subdirectory,'Subdirectory render prefixes admin assets')
  check('action="/stone/admin/product"' in subdirectory,'Subdirectory render prefixes mutation forms')
  check('href="/stone/admin/products"' in subdirectory,'Subdirectory render prefixes navigation')
  archive=fields(admin.request('/admin/product?id='+pid)[2],'/admin/product/archive');check(admin.request('/admin/product/archive','POST',archive)[0]==303,'Product archive wired')
  check(run('inspect')['products'][0]['status']=='archived','Archived state persisted')
  check(public.request('/product/verified-marble')[0]==404,'Admin archive removes published detail')
  check('Changed marble' not in public.request('/shop')[2] and 'Changed marble' not in public.request('/')[2],'Admin archive removes warm home/shop listings')

  print(json.dumps({'passed':count,'driver':'mysql' if args.mysql else 'sqlite','gd':seed['gd'],'browser':args.browser},indent=2))
 finally:
  if chrome and chrome.poll() is None:chrome.terminate();chrome.wait(timeout=10)
  if server and server.poll() is None:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
  if args.mysql:
   result=subprocess.run([PHP,str(fixture),'cleanup'],env=env,cwd=ROOT,capture_output=True,text=True)
   assert result.returncode==0,'Disposable MySQL cleanup failed'
  for path in uploaded_paths:
   p=ROOT/path
   if p.is_file():p.unlink()
