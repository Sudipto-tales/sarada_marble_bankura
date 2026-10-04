(() => {
'use strict';
const config = window.consoleConfig;
const el = id => document.getElementById(id);
const all = selector => [...document.querySelectorAll(selector)];
const page = document.querySelector('[data-page]')?.dataset.page;
let preview = false, paused = false, busy = false, stopped = false, total = 0, apiPage = 1;
let registry = [], trafficSamples = [], loadSamples = [], lastFocus;
const planned = [
 {method:'GET',path:'/api/v1/products',module:'Catalog',permission:'catalog.read',state:'Planned'},
 {method:'GET',path:'/api/v1/categories',module:'Catalog',permission:'catalog.read',state:'Planned'},
 {method:'POST',path:'/api/v1/cart/items',module:'Cart',permission:'cart.write',state:'Planned'},
 {method:'POST',path:'/api/v1/checkout',module:'Checkout',permission:'checkout.create',state:'Planned'},
 {method:'GET',path:'/api/v1/orders',module:'Orders',permission:'orders.read',state:'Planned'}
];
const incidents = [
 {title:'Payment gateway response timed out',module:'Payments',severity:'High',state:'Open',count:7},
 {title:'Inventory reservation could not complete',module:'Inventory',severity:'Medium',state:'Acknowledged',count:3},
 {title:'Catalog service recovered',module:'Catalog',severity:'Low',state:'Resolved',count:2}
];
const moduleInfo = {
 Catalog:'Product discovery, categories, variants and availability.',
 Cart:'Selected products, quantities, saved items and customer cart ownership.',
 Checkout:'Server-calculated totals, addresses and stock reservation.',
 Payments:'Gateway requests, verified webhooks and idempotent reconciliation.',
 Orders:'Order creation, status history, fulfilment and tracking.'
};
function value(name, text) { all(`[data-value="${name}"]`).forEach(node => node.textContent = text); }
function activity(message, source = 'RUNTIME', when = new Date().toLocaleTimeString()) {
 all('[data-events]').forEach(list => {
  if (!list.dataset.ready) { list.replaceChildren(); list.dataset.ready = 'true'; }
  const item = document.createElement('li'), time = document.createElement('time');
  item.textContent = message; time.textContent = `${source} · ${when}`;
  item.append(time); list.prepend(item);
  const limit = page === 'activity' ? 30 : 5;
  while (list.children.length > limit) list.lastElementChild.remove();
 });
}
function draw(kind, samples) {
 all(`[data-chart="${kind}"]`).forEach(svg => {
  const max = Math.max(1, ...samples.filter(Number.isFinite)) * 1.15;
  const points = samples.map((n, i) => Number.isFinite(n) ? [i * 640 / 59, 165 - n / max * 145] : null);
  let line = '', area = '', segment = [];
  function flush() {
   if (!segment.length) return;
   const coords = segment.map(point => point.join(',')).join(' L');
   line += `M${coords} `;
   area += `M${segment[0][0]},180 L${coords} L${segment.at(-1)[0]},180 Z `;
   segment = [];
  }
  points.forEach(point => { if (point) segment.push(point); else flush(); }); flush();
  svg.querySelector('.line').setAttribute('d', line);
  svg.querySelector('.area').setAttribute('d', area);
  svg.querySelector('.empty-chart').style.display = line ? 'none' : '';
 });
}
function cell(row, text, className) {
 const td = document.createElement('td'); td.textContent = text;
 if (className) td.className = className; row.append(td); return td;
}
function details(endpoint, button) {
 lastFocus = button;
 el('detail-title').textContent = `${endpoint.method} ${endpoint.path}`;
 el('detail-body').textContent = `Module: ${endpoint.module}\nPermission: ${endpoint.permission}\nState: ${endpoint.state}\n\n${endpoint.state === 'Planned' ? 'Design contract; this route is not implemented.' : 'Registered route. Traffic metrics require instrumentation.'}\n\nRequest bodies and secrets are not captured.`;
 el('detail-dialog').hidden = false; el('close-detail').focus();
}
function closeDetails() { el('detail-dialog').hidden = true; lastFocus?.focus(); }
function renderRegistry() {
 const rows = [...registry, ...planned.map(row => ({...row, state:preview ? 'Sample' : 'Planned'}))];
 const summary = el('overview-endpoints');
 if (summary) {
  summary.replaceChildren();
  rows.slice(0, 4).forEach(endpoint => { const tr = document.createElement('tr'); cell(tr, `${endpoint.method} ${endpoint.path}`); cell(tr, endpoint.module); const td = cell(tr, ''); const badge = document.createElement('span'); badge.className = 'status neutral'; badge.textContent = endpoint.state; td.append(badge); summary.append(tr); });
 }
 const body = el('api-rows'); if (!body) return;
 const search = (el('api-search')?.value || '').toLowerCase(), module = el('module-filter')?.value || '';
 const filtered = rows.filter(row => `${row.path} ${row.module} ${row.permission}`.toLowerCase().includes(search) && (!module || row.module === module));
 const pages = Math.max(1, Math.ceil(filtered.length / 10)); apiPage = Math.min(apiPage, pages);
 body.replaceChildren();
 filtered.slice((apiPage - 1) * 10, apiPage * 10).forEach((endpoint, index) => {
  const tr = document.createElement('tr'), td = cell(tr, '');
  const method = document.createElement('span'); method.className = 'method' + (endpoint.method === 'POST' ? ' post' : ''); method.textContent = endpoint.method;
  const button = document.createElement('button'); button.className = 'endpoint-button'; button.textContent = endpoint.path; button.onclick = () => details(endpoint, button);
  td.append(method, button); cell(tr, endpoint.module); cell(tr, endpoint.permission);
  const status = cell(tr, ''), badge = document.createElement('span'); badge.className = 'status ' + (endpoint.state === 'Registered' ? 'green' : endpoint.state === 'Sample' ? 'amber' : 'neutral'); badge.textContent = endpoint.state; status.append(badge);
  cell(tr, preview && endpoint.state === 'Sample' ? Math.round(total / (index + 2)).toLocaleString() : '—');
  cell(tr, preview && endpoint.state === 'Sample' ? `${72 + index * 23} ms` : '—'); body.append(tr);
 });
 if (!filtered.length) { const tr=document.createElement('tr'),td=cell(tr,'No endpoints match your filters.','muted');td.colSpan=6;body.append(tr); }
 el('result-count').textContent = `${filtered.length} endpoints`;
 el('table-count').textContent = `${registry.length} registered · ${planned.length} ${preview ? 'sample contracts' : 'planned contracts'}`;
 el('page-count').textContent = `${apiPage} / ${pages}`;
 el('previous').disabled = apiPage <= 1; el('next').disabled = apiPage >= pages;
}
function renderIncidents() {
 const list = el('incident-list'); if (!list) return;
 if (!preview) { list.className='empty-state'; list.replaceChildren(); const icon=document.createElement('span');icon.className='empty-icon';icon.textContent='△';const title=document.createElement('h3');title.textContent='Incident collection is not connected';const text=document.createElement('p');text.textContent='This does not indicate a healthy system. Enable sample preview to inspect the incident layout.';list.append(icon,title,text);return; }
 list.className = ''; list.replaceChildren();
 const state=el('incident-filter')?.value || '';
 incidents.filter(item=>!state||item.state===state).forEach(item=>{
  const row=document.createElement('div');row.className='incident-row';
  const badge=document.createElement('span');badge.className='status '+(item.severity==='High'?'red':'amber');badge.textContent=item.severity;
  const info=document.createElement('div'),title=document.createElement('strong'),sub=document.createElement('small');title.textContent=item.title;sub.textContent=`${item.module} · ${item.count} occurrences · SIMULATED`;info.append(title,sub);
  const status=document.createElement('span');status.className='status '+(item.state==='Resolved'?'green':'neutral');status.textContent=item.state;row.append(badge,info,status);list.append(row);
 });
}
function resetPreview() {
 total=0;trafficSamples=[];draw('traffic',trafficSamples);
 ['requests','rate','errors','latency','incidents','acknowledged','resolved'].forEach(name=>value(name,'—'));
 all('[data-events]').forEach(list=>{list.replaceChildren();delete list.dataset.ready;});
 activity('Sample preview ended. Live runtime observations only.');
}
function sampleTick() {
 if (!preview) return;
 if (!trafficSamples.length) trafficSamples=Array.from({length:59},(_,i)=>Math.round(20+Math.sin(i*.3)*7+Math.random()*5));
 const rate=12+Math.round(Math.random()*25);total+=rate*5;trafficSamples.push(rate);trafficSamples=trafficSamples.slice(-60);
 value('requests',total.toLocaleString());value('rate',rate);value('errors','0.4%');value('latency','124 ms');
 value('incidents',1);value('acknowledged',1);value('resolved',1);draw('traffic',trafficSamples);
 activity('Catalog → Cart → Checkout · sample request trace', 'SIMULATED');renderRegistry();
}
async function tick() {
 if (paused || document.hidden || busy || stopped || config.denied) return;
 busy=true;
 try {
  const response=await fetch(config.endpoint,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(4000)});
  if (response.status===401) { stopped=true;window.location.assign(config.login);return; }
  if (!response.ok) { if ([403,404].includes(response.status)) stopped=true;throw new Error(`Metrics unavailable (${response.status})`); }
  const data=await response.json();
  value('load',data.load_1m===null?'Unavailable':Number(data.load_1m).toFixed(2));
  value('memory',data.php_memory_mb===null?'No permission':`${data.php_memory_mb} MB`);
  value('registered',data.registered_apis===null?'—':data.registered_apis);
  registry=Array.isArray(data.endpoints)?data.endpoints:[];renderRegistry();
  loadSamples.push(data.load_1m);loadSamples=loadSamples.slice(-60);draw('load',loadSamples);
  el('connection').textContent='Runtime connected';el('connection-dot').classList.add('on');
  const when=new Date(data.observed_at).toLocaleTimeString();el('updated').textContent=`Last observation ${when}`;
  activity('Runtime sample received', 'RUNTIME',when);
 } catch(error) {
  el('connection').textContent='Connection unavailable';el('connection-dot').classList.remove('on');
  el('updated').textContent=error.message;value('load','—');value('memory','—');value('registered','—');
  loadSamples.push(null);loadSamples=loadSamples.slice(-60);draw('load',loadSamples);
 } finally {busy=false;}
 sampleTick();
}
el('sample')?.addEventListener('click',()=>{
 preview=!preview;el('sample').setAttribute('aria-pressed',String(preview));el('sample').textContent=preview?'Exit sample preview':'Preview sample data';
 const notice=el('data-notice');if(notice){notice.classList.toggle('sample',preview);notice.querySelector('div').textContent=preview?'SAMPLE PREVIEW · Traffic, incidents and flow events are simulated. Runtime readings remain real.':'Live runtime sampling. API traffic, traces and incidents await collectors.';notice.querySelector('.notice-label').textContent=preview?'SIMULATED':'FOUNDATION';}
 all('[data-source]').forEach(node=>node.textContent=preview?'Simulated requests / sec':'Awaiting collector');
 if(!preview)resetPreview();else sampleTick();renderIncidents();renderRegistry();
});
el('pause')?.addEventListener('click',()=>{paused=!paused;el('pause').setAttribute('aria-pressed',String(paused));el('pause').textContent=paused?'▶ Resume':'Ⅱ Pause';if(paused){el('connection').textContent='Updates paused';el('connection-dot').classList.remove('on');}else tick();});
el('menu-toggle')?.addEventListener('click',()=>{const open=el('sidebar').classList.toggle('open');el('menu-toggle').setAttribute('aria-expanded',String(open));});
['api-search','module-filter'].forEach(id=>el(id)?.addEventListener(id==='api-search'?'input':'change',()=>{apiPage=1;renderRegistry();}));
el('previous')?.addEventListener('click',()=>{apiPage=Math.max(1,apiPage-1);renderRegistry();});el('next')?.addEventListener('click',()=>{apiPage++;renderRegistry();});
el('incident-filter')?.addEventListener('change',renderIncidents);
el('clear-events')?.addEventListener('click',()=>{all('[data-events]').forEach(list=>{list.replaceChildren();list.dataset.ready='true';});});
el('close-detail')?.addEventListener('click',closeDetails);
el('detail-dialog')?.addEventListener('click',event=>{if(event.target===el('detail-dialog'))closeDetails();});
document.addEventListener('keydown',event=>{
 if(event.key==='Escape'){if(!el('detail-dialog')?.hidden)closeDetails();el('sidebar')?.classList.remove('open');el('menu-toggle')?.setAttribute('aria-expanded','false');}
 if(event.key==='Tab'&&!el('detail-dialog')?.hidden){event.preventDefault();el('close-detail').focus();}
});
let zoom=1;
function setZoom(next){zoom=Math.max(.6,Math.min(1.5,next));all('.flow-lane').forEach(lane=>lane.style.transform=`scale(${zoom})`);if(el('zoom-label'))el('zoom-label').textContent=`${Math.round(zoom*100)}%`;}
el('zoom-in')?.addEventListener('click',()=>setZoom(zoom+.1));el('zoom-out')?.addEventListener('click',()=>setZoom(zoom-.1));el('zoom-reset')?.addEventListener('click',()=>setZoom(1));
all('.flow-node').forEach(node=>{
 node.setAttribute('tabindex','0');node.setAttribute('role','button');node.setAttribute('aria-label','Inspect '+node.querySelector('strong').textContent+' module');
 function select(){all('.flow-node').forEach(item=>item.classList.remove('selected'));node.classList.add('selected');if(el('module-name')){const name=node.querySelector('strong').textContent;el('module-name').textContent=name;el('module-description').textContent=moduleInfo[name];}}
 node.addEventListener('click',select);node.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();select();}});
});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick();});
renderRegistry();if(!config.denied){setInterval(tick,5000);tick();}else{el('connection').textContent='Permission required';}
})();
