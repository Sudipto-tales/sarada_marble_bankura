(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const sidebar=document.querySelector('.sidebar'),toggle=document.querySelector('.drawer-toggle'),backdrop=document.querySelector('.sidebar-backdrop');
  if(sidebar){
    const media=matchMedia('(max-width:900px)');let previousFocus=null;
    function drawer(open){
      sidebar.classList.toggle('open',open);backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));
      sidebar.inert=media.matches&&!open;document.body.classList.toggle('drawer-open',open&&media.matches);
      if(open){previousFocus=document.activeElement;sidebar.querySelector('.drawer-close').focus();}else if(previousFocus){previousFocus.focus();previousFocus=null;}
    }
    toggle.onclick=()=>drawer(!sidebar.classList.contains('open'));
    sidebar.querySelector('.drawer-close').onclick=()=>drawer(false);backdrop.onclick=()=>drawer(false);
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
        data:'id',orderable:false,searchable:false,render:(id,type)=>type==='display'?`<a class="table-link" href="${table.dataset.destination}?${table.dataset.queryKey}=${encodeURIComponent(id)}">Open record ↗</a>`:id
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
