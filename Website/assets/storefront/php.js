/* Progressive enhancements only; catalog and commerce forms render in PHP. */
(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const toggle = document.querySelector('.mobile-toggle');
  const nav = document.querySelector('#site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('php-nav-open', open);
    });
  }
  const surfaces = document.querySelector('#calculator-surfaces');
  const addSurface = document.querySelector('#add-surface');
  if (surfaces && addSurface) {
    const rename = () => surfaces.querySelectorAll('.php-surface').forEach((surface,index) => {
      surface.querySelector('legend').textContent = `Surface ${index+1}`;
      surface.querySelectorAll('[name]').forEach(input => { input.name=input.name.replace(/surfaces\[[0-9]+\]/, `surfaces[${index}]`); });
      surface.querySelector('[data-remove-surface]').hidden = surfaces.children.length === 1;
    });
    addSurface.hidden=false; rename();
    addSurface.addEventListener('click', () => {
      if (surfaces.children.length >= 20) return;
      const surface=surfaces.firstElementChild.cloneNode(true);
      surface.querySelector('[name$="[name]"]').value=`Surface ${surfaces.children.length+1}`;
      surfaces.append(surface);rename();
    });
    surfaces.addEventListener('click', event => {
      if (event.target.matches('[data-remove-surface]') && surfaces.children.length>1) {
        event.target.closest('.php-surface').remove();rename();
      }
    });
  }
  document.querySelectorAll('[data-gallery-image]').forEach(link => {
    link.addEventListener('click', event => {
      const image = document.querySelector('#product-image');
      const full = document.querySelector('#gallery-link');
      if (!image || !full) return;
      event.preventDefault();
      image.src = link.dataset.galleryImage;
      image.alt = link.dataset.galleryAlt;
      full.href = link.href;
    });
  });
})();

/* Browser-local project notes: no request is sent to the store. */
(() => {
  'use strict';
  const toast = document.querySelector('#toast');
  let timer;
  const notify = message => { if (!toast) return; toast.textContent=message; toast.classList.add('show'); clearTimeout(timer); timer=setTimeout(()=>toast.classList.remove('show'),4000); };
  document.querySelectorAll('[data-showroom-info]').forEach(button => {
    button.hidden=false;
    button.addEventListener('click',()=>notify('Visit our Bankura showroom to confirm availability, samples, delivery and current pricing.'));
  });
  const dialog=document.querySelector('#request-dialog'), form=document.querySelector('#request-form');
  if (!dialog || !form) return;
  const key='sarada-project-draft';
  document.querySelectorAll('[data-open-enquiry]').forEach(button=>{
    button.hidden=false;
    button.addEventListener('click',()=>{
      try { const draft=JSON.parse(localStorage.getItem(key)||'null'); if(draft){ form.elements.name.value=draft.name||''; form.elements.notes.value=draft.notes||''; } } catch (_) { /* The form still works for this visit. */ }
      dialog.showModal();
    });
  });
  dialog.querySelector('[data-close-request]').addEventListener('click',()=>dialog.close());
  form.addEventListener('submit',event=>{
    event.preventDefault();
    const status=document.querySelector('#request-status');
    try { localStorage.setItem(key,JSON.stringify({name:form.elements.name.value,notes:form.elements.notes.value})); status.textContent='Draft saved on this browser. Nothing has been sent to the store.'; }
    catch (_) { status.textContent='Browser storage is unavailable. Copy your notes before closing this page.'; }
  });
})();

/* The original mobile filter disclosure, retaining real GET filtering. */
(() => {
  const form=document.querySelector('#store-filters');
  const button=document.querySelector('.filter-mobile-toggle');
  if (!form || !button) return;
  const mobile=matchMedia('(max-width:650px)');
  function setOpen(open) { form.hidden=!open; button.setAttribute('aria-expanded',String(open)); button.textContent=open?'Hide filters −':'Show filters +'; }
  function resize() { button.hidden=!mobile.matches; setOpen(!mobile.matches); }
  button.addEventListener('click',()=>setOpen(form.hidden)); mobile.addEventListener('change',resize); resize();
})();
