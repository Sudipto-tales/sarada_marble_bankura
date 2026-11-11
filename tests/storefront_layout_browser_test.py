"""Card layout and own-photo processing regression. Uses disposable Chrome DevTools :9222.
Run after visualizer_browser_test.py, not concurrently against the same browser tab.
Requires websocket-client. No live catalog or account data is mutated.
"""
import json, os, subprocess, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent / 'support'))
from browser_client import Browser
BASE = os.environ.get('TEST_BASE_URL', 'http://localhost:8000').rstrip('/')
ROOT = Path(__file__).resolve().parents[2]
b = Browser()
passed = []
def check(name, expression):
    assert b.js(expression), name
    passed.append(name)
def ready():
    b.wait("document.querySelector('.photo-stage')?.getAttribute('aria-busy')==='false'")
def viewport(width):
    b.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1100, 'deviceScaleFactor': 1, 'mobile': False})
def choose(selector, path):
    root = b.call('DOM.getDocument')['root']['nodeId']
    node = b.call('DOM.querySelector', {'nodeId': root, 'selector': selector})['nodeId']
    b.call('DOM.setFileInputFiles', {'nodeId': node, 'files': [str(path)]})
# Render the actual PHP card with adversarial-length public presentation fields.
php = '''class RequestSecurity {public static function csrfToken(){return 'layout-test';}}
$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$u=fn($v='')=>'/' . $e($v);$m=fn($v)=>'INR 99999999.99';
$product=['slug'=>'layout-fixture','id'=>'123','images'=>[],
'name'=>str_repeat('ExtraLongNaturalStoneName',8),
'brand_name'=>str_repeat('BrandWithoutSpaces',7),
'category_name'=>'Premium natural marble and stone finishes for large residential spaces',
'min_price_minor'=>9999999999,'in_stock'=>false];
include 'Website/app/page/storefront/product_card.php';'''
card = subprocess.check_output(['php', '-r', php], cwd=ROOT, text=True)
for width in [360, 390, 600, 768, 1024, 1440]:
    viewport(width)
    b.go(BASE + '/')
    b.wait("document.querySelector('.h-promos')!==null")
    b.js("(()=>{const section=document.querySelector('.h-section');const grid=document.createElement('div');grid.className='stone-grid';grid.id='layout-fixture';grid.innerHTML=" + json.dumps(card * 4) + ";section.append(grid);})()")
    check(f'no page overflow at {width}', 'document.documentElement.scrollWidth<=innerWidth')
    check(f'long card content contained at {width}', """[...document.querySelectorAll('#layout-fixture .stone-card')].every(card=>{
      const c=card.getBoundingClientRect();return card.scrollWidth<=card.clientWidth+1 && [...card.querySelectorAll('h3,.brand-tag,.stone-meta,strong,.note,.card-bottom,.php-card-actions')].every(e=>{
        const r=e.getBoundingClientRect();return r.left>=c.left && r.right<=c.right+1 && r.bottom<=c.bottom;
      });})""")
    check(f'promotional text contained at {width}', "[...document.querySelectorAll('.h-promo')].every(e=>[...e.querySelectorAll('h2,p,a')].every(t=>t.getBoundingClientRect().bottom<=e.getBoundingClientRect().bottom))")
    check(f'readable card type at {width}', "parseFloat(getComputedStyle(document.querySelector('.stone-card h3')).fontSize)>=18 && parseFloat(getComputedStyle(document.querySelector('.stone-card .note')).fontSize)>=14")
    if width <= 390:
        check(f'large mobile cards at {width}', "document.querySelector('.stone-card').getBoundingClientRect().width>=document.documentElement.clientWidth-30")
viewport(1440)
b.go(BASE + '/visualizer?room=luxury_living')
b.wait("document.querySelector('#photo-status')?.textContent.includes('ready with SAM')")
check('room upload visible without expanding details', "document.querySelector('#photo-upload').closest('details')===null && document.querySelector('#photo-upload').getBoundingClientRect().width>100")
# Hold a real texture download to assert the loader spans processing, without a fake UI timer.
b.js("window.originalLoad=RoomPhoto.loadImage; RoomPhoto.loadImage=src=>src.endsWith('/statuario.webp')?new Promise((resolve,reject)=>{window.releaseTexture=()=>originalLoad(src).then(resolve,reject);}):originalLoad(src); document.querySelector('[data-texture=p_statuario]').click();")
b.wait("typeof window.releaseTexture==='function'")
check('loader visible and export blocked during real load', "!document.querySelector('#photo-processing').hidden && document.querySelector('.photo-stage').getAttribute('aria-busy')==='true' && document.querySelector('#photo-export').disabled && document.querySelector('#photo-processing-label').textContent.includes('Placing')")
b.js('window.releaseTexture()');ready()
check('loader clears after material render', "document.querySelector('#visual-stone').value==='p_statuario' && document.querySelector('#photo-processing').hidden && !document.querySelector('#photo-export').disabled")
# A later selection owns the result even when an older texture resolves last.
b.js("RoomPhoto.loadImage=src=>src.endsWith('/imperial_green.webp')?new Promise((resolve,reject)=>{window.releaseOld=()=>originalLoad(src).then(resolve,reject);}):originalLoad(src);document.querySelector('[data-texture=p_imperial_green]').click();")
b.wait("typeof window.releaseOld==='function'")
b.js("document.querySelector('[data-texture=p_nero_marquina]').click()")
ready();b.js('window.releaseOld()')
check('latest material wins over delayed older selection', "(async()=>{await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));return document.querySelector('#visual-stone').value==='p_nero_marquina' && document.querySelector('#photo-processing').hidden;})()")
# Transient texture failure can be retried and never leaves the overlay stuck.
b.js("RoomPhoto.loadImage=src=>src.endsWith('/makrana_white.webp')?Promise.reject(Error('Texture temporarily unavailable')):originalLoad(src);document.querySelector('[data-texture=p_makrana_white]').click()")
b.wait("document.querySelector('#photo-status').textContent.includes('temporarily unavailable')")
check('failed texture preserves prior material and clears loader', "document.querySelector('#visual-stone').value==='p_nero_marquina' && document.querySelector('#photo-processing').hidden")
b.js("RoomPhoto.loadImage=originalLoad;document.querySelector('[data-texture=p_makrana_white]').click()")
ready();check('failed texture can be retried', "document.querySelector('#visual-stone').value==='p_makrana_white'")
room_path = ROOT / 'Website/assets/storefront/assets/images/rooms/premium_bathroom.webp'
choose('#photo-upload', room_path)
b.wait("document.querySelector('#photo-status').textContent.startsWith('Photo ready.')")
check('original room photo selected and labelled', "document.querySelector('#photo-file-name').textContent==='premium_bathroom.webp' && !document.querySelector('#photo-enabled').checked")
# Monitor browser requests: selecting either local photo must never send an HTTP mutation.
b.call('Network.enable')
b.js("window.photoRequests=[];window.originalFetch=fetch;window.fetch=(...args)=>{photoRequests.push(args[0]);return originalFetch(...args);};")
texture_path = ROOT / 'Website/assets/storefront/assets/textures/marble/calacatta_gold.webp'
choose('#photo-texture-upload', texture_path)
b.wait("document.querySelector('#photo-status').textContent==='Material updated.'")
ready()
check('original marble photo applied', "document.querySelector('#visual-stone').value==='custom' && document.querySelector('#photo-texture-name').textContent.includes('calacatta_gold.webp') && document.querySelector('#photo-enabled').checked")
check('local photo processing uses no fetch upload', 'photoRequests.length===0')
preview = b.js("document.querySelector('#photo-preview').toDataURL()")
b.js("document.querySelector('#save-design').click()")
b.wait("document.querySelector('#photo-status').textContent.includes('Design saved')")
b.go(BASE + '/saved-designs');b.wait("document.querySelector('.photo-design-thumb')!==null")
link=b.js("[...document.querySelectorAll('.design-card')].find(c=>c.querySelector('h2').textContent==='premium_bathroom.webp').querySelector('a').href")
b.go(link);b.wait("document.querySelector('#photo-status')?.textContent==='Saved design restored.'")
check('custom marble photo survives saved design restore', "document.querySelector('#visual-stone').value==='custom' && document.querySelector('#photo-texture-name').textContent.includes('calacatta_gold.webp')")
check('custom photo render restored exactly', 'document.querySelector("#photo-preview").toDataURL()===' + json.dumps(preview))
b.js("document.querySelector('[data-texture=p_carrara_white]').click()")
ready();b.js("document.querySelector('#photo-undo').click()")
b.wait("document.querySelector('#photo-status').textContent==='Previous edit restored.'")
check('undo restores uploaded marble photo exactly', 'document.querySelector("#photo-preview").toDataURL()===' + json.dumps(preview))
check('invalid marble photo rejected without losing design', """(async()=>{const before=document.querySelector('#photo-preview').toDataURL();await document.querySelector('#photo-texture-upload').onchange({target:{files:[new File(['bad'],'bad.txt',{type:'text/plain'})]}});return document.querySelector('#photo-status').textContent.includes('Choose a JPEG') && document.querySelector('#photo-preview').toDataURL()===before && document.querySelector('#photo-processing').hidden;})()""")
for width in [360,390,768,1024,1440]:
    viewport(width)
    check(f'photo editor no overflow at {width}', 'document.documentElement.scrollWidth<=innerWidth')
check('no runtime errors', json.dumps(not b.errors))
print(json.dumps({'passed':len(passed),'checks':passed,'errors':b.errors},indent=2))
