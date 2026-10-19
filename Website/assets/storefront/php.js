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

/* Wishlist save: fill the heart in place; falls back to a full POST without JS. */
(() => {
  'use strict';
  const badge = document.querySelector('.header-actions a[aria-label="Saved materials"] b');
  document.querySelectorAll('form[data-wishlist]').forEach(form => {
    form.addEventListener('submit', event => {
      // Toggle the heart in place immediately; the write is sent in the background, never reloading.
      event.preventDefault();
      const button = form.querySelector('button'), action = form.elements.action;
      if (button.dataset.busy) return;
      button.dataset.busy = '1';
      const saved = action.value === 'add';
      action.value = saved ? 'remove' : 'add';
      button.classList.toggle('saved', saved);
      button.setAttribute('aria-pressed', String(saved));
      if (badge) badge.textContent = String(Math.max(0, (parseInt(badge.textContent,10)||0) + (saved ? 1 : -1)));
      fetch(form.action, { method:'POST', body:new FormData(form) }).catch(() => {}).finally(() => { delete button.dataset.busy; });
    });
  });
})();

/* Header live search: grouped suggestions with thumbnails from the public products API.
   Falls back to the plain GET form (action=shop) whenever JS, the network or the API is unavailable. */
(() => {
  'use strict';
  const form = document.querySelector('header .search');
  const input = form && form.querySelector('input[name="q"]');
  const api = form && form.dataset.api;
  if (!form || !input || !api) return;
  const base = form.dataset.base || '';
  const panel = document.createElement('div');
  panel.className = 'search-suggest';
  panel.id = 'search-suggest';
  panel.setAttribute('role', 'listbox');
  panel.hidden = true;
  form.append(panel);

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const money = minor => '₹' + Math.floor((parseInt(minor, 10) || 0) / 100).toLocaleString('en-IN');
  const open = html => { panel.innerHTML = html; panel.hidden = false; input.setAttribute('aria-expanded', 'true'); };
  const close = () => { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); };

  const render = (items, q) => {
    if (!items.length) { open('<p class="search-empty">No matches for “' + esc(q) + '”. Press Enter to search the full catalogue.</p>'); return; }
    const groups = new Map();
    items.forEach(i => { const key = i.category_name || 'Other'; (groups.get(key) || groups.set(key, []).get(key)).push(i); });
    let html = '';
    groups.forEach((list, cat) => {
      html += '<div class="search-group"><p class="search-group-head">' + esc(cat) + '</p>';
      list.forEach(i => {
        const img = i.images && i.images[0];
        const thumb = img ? '<img loading="lazy" src="' + esc(base + img.path) + '" alt="' + esc(img.alt_text) + '">' : '<span class="search-thumb-blank"></span>';
        const price = i.min_price_minor ? '<span class="search-price">From ' + money(i.min_price_minor) + '</span>' : '';
        html += '<a class="search-hit" role="option" href="' + esc(base + 'product/' + i.slug) + '">' + thumb +
          '<span class="search-hit-text"><span class="search-hit-name">' + esc(i.name) + '</span>' +
          '<span class="search-hit-meta">' + esc(i.brand_name || i.category_name) + '</span></span>' + price + '</a>';
      });
      html += '</div>';
    });
    html += '<a class="search-all" href="' + esc(base + 'shop?q=' + encodeURIComponent(q)) + '">See all results for “' + esc(q) + '” ↗</a>';
    open(html);
  };

  let timer, controller, lastQuery = '';
  const search = async q => {
    if (controller) controller.abort();
    controller = new AbortController();
    try {
      const res = await fetch(api + '?q=' + encodeURIComponent(q) + '&per_page=8', { signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!res.ok) { close(); return; }
      const body = await res.json();
      if (q === lastQuery) render(body.data || [], q);
    } catch (_) { /* abort or network error: the plain form submit remains the fallback */ }
  };

  input.addEventListener('input', () => {
    const q = input.value.trim();
    lastQuery = q;
    clearTimeout(timer);
    if (q.length < 2) { close(); return; }
    timer = setTimeout(() => search(q), 200);
  });
  input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && panel.innerHTML) panel.hidden = false; });
  input.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  document.addEventListener('click', event => { if (!form.contains(event.target)) close(); });
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
