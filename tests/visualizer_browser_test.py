"""Visual proof for PHP photo editor. Use a disposable Chrome profile on DevTools :9222.
Requires websocket-client; defaults to the running Vayu server at localhost:8000.
"""
import os,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent/'support'))
BASE=os.environ.get('TEST_BASE_URL','http://localhost:8000').rstrip('/')
from browser_client import Browser
import time,json,base64
b=Browser();passed=[]
def check(name,expr):
 value=b.js(expr)
 assert value,(name,value)
 passed.append(name)
def ready():b.wait("!document.querySelector('.photo-stage') || document.querySelector('.photo-stage').getAttribute('aria-busy')!=='true'")
def change(selector,value):
 ready()
 b.js(f"(()=>{{const e=document.querySelector({json.dumps(selector)});e.value={json.dumps(value)};e.dispatchEvent(new Event('change',{{bubbles:true}}));}})()")
def click(s):
 ready()
 b.js(f"document.querySelector({json.dumps(s)}).click()")
b.go(BASE+'/visualizer?room=luxury_living');b.wait("document.querySelector('#photo-status')?.textContent.includes('ready with SAM')")
check('initial floor enabled',"document.querySelector('#photo-enabled').checked")
change('#visual-stone','p_nero_marquina');b.wait("document.querySelector('[data-texture=\"p_nero_marquina\"]').getAttribute('aria-pressed')==='true'")
change('#photo-surface','wall');check('wall starts independent',"!document.querySelector('#photo-enabled').checked && document.querySelector('#visual-stone').value==='p_carrara_white'")
change('#visual-stone','p_calacatta_gold');b.wait("document.querySelector('#photo-enabled').checked")
change('#photo-surface','floor');check('floor selection retained',"document.querySelector('#visual-stone').value==='p_nero_marquina'")
# Pixel comparison against original outside both masks, including sofa and table.
check('furniture pixels preserved',"""(async()=>{const load=src=>new Promise(r=>{const im=new Image();im.onload=()=>r(im);im.src=src;});const src=await load('/assets/storefront/assets/images/rooms/luxury_living.webp'),out=await load(document.querySelector('#photo-preview').toDataURL());const c=document.createElement('canvas');c.width=1000;c.height=640;const x=c.getContext('2d');x.drawImage(src,0,0);const a=x.getImageData(0,0,1000,640).data;x.drawImage(out,0,0);const z=x.getImageData(0,0,1000,640).data;return [[500,490],[500,610],[500,380],[50,50]].every(([u,v])=>{const i=(v*1000+u)*4;return [0,1,2].every(k=>Math.abs(a[i+k]-z[i+k])<=1);});})()""")
baseline=b.js("document.querySelector('#photo-preview').toDataURL()")
change('#photo-tool','erase');b.js("(()=>{const c=document.querySelector('#photo-tools'),r=c.getBoundingClientRect();const e={bubbles:true,pointerId:1,clientX:r.left+r.width*.15,clientY:r.top+r.height*.94};c.dispatchEvent(new PointerEvent('pointerdown',e));c.dispatchEvent(new PointerEvent('pointerup',e));})()")
check('brush changes preview',f"document.querySelector('#photo-preview').toDataURL()!=={json.dumps(baseline)}")
click('#photo-undo');b.wait(f"document.querySelector('#photo-preview').toDataURL()==={json.dumps(baseline)}");passed.append('brush undo restores exact preview')
click('#photo-original');check('original differs from material preview',f"document.querySelector('#photo-preview').toDataURL()!=={json.dumps(baseline)}")
click('#photo-original');check('toggle restores exact preview',f"document.querySelector('#photo-preview').toDataURL()==={json.dumps(baseline)}")
change('#photo-tool','none');click('#save-design');b.wait("document.querySelector('#photo-status').textContent.includes('Design saved')")
b.go(BASE+'/saved-designs');b.wait("document.querySelector('.photo-design-thumb')!==null")
link=b.js("document.querySelector('a[href*=\"saved=\"]').href")
b.go(link);b.wait("document.querySelector('#photo-status')?.textContent==='Saved design restored.'")
check('saved floor restored',"document.querySelector('#visual-stone').value==='p_nero_marquina'")
change('#photo-surface','wall');check('saved wall restored',"document.querySelector('#visual-stone').value==='p_calacatta_gold' && document.querySelector('#photo-enabled').checked")
check('saved preview exact',f"document.querySelector('#photo-preview').toDataURL()==={json.dumps(baseline)}")
check('projective corners map correctly',"""(()=>{const corners=[[.1,.2],[.9,.1],[.8,.9],[.2,.8]],h=RoomPhoto.inversePlane(corners),uv=[[0,0],[1,0],[1,1],[0,1]];return corners.every(([x,y],i)=>{const z=h[2]*x+h[5]*y+h[8];return Math.abs((h[0]*x+h[3]*y+h[6])/z-uv[i][0])<.00001&&Math.abs((h[1]*x+h[4]*y+h[7])/z-uv[i][1])<.00001;});})()""")
check('crossed corners rejected',"(()=>{try{RoomPhoto.inversePlane([[0,0],[1,1],[1,0],[0,1]]);return false;}catch(e){return true;}})()")
check('PNG export requested',"""(async()=>{let name='';const original=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){name=this.download;};try{document.querySelector('#photo-export').click();for(let i=0;i<50&&!name;i++)await new Promise(r=>setTimeout(r,20));return name==='maa-sarada-room.png';}finally{HTMLAnchorElement.prototype.click=original;}})()""")
check('source export requested as PNG',"""(async()=>{let name='';const original=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){name=this.download;};try{await document.querySelector('#photo-source').onclick();for(let i=0;i<50&&!name;i++)await new Promise(r=>setTimeout(r,20));return name==='room-source.png';}finally{HTMLAnchorElement.prototype.click=original;}})()""")
check('positive AI prompt can be exported',"""(async()=>{const tool=document.querySelector('#photo-tool');tool.value='positive';tool.dispatchEvent(new Event('change'));const c=document.querySelector('#photo-tools'),r=c.getBoundingClientRect();c.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,pointerId:1,clientX:r.left+r.width*.12,clientY:r.top+r.height*.94}));let name='';const original=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){name=this.download;};try{document.querySelector('#photo-prompts').click();return name==='room-prompts.json';}finally{HTMLAnchorElement.prototype.click=original;tool.value='none';tool.dispatchEvent(new Event('change'));}})()""")
# Build a manifest from the bundled real SAM masks to exercise the import contract.
check('SAM manifest imports',"""(async()=>{const png=src=>new Promise(resolve=>{const im=new Image();im.onload=()=>{const c=document.createElement('canvas');c.width=im.width;c.height=im.height;c.getContext('2d').drawImage(im,0,0);resolve(c.toDataURL());};im.src=src;});const photo=await png('/assets/storefront/assets/images/rooms/luxury_living.webp'),mask=await png('/assets/storefront/assets/visualizer/floor.png'),bytes=Uint8Array.from(atob(photo.split(',')[1]),c=>c.charCodeAt(0)),hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(v=>v.toString(16).padStart(2,'0')).join('');window.testManifest={version:1,revision:1,photo:{dataUrl:photo,width:1000,height:640,sha256:hash},surfaces:[{id:'floor',label:'floor',mask,corners:[[.28,.76],[.72,.76],[1,1],[0,1]]}]};await document.querySelector('#photo-sam').onchange({target:{files:[new File([JSON.stringify(testManifest)],'result.json',{type:'application/json'})]}});return document.querySelector('#photo-status').textContent.includes('SAM selection imported');})()""")
check('corrupted photo checksum rejected',"""(async()=>{testManifest.photo.sha256='0'.repeat(64);const before=document.querySelector('#photo-preview').toDataURL();await document.querySelector('#photo-sam').onchange({target:{files:[new File([JSON.stringify(testManifest)],'bad.json',{type:'application/json'})]}});return document.querySelector('#photo-status').textContent.includes('checksum')&&document.querySelector('#photo-preview').toDataURL()===before;})()""")
check('invalid upload preserves design',"""(async()=>{const before=document.querySelector('#photo-preview').toDataURL();await document.querySelector('#photo-upload').onchange({target:{files:[new File(['bad'],'bad.txt',{type:'text/plain'})]}});return document.querySelector('#photo-status').textContent.includes('Choose a JPEG')&&document.querySelector('#photo-preview').toDataURL()===before;})()""")
for width in [360,390,768,1440]:
 b.call('Emulation.setDeviceMetricsOverride',{'width':width,'height':1100,'deviceScaleFactor':1,'mobile':False})
 check(f'no overflow at {width}',"document.documentElement.scrollWidth<=innerWidth")
 if width==390:check('preview visible on mobile',"document.querySelector('#photo-preview').getBoundingClientRect().width>250")
check('no runtime errors',str(not b.errors).lower())
print(json.dumps({'passed':len(passed),'checks':passed,'errors':b.errors},indent=2))
