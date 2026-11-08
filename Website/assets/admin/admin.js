(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const sidebar=document.querySelector('#leftside-menu'),toggle=document.querySelector('.drawer-toggle'),backdrop=document.querySelector('.sidebar-backdrop');
  if(sidebar){
    const media=matchMedia('(max-width:700px)');let previousFocus=null;
    function drawer(open){
      const mobile=media.matches&&open;
      sidebar.classList.toggle('open',open);
      backdrop.hidden=!mobile;
      toggle.setAttribute('aria-expanded',String(mobile));
      toggle.setAttribute('aria-label',mobile?'Close navigation':'Open navigation');
      sidebar.inert=media.matches&&!open;
      document.body.classList.toggle('drawer-open',mobile);
      if(mobile){previousFocus=document.activeElement;sidebar.querySelector('.drawer-close')?.focus();}
      else if(previousFocus){previousFocus.focus();previousFocus=null;}
    }
    toggle?.addEventListener('click',()=>drawer(!sidebar.classList.contains('open')));
    sidebar.querySelector('.drawer-close')?.addEventListener('click',()=>drawer(false));
    backdrop?.addEventListener('click',()=>drawer(false));
    media.addEventListener('change',()=>drawer(false));sidebar.inert=media.matches;
    document.addEventListener('keydown',event=>{
      if(!sidebar.classList.contains('open')||!media.matches)return;
      if(event.key==='Escape'){drawer(false);return;}
      if(event.key==='Tab'){
        const focusable=[...sidebar.querySelectorAll('a,button')].filter(el=>!el.disabled),first=focusable[0],last=focusable.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
      }
    });
  }
  const table=document.querySelector('[data-source]');
  if(table&&window.DataTable){
    const message=document.querySelector('.table-message'),escape=value=>{const span=document.createElement('span');span.textContent=value??'—';return span.innerHTML;};
    DataTable.ext.errMode='none';
    window.adminTable=new DataTable(table,{
      serverSide:true,processing:true,pageLength:20,lengthMenu:[10,20,50],searchDelay:300,order:[[0,'asc']],search:{search:table.dataset.search},
      columns:[...table.querySelectorAll('thead th')].map(th=>th.dataset.field==='action'?{
        data:'id',orderable:false,searchable:false,render:(id,type,row)=>{
          if(type!=='display')return id;
          if(table.dataset.module==='enquiries'&&row.contact)return `<button type="button" class="table-icon-action" data-copy-contact="${escape(row.contact)}" aria-label="Copy contact" title="Copy contact"><i class="mdi mdi-content-copy" aria-hidden="true"></i></button>`;
          const edit=table.dataset.actionMode==='edit',label=table.dataset.module==='inventory'&&edit?'Adjust stock':`${edit?'Edit':'View'} record`;
          return table.dataset.destination?`<a class="table-icon-action" href="${escape(table.dataset.destination)}?${table.dataset.queryKey}=${encodeURIComponent(id)}" aria-label="${label}" title="${label}"><i class="mdi ${edit?'mdi-pencil-outline':'mdi-eye-outline'}" aria-hidden="true"></i></a>`:'—';
        }
      }:{data:th.dataset.field,defaultContent:'—',render:(value,type)=>type==='display'?escape(value):value}),
      ajax:async(data,callback)=>{
        const query=new URLSearchParams({draw:data.draw,start:data.start,length:data.length,'search[value]':data.search.value,'order[0][column]':data.order[0]?.column??0,'order[0][dir]':data.order[0]?.dir??'asc'});
        try{
          const response=await fetch(`${table.dataset.source}?${query}`,{credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();
          if(!response.ok)throw Error(result.error?.message||'Could not load these records.');
          message.textContent='';callback(result);
        }catch(error){message.textContent=`${error.message} Reload the page or sign in again.`;callback({draw:data.draw,data:[],recordsTotal:0,recordsFiltered:0});}
      },
      language:{search:'Search records:',searchPlaceholder:'Name, SKU or keyword…',emptyTable:'No records yet. Add your first record when you are ready.',zeroRecords:'No matching records. Try a different search.',processing:'Loading records…',lengthMenu:'Show _MENU_ records',info:'Showing _START_–_END_ of _TOTAL_ records'}
    });
    table.closest('.table-panel').classList.add('enhanced');
  }
  const productGrid=document.querySelector('#product-grid'),recordGrid=document.querySelector('#admin-record-grid');
  if(table&&(productGrid||recordGrid)&&window.adminTable){
    const module=table.dataset.module,panel=table.closest('.table-panel'),list=panel.querySelector('.table-scroll'),listTable=panel.querySelector('.dt-layout-table'),dataTableContainer=table.closest('.dt-container');
    const buttons=[...document.querySelectorAll('[data-table-view]')],productTools=document.querySelector('#product-grid-tools');
    const pager=document.querySelector('#product-grid-pagination'),productSearch=document.querySelector('#product-grid-search');
    const count=document.querySelector('#product-grid-count'),pageLabel=document.querySelector('#product-grid-page');
    const escapeHTML=value=>{const node=document.createElement('span');node.textContent=value??'';return node.innerHTML;};
    let start=0,total=0,loading=false,searchTimer=null;const pageSize=20,storageKey=`admin-table-view:${module}`;
    function actionMarkup(row){
      if(module==='enquiries')return row.contact?`<button type="button" class="table-icon-action" data-copy-contact="${escapeHTML(row.contact)}" aria-label="Copy contact" title="Copy contact"><i class="mdi mdi-content-copy" aria-hidden="true"></i></button>`:'';
      if(!table.dataset.destination)return '';
      const edit=table.dataset.actionMode==='edit',label=module==='inventory'&&edit?'Adjust stock':`${edit?'Edit':'View'} record`;
      return `<a class="table-icon-action" href="${escapeHTML(table.dataset.destination)}?${table.dataset.queryKey}=${encodeURIComponent(row.id)}" aria-label="${label}" title="${label}"><i class="mdi ${edit?'mdi-pencil-outline':'mdi-eye-outline'}" aria-hidden="true"></i></a>`;
    }
    function cardData(row){
      const couponLimit=row.usage_limit===null?'No cap':row.usage_limit;
      const layouts={
        categories:{title:row.name,meta:[row.slug,row.parent_name?`Parent: ${row.parent_name}`:'Top level category'],state:Number(row.enabled)?'Enabled':'Disabled'},
        brands:{title:row.name,meta:[row.slug],state:Number(row.enabled)?'Enabled':'Disabled'},
        inventory:{title:row.name,meta:[`SKU ${row.sku}`,`Available ${row.available} ${row.sell_unit}`,`On hand ${row.on_hand} · Reserved ${row.reserved}`],state:Number(row.available)>0?'In stock':'Out of stock'},
        orders:{title:row.order_number,meta:[row.customer,`${row.total} INR`,`${row.payment_status} · ${row.created}`],state:row.status},
        customers:{title:row.name,meta:[row.email,Number(row.email_verify)?'Email verified':'Email pending'],state:Number(row.status)?'Active':'Disabled'},
        coupons:{title:row.code,meta:[`${row.kind} · ${row.value}`,`Uses ${row.used_count} / ${couponLimit}`],state:Number(row.enabled)?'Enabled':'Disabled'},
        enquiries:{title:row.customer_name,meta:[row.contact,row.product_name,row.created,row.note],state:''}
      };
      return layouts[module]||{title:row.name||row.id,meta:[],state:''};
    }
    function renderRecordGrid(){
      if(!recordGrid||module==='products')return;
      const rows=window.adminTable.rows({page:'current'}).data().toArray(),icons={categories:'mdi-shape-outline',brands:'mdi-tag-outline',inventory:'mdi-warehouse',orders:'mdi-receipt-text-outline',customers:'mdi-account-multiple',coupons:'mdi-ticket-percent-outline',enquiries:'mdi-email-outline'};
      recordGrid.innerHTML=rows.map(row=>{
        const card=cardData(row),meta=card.meta.filter(value=>value&&value!=='—').map(value=>`<span>${escapeHTML(value)}</span>`).join('');
        const status=card.state?`<span class="admin-grid-badge ${escapeHTML(String(card.state).toLowerCase().replace(/[^a-z0-9]+/g,'-'))}">${escapeHTML(card.state)}</span>`:'';
        return `<article class="admin-grid-card"><div class="admin-grid-card-head"><span class="admin-grid-icon" aria-hidden="true"><i class="mdi ${icons[module]||'mdi-database-outline'}"></i></span><div class="admin-grid-card-title"><h3>${escapeHTML(card.title)}</h3>${status}</div></div><div class="admin-grid-meta">${meta}</div><div class="admin-grid-actions">${actionMarkup(row)}</div></article>`;
      }).join('');
      if(!rows.length)recordGrid.innerHTML='<p class="product-grid-empty">No records match this search.</p>';
      const info=window.adminTable.page.info();count&&(count.textContent=`${info.recordsDisplay.toLocaleString()} ${module}`);
    }
    async function loadProductGrid(){
      if(loading||!productGrid)return;loading=true;productGrid.setAttribute('aria-busy','true');
      const query=new URLSearchParams({view:'grid',draw:'1',start:String(start),length:String(pageSize),'search[value]':productSearch.value});
      try{
        const response=await fetch(`${table.dataset.source}?${query}`,{credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();
        if(!response.ok)throw Error(result.error?.message||'Could not load products.');
        total=Number(result.recordsFiltered)||0;count.textContent=`${total.toLocaleString()} products`;
        pageLabel.textContent=`Page ${total?Math.floor(start/pageSize)+1:0} of ${Math.max(1,Math.ceil(total/pageSize))}`;
        productGrid.innerHTML=result.data.map(item=>{
          const details=new URL(table.dataset.destination,location.href);details.searchParams.set('id',item.id);
          const picture=item.image_url?`<img src="${escapeHTML(item.image_url)}" alt="" loading="lazy">`:'<span class="product-grid-placeholder" aria-hidden="true">▦</span>';
          const price=item.min_price===null?'Price not set':`₹${escapeHTML(item.min_price)}${item.max_price!==item.min_price?` – ₹${escapeHTML(item.max_price)}`:''}`;
          const brand=item.brand_name?` · ${escapeHTML(item.brand_name)}`:'';
          const store=item.status==='active'?`<a class="table-icon-action" href="${escapeHTML(item.product_url)}" target="_blank" rel="noopener" aria-label="View on storefront" title="View on storefront"><i class="mdi mdi-open-in-new" aria-hidden="true"></i></a>`:'';
          const icon=table.dataset.actionMode==='edit'?'mdi-pencil-outline':'mdi-eye-outline',label=table.dataset.actionMode==='edit'?'Edit product':'View product';
          const edit=`<a class="table-icon-action" href="${escapeHTML(details.href)}" aria-label="${label}" title="${label}"><i class="mdi ${icon}" aria-hidden="true"></i></a>`;
          return `<article class="product-grid-card"><div class="product-grid-main"><span class="product-grid-image">${picture}</span><span class="product-grid-title"><strong>${escapeHTML(item.name)}</strong><span class="product-grid-status ${escapeHTML(item.status)}">${escapeHTML(item.status)}</span></span><span class="product-grid-meta">${escapeHTML(item.category_name)}${brand}</span><span class="product-grid-sku">SKU ${escapeHTML(item.sku||'—')}</span><strong class="product-grid-price">${price}</strong></div><span class="product-grid-actions">${edit}${store}</span></article>`;
        }).join('');
        if(!result.data.length)productGrid.innerHTML='<p class="product-grid-empty">No products match this search.</p>';
      }catch(error){productGrid.innerHTML=`<p class="product-grid-empty error">${escapeHTML(error.message)} Reload the page or sign in again.</p>`;}
      finally{loading=false;productGrid.removeAttribute('aria-busy');}
    }
    function setView(view){
      const grid=view==='grid';panel.classList.toggle('grid-view',grid);
      if(list)list.hidden=grid;
      if(listTable)listTable.hidden=grid;
      if(dataTableContainer&&module==='products')dataTableContainer.hidden=grid;
      if(productGrid)productGrid.hidden=!grid;
      if(recordGrid)recordGrid.hidden=!grid;
      if(productTools)productTools.hidden=!grid;
      if(pager)pager.hidden=!grid;
      buttons.forEach(button=>{const active=button.dataset.tableView===view;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});
      try{localStorage.setItem(storageKey,view);}catch{}
      if(grid){
        if(module==='products'){
          productSearch.value=window.adminTable.search()||'';
          start=0;loadProductGrid();
        }else renderRecordGrid();
      }else if(module==='products'&&window.adminTable.search()!==productSearch.value)window.adminTable.search(productSearch.value).draw();
    }
    buttons.forEach(button=>button.addEventListener('click',()=>setView(button.dataset.tableView)));
    window.adminTable.on('draw',()=>{if(panel.classList.contains('grid-view')&&module!=='products')renderRecordGrid();});
    productSearch?.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{start=0;loadProductGrid();},250);});
    document.querySelector('#product-grid-previous')?.addEventListener('click',()=>{if(start>0){start=Math.max(0,start-pageSize);loadProductGrid();}});
    document.querySelector('#product-grid-next')?.addEventListener('click',()=>{if(start+pageSize<total){start+=pageSize;loadProductGrid();}});
    document.addEventListener('click',async event=>{
      const button=event.target.closest('[data-copy-contact]');if(!button)return;
      try{await navigator.clipboard.writeText(button.dataset.copyContact);const message=document.querySelector('.table-message');if(message)message.textContent='Contact copied.';}
      catch{const message=document.querySelector('.table-message');if(message)message.textContent='Could not copy contact. Select it from the record details.';}
    });
    const views=[...buttons];for(const button of views)button.closest('.table-view-toggle').hidden=false;
    let initial='list';try{initial=localStorage.getItem(storageKey)==='grid'?'grid':'list';}catch{}
    setView(initial);
  }
  function chartRows(element){try{return JSON.parse(element.dataset.chartSeries||'[]');}catch{return[];}}
  function initAdminCharts(){
    if(!window.ApexCharts)return;
    const primary=getComputedStyle(document.documentElement).getPropertyValue('--admin-primary').trim()||'#696cff';
    const formatMoney=value=>`₹${Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    const mount=(element,options)=>{if(element&&chartRows(element).length)new ApexCharts(element,options).render();else if(element)element.innerHTML='<p class="chart-empty">No recorded data for this period.</p>';};
    const trend=document.querySelector('#analytics-trend');
    if(trend){const rows=chartRows(trend);mount(trend,{chart:{type:'line',height:300,toolbar:{show:false},fontFamily:'Inter,system-ui,sans-serif'},colors:[primary,'#03c3ec'],series:[{name:'Orders',type:'column',data:rows.map(row=>Number(row.orders)||0)},{name:'Paid revenue',type:'area',data:rows.map(row=>Number(row.revenue_minor||0)/100)}],stroke:{curve:'smooth',width:[0,2.5]},fill:{type:['solid','gradient'],opacity:[1,.18],gradient:{opacityFrom:.22,opacityTo:.02}},plotOptions:{bar:{columnWidth:'42%',borderRadius:3}},dataLabels:{enabled:false},grid:{borderColor:'#ebeef0',strokeDashArray:4},xaxis:{categories:rows.map(row=>row.date.slice(5)),labels:{style:{colors:'#697a8d'}}},yaxis:[{title:{text:'Orders'},labels:{style:{colors:'#697a8d'}}},{opposite:true,title:{text:'Paid revenue (INR)'},labels:{formatter:formatMoney,style:{colors:'#697a8d'}}}],legend:{labels:{colors:'#697a8d'}},tooltip:{shared:true,y:[{formatter:value=>`${Math.round(value)} orders`},{formatter:formatMoney}]}});}
    const status=document.querySelector('#analytics-status');
    if(status){const rows=chartRows(status);mount(status,{chart:{type:'donut',height:300,fontFamily:'Inter,system-ui,sans-serif'},colors:[primary,'#03c3ec','#71dd37','#ffab00','#ff3e1d','#8592a3'],labels:rows.map(row=>String(row.status)),series:rows.map(row=>Number(row.order_count)||0),dataLabels:{enabled:false},legend:{position:'bottom',labels:{colors:'#697a8d'}},stroke:{width:2,colors:['#fff']},plotOptions:{pie:{donut:{size:'68%'}}},tooltip:{y:{formatter:value=>`${value} orders`}}});}
    const products=document.querySelector('#analytics-products');
    if(products){const rows=chartRows(products).slice(0,10).reverse();mount(products,{chart:{type:'bar',height:Math.max(250,rows.length*36),toolbar:{show:false},fontFamily:'Inter,system-ui,sans-serif'},colors:[primary],series:[{name:'Paid revenue',data:rows.map(row=>Number(row.revenue_minor||0)/100)}],plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'58%'}},dataLabels:{enabled:false},grid:{borderColor:'#ebeef0',strokeDashArray:4},xaxis:{categories:rows.map(row=>String(row.name)),labels:{formatter:formatMoney,style:{colors:'#697a8d'}}},yaxis:{labels:{style:{colors:'#697a8d'}}},tooltip:{y:{formatter:formatMoney}}});}
    const productChart=document.querySelector('#product-analytics-chart');
    if(productChart){const rows=chartRows(productChart);mount(productChart,{chart:{type:'line',height:260,toolbar:{show:false},fontFamily:'Inter,system-ui,sans-serif'},colors:[primary,'#03c3ec'],series:[{name:'Views',data:rows.map(row=>Number(row.views)||0)},{name:'Net orders',data:rows.map(row=>Number(row.orders)||0)}],stroke:{curve:'smooth',width:2.5},markers:{size:0},dataLabels:{enabled:false},grid:{borderColor:'#ebeef0',strokeDashArray:4},xaxis:{categories:rows.map(row=>row.date.slice(5)),labels:{style:{colors:'#697a8d'},hideOverlappingLabels:true}},yaxis:{min:0,labels:{style:{colors:'#697a8d'}}},legend:{labels:{colors:'#697a8d'}},tooltip:{shared:true}});}
  }
  initAdminCharts();
  const productForm=document.querySelector('#product-form'),rows=document.querySelector('#variant-rows');
  if(productForm){
    function normalizeRows(){
      [...rows.children].forEach((row,index)=>{
        row.querySelector('.variant-number').textContent=index+1;
        row.querySelectorAll('[name]').forEach(input=>{input.name=input.name.replace(/variants\[\d+\]/,`variants[${index}]`);});
        row.querySelectorAll('[id]').forEach(input=>{
          const old=input.id,next=old.replace(/variants-\d+-/,`variants-${index}-`);input.id=next;
          row.querySelectorAll('[for]').forEach(label=>{if(label.htmlFor===old)label.htmlFor=next;});
        });
        row.querySelector('.remove-variant').disabled=rows.children.length<=1;
        const whole=row.querySelector('.variant-unit').value!=='sqft',increment=row.querySelector('[name$="[increment]"]'),coverage=row.querySelector('[name$="[coverage]"]');
        increment.step=whole?'1':'.001';increment.min=whole?'1':'.001';coverage.required=whole;
      });
      document.querySelector('#variant-count').value=rows.children.length;document.querySelector('#add-variant').disabled=rows.children.length>=20;
    }
    document.querySelector('#add-variant').onclick=()=>{
      if(rows.children.length>=20)return;
      const row=rows.firstElementChild.cloneNode(true);
      row.querySelectorAll('[type=hidden],.field-error').forEach(el=>el.remove());
      row.querySelectorAll('input').forEach(input=>{input.value=input.name.endsWith('[increment]')?'1':'';input.removeAttribute('aria-invalid');input.removeAttribute('aria-describedby');input.setCustomValidity('');});
      row.querySelectorAll('select').forEach(select=>{select.selectedIndex=0;});
      rows.append(row);normalizeRows();row.querySelector('input').focus();
    };
    rows.addEventListener('click',event=>{if(event.target.closest('.remove-variant')&&rows.children.length>1){event.target.closest('.variant-row').remove();normalizeRows();}});
    rows.addEventListener('change',normalizeRows);normalizeRows();
  }
  document.querySelectorAll('form[data-validate]').forEach(form=>{
    form.addEventListener('input',event=>{if(event.target.setCustomValidity)event.target.setCustomValidity('');});
    form.addEventListener('submit',event=>{
      form.querySelectorAll('input[type=number]').forEach(input=>{
        if(input.value&&/[eE+]/.test(input.value))input.setCustomValidity('Use a regular decimal number.');
        if(input.name==='quantity'&&Number(input.value)===0)input.setCustomValidity('Enter a non-zero stock change.');
      });
      form.querySelectorAll('input[type=file]').forEach(input=>{if(input.files[0]?.size>4*1024*1024)input.setCustomValidity('Choose an image under 4 MB.');});
      if(!form.reportValidity()){event.preventDefault();return;}
      form.querySelectorAll('button[type=submit],button:not([type])').forEach(button=>{button.disabled=true;button.dataset.label=button.textContent;button.textContent='Saving…';});
    });
  });
  document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();}));
  const summary=document.querySelector('.error-summary');if(summary)summary.focus();
  addEventListener('pageshow',()=>document.querySelectorAll('button[data-label]').forEach(button=>{button.disabled=false;button.textContent=button.dataset.label;delete button.dataset.label;}));
})();
