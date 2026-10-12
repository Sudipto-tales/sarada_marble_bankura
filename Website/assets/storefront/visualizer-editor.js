/* Local proof: photo/mask editing and IndexedDB designs, no remote photo upload. */
(() => {
  'use strict';
  const config=JSON.parse(document.querySelector('#visualizer-config').textContent), assetBase=config.assets, visualizerUrl=config.visualizer, savedUrl=config.saved;
  const R=window.RoomPhoto, clone=x=>JSON.parse(JSON.stringify(x));
  const $=(root,s)=>root.querySelector(s);
  function database(){return new Promise((resolve,reject)=>{const request=indexedDB.open(config.storageScope,1);request.onupgradeneeded=()=>request.result.createObjectStore('designs',{keyPath:'id'});request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(Error('Browser storage is unavailable.'));});}
  async function store(action,value){const db=await database();return new Promise((resolve,reject)=>{const tx=db.transaction('designs',action==='get'||action==='all'?'readonly':'readwrite'),s=tx.objectStore('designs');let request;if(action==='get')request=s.get(value);else if(action==='all')request=s.getAll();else if(action==='put')request=s.put(value);else request=s.delete(value);tx.oncomplete=()=>{db.close();resolve(request.result);};tx.onerror=()=>{db.close();reject(Error('Could not save the design. Browser storage may be full.'));};tx.onabort=tx.onerror;});}
  function download(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
  function defaultState(id,product,corners){return {id,label:id,product,corners,enabled:false,repeat:4,rotation:0,grout:.006,shade:.35};}
  async function mount({main,products,rooms,params,escape:esc,toast}){
    const first=products.find(p=>p.id===(config.selectedMaterial||params.get('id')))||products[0];
    const textureUrl=p=>p.textureUrl||`${assetBase}assets/textures/marble/${p.textureId}.webp`;
    const thumbnailUrl=p=>p.thumbnailUrl||`${assetBase}assets/textures/marble/${p.textureId}_thumb.webp`;
    main.innerHTML=`<section class="page-intro"><div><span class="eyebrow">ROOM VISUALIZER</span><h1>See your stone at home.</h1><p>Preview published store materials using their original product photos, or try a labelled sample. Open material details for current prices and availability.</p></div><a class="button secondary" href="${savedUrl}">Saved designs ↗</a></section>
      <section class="photo-upload-card"><div><h2>Start with your original room photo</h2><p>Choose a photo from your device, or try a room below.</p></div><label class="button photo-upload-button" for="photo-upload">Choose room photo<input id="photo-upload" type="file" accept="image/jpeg,image/png,image/webp"></label><p id="photo-file-name" class="note">JPEG, PNG or WebP · up to 12 MB. Your photo stays on this device.</p></section>
      <div class="room-picker" aria-label="Choose a room">${rooms.map(([id,name])=>`<button data-photo-room="${id}" aria-pressed="false"><img src="${assetBase}assets/images/rooms/${id}_thumb.webp" alt=""><span>${esc(name)}</span></button>`).join('')}</div>
      <div class="visualizer-layout photo-editor"><section><div class="photo-stage"><canvas id="photo-preview" aria-label="Room material preview"></canvas><canvas id="photo-tools" aria-label="Surface selection and perspective editor"></canvas><div id="photo-processing" class="photo-processing" hidden><span class="photo-spinner" aria-hidden="true"></span><strong id="photo-processing-label">Preparing preview…</strong><span>Aligning the material with your selected surface.</span></div></div>
      <p id="photo-status" role="status" aria-live="polite">Loading room…</p><div class="photo-actions"><button class="button secondary" id="photo-original" aria-pressed="false">Show original</button><button class="button secondary" id="photo-export">Download image ↗</button><button class="button secondary" id="photo-undo">Undo edit</button></div>
      <details class="photo-import"><summary>Advanced: import an AI surface selection</summary><p class="note">Your photo stays in this browser. Mark the surface manually, or import a selection prepared with SAM 2.</p><label class="field">Prepared SAM selection<input id="photo-sam" type="file" accept="application/json,.json"></label><div class="photo-actions"><button id="photo-prompts" class="button secondary">Download selection prompts</button><button id="photo-source" class="button secondary">Download source photo</button></div><p class="note">AI selection is currently prepared locally with the Python runner. Click prompts alone do not run AI.</p></details></section>
      <aside class="panel visual-controls"><h2>Make it yours.</h2><label class="field">Surface<select id="photo-surface"><option value="floor">Floor</option><option value="wall">Feature wall</option></select></label><label class="photo-enable"><input id="photo-enabled" type="checkbox"> Apply material to this surface</label>
      <label class="field">Material<select id="visual-stone">${['catalog','sample'].map(source=>`<optgroup label="${source==='catalog'?'Store materials':'Planning samples'}">${products.filter(p=>(p.source||'sample')===source).map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')}</optgroup>`).join('')}</select></label><div class="swatch-grid">${products.map(p=>`<button data-texture="${p.id}" aria-label="${esc(p.name)}" aria-pressed="false"><img src="${esc(thumbnailUrl(p))}" alt="" loading="lazy"></button>`).join('')}</div>
      <label class="field photo-texture-upload">Or use your original marble photo<input id="photo-texture-upload" type="file" accept="image/jpeg,image/png,image/webp"><span class="note" id="photo-texture-name">Choose a clear, straight-on photo of the marble surface. Up to 12 MB; processed on this device.</span></label>
      <label class="field">Pattern repeats <output id="photo-repeat-value"></output><input id="photo-repeat" type="range" min="1" max="12" step=".25"></label><label class="field">Rotation <input id="photo-rotation" type="range" min="0" max="180" step="15"></label><label class="field">Grout width<input id="photo-grout" type="range" min="0" max=".03" step=".001"></label><label class="field">Room shading<input id="photo-shade" type="range" min="0" max="1" step=".05"></label>
      <details open><summary>Adjust surface</summary><label class="field">Editing tool<select id="photo-tool"><option value="none">Preview</option><option value="corners">Perspective corners</option><option value="erase">Erase selection</option><option value="restore">Restore selection</option><option value="positive">AI: include point</option><option value="negative">AI: exclude point</option></select></label><p class="note">Drag the four corners to follow the surface. Erase furniture from the selection. Pattern scale is illustrative until measurements are supplied.</p><div id="photo-corner-inputs" class="photo-corner-inputs"></div><button class="text-button" id="photo-reset-mask">Reset selection to corners</button></details>
      <button class="button" id="save-design">Save photo design ♡</button><a class="button secondary" id="visual-details">Browse store materials ↗</a><p class="note">Designs stay in this browser. Download an image to keep a separate copy.</p></aside></div>`;
    const canvas=$(main,'#photo-preview'),overlay=$(main,'#photo-tools'),status=$(main,'#photo-status');let renderer;
    try{renderer=new R.PhotoRenderer(canvas);}catch(e){status.textContent=e.message;return;}
    let source='',room='',name='',active='floor',operation=0,textureRevision=0,loading=true,materialBusy=false,revision=1,prompts={},undo=[],drag=null,original=false;
    const textureCache=new Map();
    function texture(id,dataUrl){if(dataUrl){if(!/^data:image\/(png|jpeg|webp);base64,/.test(dataUrl))return Promise.reject(Error('Invalid saved marble photo.'));return R.loadImage(dataUrl);}const p=products.find(p=>p.id===id)||first;if(!textureCache.has(p.id))textureCache.set(p.id,R.loadImage(textureUrl(p)).catch(error=>{textureCache.delete(p.id);throw error;}));return textureCache.get(p.id);}
    function selected(){return renderer.surfaces.find(s=>s.state.id===active);}
    function message(text){status.textContent=text;}
    // Yield a frame before expensive canvas/GPU work so the progress UI can paint.
    const paint=()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    function busy(text){
      const stage=$(main,'.photo-stage');stage.setAttribute('aria-busy',String(Boolean(text)));
      $(main,'#photo-processing').hidden=!text;
      if(text){$(main,'#photo-processing-label').textContent=text;message(text);drag=null;}
      ['#save-design','#photo-export','#photo-undo','#photo-original','#photo-surface','#photo-enabled','#photo-repeat','#photo-rotation','#photo-grout','#photo-shade'].forEach(id=>$(main,id).disabled=Boolean(text));
    }
    async function photoData(file){
      if(file.size>12*1024*1024||!['image/jpeg','image/png','image/webp'].includes(file.type))throw Error('Choose a JPEG, PNG or WebP under 12 MB.');
      const url=URL.createObjectURL(file);let img;
      try{img=await R.loadImage(url);}finally{URL.revokeObjectURL(url);}
      if(img.width*img.height>24e6)throw Error('Choose an image under 24 megapixels.');
      const scale=Math.min(1,1536/Math.max(img.width,img.height)),normalized=R.canvasFor(Math.max(1,Math.round(img.width*scale)),Math.max(1,Math.round(img.height*scale)));
      normalized.getContext('2d').drawImage(img,0,0,normalized.width,normalized.height);
      return normalized.toDataURL('image/png');
    }
    function remember(){undo.push(renderer.surfaces.map(s=>({state:clone(s.state),mask:s.mask.toDataURL()})));if(undo.length>15)undo.shift();}
    function drawTools(){
      const ctx=overlay.getContext('2d');ctx.clearRect(0,0,overlay.width,overlay.height);const s=selected();if(!s)return;
      const tool=$(main,'#photo-tool').value;if(tool==='none'||original)return;
      const visible=R.canvasFor(overlay.width,overlay.height),v=visible.getContext('2d');v.drawImage(s.mask,0,0);const data=v.getImageData(0,0,visible.width,visible.height);for(let i=0;i<data.data.length;i+=4){const alpha=data.data[i]*.3;data.data[i]=100;data.data[i+1]=190;data.data[i+2]=170;data.data[i+3]=alpha;}v.putImageData(data,0,0);ctx.drawImage(visible,0,0);
      ctx.lineWidth=2;ctx.strokeStyle='#ffd789';ctx.fillStyle='#ffd789';ctx.beginPath();s.state.corners.forEach(([x,y],i)=>i?ctx.lineTo(x*overlay.width,y*overlay.height):ctx.moveTo(x*overlay.width,y*overlay.height));ctx.closePath();ctx.stroke();
      s.state.corners.forEach(([x,y],i)=>{ctx.beginPath();ctx.arc(x*overlay.width,y*overlay.height,8,0,Math.PI*2);ctx.fill();ctx.fillStyle='#17251a';ctx.font='bold 12px sans-serif';ctx.fillText(String(i+1),x*overlay.width-3,y*overlay.height+4);ctx.fillStyle='#ffd789';});
      (prompts[active]||[]).forEach(p=>{ctx.fillStyle=p.label?'#26eb8a':'#ff6b6b';ctx.beginPath();ctx.arc(p.xy[0]*overlay.width,p.xy[1]*overlay.height,6,0,Math.PI*2);ctx.fill();});
    }
    function render(){renderer.original=original;renderer.render();drawTools();}
    function sync(){
      const s=selected();if(!s)return;const st=s.state,p=products.find(p=>p.id===st.product)||first;
      $(main,'#photo-surface').value=active;$(main,'#photo-enabled').checked=st.enabled;const materialSelect=$(main,'#visual-stone');materialSelect.querySelector('[value="custom"]')?.remove();
      if(st.textureSource){const option=document.createElement('option');option.value='custom';option.textContent='Your marble photo';materialSelect.append(option);}
      materialSelect.value=st.textureSource?'custom':p.id;
      $(main,'#photo-texture-name').textContent=st.textureSource?`Your marble: ${st.textureName||'Uploaded photo'}`:'Choose a clear, straight-on photo of the marble surface. Up to 12 MB; processed on this device.';
      ['repeat','rotation','grout','shade'].forEach(k=>$(main,'#photo-'+k).value=st[k]);$(main,'#photo-repeat-value').textContent=st.repeat;
      const details=$(main,'#visual-details');details.href=!st.textureSource&&p.detailUrl?p.detailUrl:config.shop;
      details.textContent=!st.textureSource&&p.detailUrl?'View material details ↗':'Browse store materials ↗';
      main.querySelectorAll('[data-texture]').forEach(b=>b.setAttribute('aria-pressed',String(!st.textureSource&&b.dataset.texture===p.id)));
      main.querySelectorAll('[data-photo-room]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.photoRoom===room)));
      $(main,'#photo-corner-inputs').innerHTML=st.corners.map(([x,y],i)=>`<label>Corner ${i+1}<span><input type="number" min="0" max="1" step=".005" value="${x}" data-corner="${i}" data-axis="0" aria-label="Corner ${i+1} horizontal position"><input type="number" min="0" max="1" step=".005" value="${y}" data-corner="${i}" data-axis="1" aria-label="Corner ${i+1} vertical position"></span></label>`).join('');render();
    }
    async function load(photo,states,meta={}){
      const ticket=++operation;textureRevision++;materialBusy=false;loading=true;busy('Preparing room…');await paint();
      try{
        const img=await R.loadImage(photo);if(img.width>1536||img.height>1536)throw Error('Photo must be at most 1536 pixels on each side.');
        const prepared=await Promise.all(states.map(async st=>{R.inversePlane(st.corners);const mask=st.mask?await R.loadImage(st.mask):R.polygonMask(img.width,img.height,st.corners);if(mask.width!==img.width||mask.height!==img.height)throw Error('Mask dimensions do not match the photo.');return {st,mask,stone:await texture(st.product,st.textureSource)};}));
        if(ticket!==operation)return;
        renderer.clearSurfaces();renderer.setPhoto(img);overlay.width=img.width;overlay.height=img.height;
        prepared.forEach(({st,mask,stone})=>{const copy=R.canvasFor(img.width,img.height);copy.getContext('2d').drawImage(mask,0,0);const state=clone(st);delete state.mask;renderer.addSurface(state,copy,stone);});
        source=photo;room=meta.room||'';name=meta.name||'Your room';active=states.some(s=>s.id===meta.active)?meta.active:states[0].id;prompts={};undo=[];revision=meta.revision||1;original=false;$(main,'#photo-original').textContent='Show original';$(main,'#photo-original').setAttribute('aria-pressed','false');
        const select=$(main,'#photo-surface');select.innerHTML='';states.forEach(s=>{const o=document.createElement('option');o.value=s.id;o.textContent=s.label==='floor'?'Floor':`Wall${s.id==='wall'?'':' · '+s.id}`;select.append(o);});
        sync();await paint();if(ticket!==operation)return;loading=false;busy(null);message(meta.message||`${name} ready. Adjust the selection and perspective as needed.`);
      }catch(e){if(ticket===operation){loading=false;busy(null);message(e.message);}}
    }
    async function preset(id){
      const r=rooms.find(r=>r[0]===id)||rooms[0];
      if(['luxury_living','sunlit_living'].includes(r[0])){
        const ticket=++operation;textureRevision++;materialBusy=false;loading=true;busy('Loading room selection…');
        try{const response=await fetch(`${assetBase}assets/visualizer/${r[0]==='sunlit_living'?'sunlit-room':'living-room'}.json`);if(!response.ok)throw Error('Preset could not be loaded.');const data=await response.json();if(ticket!==operation)return;await load(`${assetBase}assets/images/rooms/${r[0]}.webp`,data.surfaces.map(s=>({...defaultState(s.id,first.id,s.corners),mask:`${assetBase}assets/visualizer/${s.mask}`,enabled:s.id==='floor'})),{room:r[0],name:r[1],active:params.get('surface'),message:`${r[1]} ready with SAM 2 selections. Review shadow edges and correct perspective as needed.`});}catch(e){if(ticket===operation){loading=false;busy(null);message(e.message);}}
      }else await load(`${assetBase}assets/images/rooms/${r[0]}.webp`,[defaultState('floor',first.id,[[.25,.7],[.75,.7],[1,1],[0,1]]),defaultState('wall',first.id,[[.25,.2],[.75,.2],[.75,.7],[.25,.7]])],{room:r[0],name:r[1],message:'Mark the floor or wall before applying a material. This room has no prepared AI selection.'});
    }
    async function applyMaterial(id,file){
      if(loading||id==='custom')return;
      const s=selected(),ticket=++textureRevision,op=operation;if(!s)return;
      materialBusy=true;busy(file?'Preparing your marble photo…':'Placing marble on your surface…');
      try{
        await paint();
        const dataUrl=file?await photoData(file):null,image=await texture(id,dataUrl);
        if(ticket!==textureRevision||op!==operation||s!==selected())return;
        remember();renderer.setStone(s,image);s.state.product=id;s.state.enabled=true;
        if(file){s.state.textureSource=dataUrl;s.state.textureName=file.name;}else{delete s.state.textureSource;delete s.state.textureName;}
        // Keep "Show original" meaningful, but return to the result when selecting a material.
        original=false;$(main,'#photo-original').textContent='Show original';$(main,'#photo-original').setAttribute('aria-pressed','false');
        sync();await paint();
        if(ticket===textureRevision&&op===operation)message('Material updated.');
      }catch(e){if(ticket===textureRevision&&op===operation){sync();message(e.message);}}
      finally{if(ticket===textureRevision&&op===operation){materialBusy=false;busy(null);}}
    }
    const applyProduct=id=>applyMaterial(id);
    main.querySelectorAll('[data-texture]').forEach(button=>button.onclick=()=>applyProduct(button.dataset.texture));
    main.querySelectorAll('[data-photo-room]').forEach(button=>button.onclick=()=>preset(button.dataset.photoRoom));
    $(main,'#photo-texture-upload').onchange=async e=>{const file=e.target.files[0];if(file)await applyMaterial(first.id,file);e.target.value='';};
    $(main,'#visual-stone').onchange=e=>applyProduct(e.target.value);
    $(main,'#photo-surface').onchange=e=>{if(loading||materialBusy)return;textureRevision++;active=e.target.value;sync();};
    $(main,'#photo-enabled').onchange=e=>{if(loading||materialBusy)return;remember();selected().state.enabled=e.target.checked;render();};
    ['repeat','rotation','grout','shade'].forEach(k=>{const input=$(main,'#photo-'+k);input.onpointerdown=()=>{if(!loading&&!materialBusy)remember();};input.onkeydown=()=>{if(!loading&&!materialBusy)remember();};input.oninput=()=>{if(loading||materialBusy)return;selected().state[k]=Number(input.value);$(main,'#photo-repeat-value').textContent=selected().state.repeat;render();};});
    $(main,'#photo-tool').onchange=()=>{render();};
    $(main,'#photo-original').onclick=()=>{if(loading||materialBusy)return;original=!original;$(main,'#photo-original').textContent=original?'Show materials':'Show original';$(main,'#photo-original').setAttribute('aria-pressed',String(original));render();};
    $(main,'#photo-reset-mask').onclick=()=>{if(loading||materialBusy)return;remember();const s=selected();s.mask.getContext('2d').drawImage(R.polygonMask(canvas.width,canvas.height,s.state.corners),0,0);renderer.updateMask(s);render();message('Selection reset. Erase furniture and fixtures before saving.');};
    $(main,'#photo-corner-inputs').onchange=e=>{if(loading||materialBusy||!e.target.matches('[data-corner]'))return;const s=selected(),next=clone(s.state.corners);next[Number(e.target.dataset.corner)][Number(e.target.dataset.axis)]=Number(e.target.value);try{R.inversePlane(next);remember();s.state.corners=next;sync();}catch(error){message(error.message);sync();}};
    function position(event){const rect=overlay.getBoundingClientRect();return [Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height))];}
    function brush(point,last){const s=selected(),ctx=s.mask.getContext('2d');ctx.strokeStyle=ctx.fillStyle=$(main,'#photo-tool').value==='erase'?'#000':'#fff';ctx.lineWidth=canvas.width*.035;ctx.lineCap='round';ctx.beginPath();ctx.moveTo((last||point)[0]*canvas.width,(last||point)[1]*canvas.height);ctx.lineTo(point[0]*canvas.width,point[1]*canvas.height);ctx.stroke();ctx.beginPath();ctx.arc(point[0]*canvas.width,point[1]*canvas.height,ctx.lineWidth/2,0,Math.PI*2);ctx.fill();renderer.updateMask(s);render();}
    overlay.onpointerdown=e=>{if(loading||materialBusy||original)return;const tool=$(main,'#photo-tool').value,point=position(e);if(tool==='none')return;e.preventDefault();overlay.setPointerCapture(e.pointerId);
      if(tool==='positive'||tool==='negative'){prompts[active]||=[];if(prompts[active].length>=64){message('Use at most 64 points per surface.');return;}prompts[active].push({xy:point,label:tool==='positive'?1:0});revision++;drawTools();message('Point added. Download prompts to prepare this selection locally with SAM 2.');return;}
      if(tool==='corners'){const distances=selected().state.corners.map(p=>Math.hypot((p[0]-point[0])*overlay.clientWidth,(p[1]-point[1])*overlay.clientHeight)),i=distances.indexOf(Math.min(...distances));if(distances[i]>35)return;remember();drag={corner:i};}else{remember();drag={last:point};brush(point);}};
    overlay.onpointermove=e=>{if(!drag)return;const p=position(e);if(drag.corner!==undefined){const next=clone(selected().state.corners);next[drag.corner]=p;try{R.inversePlane(next);selected().state.corners=next;render();}catch(_){/* retain last valid plane */}}else{brush(p,drag.last);drag.last=p;}};
    const end=()=>{if(drag){drag=null;sync();}};overlay.onpointerup=end;overlay.onpointercancel=end;
    $(main,'#photo-undo').onclick=async()=>{if(loading||materialBusy||!undo.length)return;loading=true;const previous=undo.pop(),ticket=operation;try{const masks=await Promise.all(previous.map(s=>R.loadImage(s.mask)));if(ticket!==operation)return;previous.forEach((entry,i)=>{const s=renderer.surfaces[i];s.state=entry.state;s.mask.getContext('2d').drawImage(masks[i],0,0);renderer.updateMask(s);});const images=await Promise.all(renderer.surfaces.map(s=>texture(s.state.product,s.state.textureSource)));if(ticket!==operation)return;renderer.surfaces.forEach((s,i)=>renderer.setStone(s,images[i]));loading=false;sync();message('Previous edit restored.');}catch(e){loading=false;busy(null);message(e.message);}};
    $(main,'#photo-export').onclick=()=>{if(loading||materialBusy)return;renderer.render();canvas.toBlob(blob=>{if(blob)download(blob,'maa-sarada-room.png');},'image/png');};
    $(main,'#photo-source').onclick=async()=>{if(!source||loading||materialBusy)return;const ticket=operation;try{const img=await R.loadImage(source);if(ticket!==operation)return;const normalized=R.canvasFor(img.width,img.height);normalized.getContext('2d').drawImage(img,0,0);normalized.toBlob(blob=>{if(blob)download(blob,'room-source.png');},'image/png');}catch(e){message(e.message);}};
    $(main,'#photo-prompts').onclick=()=>{const data={version:1,revision,surfaces:renderer.surfaces.filter(s=>(prompts[s.state.id]||[]).some(p=>p.label===1)).map(s=>({id:s.state.id,label:s.state.label,points:prompts[s.state.id],corners:s.state.corners}))};if(!data.surfaces.length){message('Add an AI include point to a surface first.');return;}download(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}),'room-prompts.json');};
    $(main,'#photo-upload').onchange=async e=>{
      const file=e.target.files[0];if(!file)return;
      const ticket=++operation;textureRevision++;materialBusy=false;loading=true;busy('Preparing your original photo…');
      try{
        await paint();const normalized=await photoData(file);if(ticket!==operation)return;
        $(main,'#photo-file-name').textContent=file.name;
        await load(normalized,[defaultState('floor',first.id,[[.2,.65],[.8,.65],[1,1],[0,1]]),defaultState('wall',first.id,[[.2,.15],[.8,.15],[.8,.65],[.2,.65]])],{name:file.name,message:'Photo ready. Adjust the corners, reset the selection, then erase furniture.'});
      }catch(error){if(ticket===operation){loading=false;busy(null);message(error.message);}}
      finally{e.target.value='';}
    };
    $(main,'#photo-sam').onchange=async e=>{const file=e.target.files[0];if(!file)return;const ticket=++operation;textureRevision++;materialBusy=false;loading=true;busy('Preparing AI surface selection…');try{if(file.size>20*1024*1024)throw Error('Selection file exceeds 20 MB.');const data=JSON.parse(await file.text());if(ticket!==operation)return;if(data.version!==1||!data.photo||!Array.isArray(data.surfaces)||data.surfaces.length<1||data.surfaces.length>8)throw Error('Invalid SAM selection file.');const ids=new Set();for(const s of data.surfaces){if(typeof s.id!=='string'||!/^[A-Za-z0-9_-]{1,40}$/.test(s.id)||ids.has(s.id)||!['floor','wall'].includes(s.label)||!/^data:image\/png;base64,/.test(s.mask))throw Error('Invalid surface mask.');ids.add(s.id);R.inversePlane(s.corners);}if(!/^data:image\/png;base64,/.test(data.photo.dataUrl)||!Number.isInteger(data.photo.width)||!Number.isInteger(data.photo.height)||data.photo.width<32||data.photo.height<32||data.photo.width>1536||data.photo.height>1536||!/^[a-f0-9]{64}$/.test(data.photo.sha256))throw Error('Invalid source photo.');const photoBytes=Uint8Array.from(atob(data.photo.dataUrl.split(',')[1]),c=>c.charCodeAt(0));const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',photoBytes))).map(v=>v.toString(16).padStart(2,'0')).join('');if(ticket!==operation)return;if(digest!==data.photo.sha256)throw Error('Source photo checksum does not match the selection.');const normalized=await R.loadImage(data.photo.dataUrl);if(normalized.width!==data.photo.width||normalized.height!==data.photo.height)throw Error('Source photo dimensions do not match the selection.');if(ticket!==operation)return;await load(data.photo.dataUrl,data.surfaces.map(s=>({...defaultState(s.id,first.id,s.corners),label:s.label,mask:s.mask,enabled:true})),{revision:data.revision,message:'SAM selection imported. Review edges, furniture and perspective before saving.'});}catch(error){if(ticket===operation){loading=false;busy(null);message(error.message);}}};
    $(main,'#save-design').onclick=async()=>{if(loading||materialBusy)return;try{const surfaces=renderer.surfaces.map(s=>({...clone(s.state),mask:s.mask.toDataURL()}));const thumbnail=R.canvasFor(320,Math.round(320*canvas.height/canvas.width));const was=renderer.original;renderer.original=false;renderer.render();thumbnail.getContext('2d').drawImage(canvas,0,0,thumbnail.width,thumbnail.height);renderer.original=was;renderer.render();const existing=await store('all');if(existing.length>=25)throw Error('Up to 25 photo designs can be saved. Remove an older design first.');const id=crypto.randomUUID();await store('put',{id,version:2,name,room,source,surfaces,active,thumbnail:thumbnail.toDataURL('image/jpeg',.8),revision,created:Date.now()});toast('Photo design saved.');message('Design saved to this browser.');}catch(e){message(e.message);}};
    if(params.get('saved')){try{const d=await store('get',params.get('saved'));if(!d)throw Error('Saved photo design was not found.');await load(d.source,d.surfaces,{room:d.room,name:d.name,active:d.active,revision:d.revision||1,message:'Saved design restored.'});}catch(e){message(e.message);}}else await preset(params.get('room')||rooms[0][0]);
    window.addEventListener('pagehide',event=>{if(!event.persisted)renderer.dispose();});
  }
  async function savedCards(main,esc){
    try{const designs=(await store('all')).sort((a,b)=>b.created-a.created);if(!designs.length)return;const heading=document.createElement('h2');heading.textContent='Saved photo designs';const grid=document.createElement('section');grid.className='stone-grid page-bottom';
      designs.forEach(d=>{const card=document.createElement('article');card.className='design-card';card.innerHTML=`<img class="photo-design-thumb" alt="Saved room preview"><div><h2>${esc(d.name)}</h2><p class="note">${d.surfaces.filter(s=>s.enabled).length} styled surfaces</p><div class="request-actions"><a class="button" href="${visualizerUrl}?saved=${encodeURIComponent(d.id)}">Open design ↗</a><button class="text-button">Remove</button></div></div>`;$(card,'img').src=d.thumbnail;$(card,'button').onclick=async()=>{try{await store('delete',d.id);card.remove();if(!grid.children.length){heading.remove();grid.remove();const empty=document.createElement('div');empty.className='empty-state';empty.textContent='No saved photo designs. Open the visualizer to create one.';main.append(empty);}}catch(e){alert(e.message);}};grid.append(card);});const empty=main.querySelector('.empty-state');if(empty)empty.remove();main.append(heading,grid);const count=main.querySelector('.page-banner-meta');if(count)count.textContent=main.querySelectorAll('.design-card').length+' saved room ideas';
    }catch(error){const notice=document.createElement('p');notice.setAttribute('role','alert');notice.textContent=error.message||'Browser storage is unavailable. Open the visualizer and export an image instead.';main.append(notice);}
  }
  window.RoomPhotoEditor={mount,savedCards};
})();
