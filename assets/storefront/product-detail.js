/* Estimates are advisory; PHP cart/checkout remain authoritative. */
(() => {
  'use strict';
  const picker = document.querySelector('#variant-picker');
  if (!picker) return;
  const forms = [...document.querySelectorAll('.pdp-purchase form[data-variant-content]')];
  const money = value => {
    const major = (value / 100n).toLocaleString('en-IN');
    return `₹${major}.${String(value % 100n).padStart(2, '0')}`;
  };
  const milli = value => {
    if (!/^\d+(?:\.\d{1,3})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0'));
  };
  const estimate = form => {
    const input = form.querySelector('[name=quantity]');
    const output = form.querySelector('output');
    const quantity = milli(input.value);
    if (quantity === null || !input.validity.valid) { output.textContent = 'Enter a valid quantity'; return; }
    const numerator = BigInt(form.dataset.priceMinor) * quantity;
    output.textContent = money(numerator / 1000n + (numerator % 1000n >= 500n ? 1n : 0n));
  };
  forms.forEach(form => {
    const input = form.querySelector('[name=quantity]');
    form.querySelector('.pdp-estimate').hidden = false;
    input.addEventListener('input', () => estimate(form));
    form.querySelectorAll('[data-quantity-step]').forEach(button => {
      button.hidden = false;
      button.addEventListener('click', () => {
        const increment = BigInt(form.dataset.increment);
        let quantity = milli(input.value) ?? increment;
        quantity = quantity / increment * increment + BigInt(button.dataset.quantityStep) * increment;
        if (quantity < increment) quantity = increment;
        if (quantity > 1000000000n) return;
        input.value = `${quantity / 1000n}.${String(quantity % 1000n).padStart(3, '0')}`;
        estimate(form);
      });
    });
    estimate(form);
  });
  const select = () => {
    const selected = forms.find(form => form.dataset.variantContent === picker.value);
    if (!selected) return;
    document.querySelectorAll('[data-variant-content]').forEach(panel => {
      panel.hidden = panel.dataset.variantContent !== picker.value;
    });
    document.querySelectorAll('[data-selected-price]').forEach(node => { node.textContent = money(BigInt(selected.dataset.priceMinor)); });
    document.querySelectorAll('[data-selected-original]').forEach(node => {
      const original = selected.dataset.originalMinor;
      node.hidden = !original;
      node.textContent = original ? money(BigInt(original)) : '';
    });
    document.querySelectorAll('[data-selected-unit]').forEach(node => { node.textContent = `per ${selected.dataset.unit}`; });
    const mobile = document.querySelector('[data-mobile-purchase]');
    mobile.setAttribute('form', selected.id);
    mobile.disabled = selected.dataset.stock !== 'true';
    mobile.textContent = mobile.disabled ? 'Out of stock' : 'Add to cart';
  };
  document.querySelector('.pdp-variant-picker').hidden = false;
  picker.addEventListener('change', select);
  select();

  const thumbnails = [...document.querySelectorAll('[data-gallery-image]')];
  const main = document.querySelector('#gallery-link');
  const dialog = document.querySelector('#product-lightbox');
  if (!main || !dialog || !thumbnails.length || !dialog.showModal) return;
  let index = 0;
  const showImage = next => {
    index = (next + thumbnails.length) % thumbnails.length;
    const thumb = thumbnails[index];
    thumbnails.forEach((item, i) => {
      if (i === index) item.setAttribute('aria-current', 'true');
      else item.removeAttribute('aria-current');
    });
    const photo = document.querySelector('#product-image');
    photo.src = thumb.dataset.galleryImage;
    photo.alt = thumb.dataset.galleryAlt;
    main.href = thumb.href;
    dialog.querySelector('img').src = thumb.dataset.galleryImage;
    dialog.querySelector('img').alt = thumb.dataset.galleryAlt;
    dialog.querySelector('[data-photo-count]').textContent = `${index + 1} / ${thumbnails.length}`;
  };
  thumbnails.forEach((thumb, i) => thumb.addEventListener('click', () => showImage(i)));
  main.addEventListener('click', event => { event.preventDefault(); showImage(index); dialog.showModal(); });
  dialog.querySelector('[data-photo-close]').addEventListener('click', () => dialog.close());
  dialog.querySelector('[data-photo-previous]').addEventListener('click', () => showImage(index - 1));
  dialog.querySelector('[data-photo-next]').addEventListener('click', () => showImage(index + 1));
  dialog.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault(); showImage(index + (event.key === 'ArrowLeft' ? -1 : 1));
    }
  });
  if (thumbnails.length === 1) {
    dialog.querySelector('[data-photo-previous]').hidden = true;
    dialog.querySelector('[data-photo-next]').hidden = true;
  }
})();

/* Reviews: half-star picker, and submit/reply/like over fetch so the page never reloads. */
(() => {
  'use strict';
  const root = document.querySelector('[data-reviews]');
  if (!root) return;
  const csrfInput = root.querySelector('[data-review-form] [name=csrf]');
  const csrf = csrfInput ? csrfInput.value : '';
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const fmtDate = ts => { const d = new Date(ts * 1000); return `${String(d.getUTCDate()).padStart(2, '0')} ${MON[d.getUTCMonth()]} ${d.getUTCFullYear()}`; };
  const starsHTML = t => `<span class="stars"><i style="width:${Math.max(0, Math.min(100, t * 2))}%">★★★★★</i>★★★★★</span>`;

  async function post(url, data) {
    const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
    let json = null; try { json = await res.json(); } catch (e) { /* non-JSON error */ }
    if (!res.ok || !json || !json.ok) throw new Error(json && json.error ? json.error.message : 'Something went wrong. Please try again.');
    return json;
  }
  const avatar = (name, sm) => { const s = document.createElement('span'); s.className = 'review-avatar' + (sm ? ' sm' : ''); s.setAttribute('aria-hidden', 'true'); s.textContent = (String(name || 'C').trim()[0] || 'C').toUpperCase(); return s; };
  function likeBtn(type, id, liked, count, sm) {
    const b = document.createElement('button'); b.type = 'button'; b.className = 'like-btn' + (sm ? ' sm' : '') + (liked ? ' on' : '');
    b.dataset.like = type; b.dataset.id = id; b.setAttribute('aria-pressed', liked ? 'true' : 'false');
    const c = document.createElement('span'); c.textContent = count; b.append('👍 ', c); return b;
  }
  function replyNode(r) {
    const d = document.createElement('div'); d.className = 'review-reply'; d.dataset.replyNode = r.id; d.appendChild(avatar(r.author, true));
    const b = document.createElement('div'); b.className = 'reply-body';
    const nm = document.createElement('strong'); nm.textContent = r.author || 'Customer';
    const dt = document.createElement('small'); dt.textContent = fmtDate(r.created_at);
    const p = document.createElement('p'); p.textContent = r.body;
    b.append(nm, ' ', dt, p, likeBtn('reply', r.id, r.liked, r.likes, true)); d.appendChild(b); return d;
  }
  function replyForm(id) {
    const f = document.createElement('form'); f.className = 'reply-form'; f.dataset.replyForm = id; f.hidden = true;
    f.innerHTML = `<input type="hidden" name="csrf" value="${csrf}"><input type="hidden" name="review_id" value="${id}"><textarea name="body" rows="2" maxlength="2000" placeholder="Write a reply…" required></textarea><button class="button secondary" type="submit">Reply</button>`;
    return f;
  }
  function reviewNode(it) {
    const art = document.createElement('article'); art.className = 'pdp-review'; art.dataset.review = it.id;
    const head = document.createElement('header'); head.className = 'review-head'; head.appendChild(avatar(it.author, false));
    const who = document.createElement('div'); who.className = 'review-who';
    const nm = document.createElement('strong'); nm.textContent = it.author || 'Customer';
    const vr = document.createElement('small'); vr.textContent = 'Verified account'; who.append(nm, vr); head.appendChild(who);
    const tm = document.createElement('time'); tm.textContent = fmtDate(it.created_at); head.appendChild(tm); art.appendChild(head);
    const h4 = document.createElement('h4'); h4.textContent = it.title; art.appendChild(h4);
    const p = document.createElement('p'); p.textContent = it.body; art.appendChild(p);
    const rr = document.createElement('div'); rr.className = 'review-rating'; rr.innerHTML = `${starsHTML(it.rating_tenths)} <b>${(it.rating_tenths / 10).toFixed(1)}</b>`; art.appendChild(rr);
    const acts = document.createElement('div'); acts.className = 'review-actions'; acts.appendChild(likeBtn('review', it.id, it.liked, it.likes, false));
    const rb = document.createElement('button'); rb.type = 'button'; rb.className = 'reply-btn'; rb.dataset.reply = it.id; rb.textContent = 'Reply'; acts.appendChild(rb); art.appendChild(acts);
    const reps = document.createElement('div'); reps.className = 'review-replies'; reps.dataset.replies = it.id;
    (it.replies || []).forEach(r => reps.appendChild(replyNode(r))); art.appendChild(reps);
    art.appendChild(replyForm(it.id)); return art;
  }
  function applyReviews(data) {
    root.querySelector('.review-score strong').textContent = data.rating_tenths !== null ? (data.rating_tenths / 10).toFixed(1) : '0.0';
    const sc = root.querySelector('.review-score .stars'); if (sc) sc.outerHTML = starsHTML(data.rating_tenths || 0);
    const cnt = root.querySelector('[data-review-count]'); if (cnt) cnt.textContent = `(${data.total} ${data.total === 1 ? 'Review' : 'Reviews'})`;
    for (let s = 1; s <= 5; s++) {
      const span = root.querySelector(`[data-bar="${s}"]`); if (!span) continue;
      span.textContent = data.breakdown[s]; const bar = span.parentElement.querySelector('.bar i');
      if (bar) bar.style.width = (data.total ? Math.round(data.breakdown[s] / data.total * 100) : 0) + '%';
    }
    const list = root.querySelector('[data-review-list]'), empty = list.querySelector('[data-review-empty]');
    list.querySelectorAll('.pdp-review').forEach(n => n.remove());
    (data.items || []).forEach(it => list.appendChild(reviewNode(it)));
    if (empty) empty.hidden = !!(data.items && data.items.length);
  }

  const si = root.querySelector('[data-star-input]');
  if (si) {
    const hidden = si.querySelector('input[name=rating]'), fill = si.querySelector('.stars.input i');
    const setFill = v => { fill.style.width = (parseFloat(v) / 5 * 100) + '%'; };
    si.querySelectorAll('.star-hit button').forEach(b => {
      b.addEventListener('mouseenter', () => setFill(b.dataset.star));
      b.addEventListener('click', () => { hidden.value = b.dataset.star; setFill(b.dataset.star); });
    });
    si.querySelector('.star-hit').addEventListener('mouseleave', () => setFill(hidden.value));
  }
  const form = root.querySelector('[data-review-form]');
  if (form) form.addEventListener('submit', async e => {
    e.preventDefault(); const msg = form.querySelector('[data-review-msg]'); msg.className = 'form-msg'; msg.textContent = 'Saving…';
    try {
      const r = await post(root.dataset.reviewAction, { csrf: form.csrf.value, revision: form.revision.value, rating: form.rating.value, title: form.title.value, body: form.body.value });
      applyReviews(r.reviews); form.revision.value = String(Number(form.revision.value) + 1);
      form.querySelector('h3').textContent = 'Edit your review'; msg.textContent = 'Your review is published.';
    } catch (err) { msg.className = 'form-msg error'; msg.textContent = err.message; }
  });

  root.addEventListener('click', async e => {
    const like = e.target.closest('.like-btn[data-like]');
    if (like && root.contains(like)) {
      e.preventDefault();
      try { const r = await post(root.dataset.reactAction, { csrf, target_type: like.dataset.like, target_id: like.dataset.id }); like.classList.toggle('on', r.liked); like.setAttribute('aria-pressed', r.liked ? 'true' : 'false'); like.querySelector('span').textContent = r.count; } catch (err) { /* keep prior state */ }
      return;
    }
    const rep = e.target.closest('.reply-btn[data-reply]');
    if (rep && rep.dataset.reply) { e.preventDefault(); const f = root.querySelector(`[data-reply-form="${rep.dataset.reply}"]`); if (f) { f.hidden = !f.hidden; if (!f.hidden) f.querySelector('textarea').focus(); } }
  });
  root.addEventListener('submit', async e => {
    const f = e.target.closest('.reply-form[data-reply-form]'); if (!f) return; e.preventDefault();
    const ta = f.querySelector('textarea'), btn = f.querySelector('button'); btn.disabled = true;
    try { const r = await post(root.dataset.replyAction, { csrf: f.csrf.value, review_id: f.review_id.value, body: ta.value }); const cont = root.querySelector(`[data-replies="${f.dataset.replyForm}"]`); if (cont) cont.appendChild(replyNode(r.reply)); ta.value = ''; f.hidden = true; }
    catch (err) { alert(err.message); } finally { btn.disabled = false; }
  });
})();

/* Product info tabs: show one bordered panel at a time. Without JS all panels stay stacked and readable. */
(() => {
  'use strict';
  const tabs = [...document.querySelectorAll('.pdp-tabs [role=tab]')];
  if (!tabs.length) return;
  const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
  const activate = (id, focus) => tabs.forEach((tab, i) => {
    const on = tab.getAttribute('aria-controls') === id;
    tab.setAttribute('aria-selected', on ? 'true' : 'false');
    tab.tabIndex = on ? 0 : -1;
    if (panels[i]) panels[i].hidden = !on;
    if (on && focus) tab.focus();
  });
  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => activate(tab.getAttribute('aria-controls')));
    tab.addEventListener('keydown', event => {
      const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
      if (!step) return;
      event.preventDefault();
      activate(tabs[(i + step + tabs.length) % tabs.length].getAttribute('aria-controls'), true);
    });
  });
  // In-page links pointing at a panel (e.g. the rating link → reviews) open that tab.
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href^="#"]');
    if (!link) return;
    const id = link.getAttribute('href').slice(1);
    if (panels.some(panel => panel && panel.id === id)) activate(id);
  });
  const deep = panels.find(panel => panel && panel.id === location.hash.slice(1));
  activate(deep ? deep.id : tabs[0].getAttribute('aria-controls'));
})();

/* Store visit enquiry: capture name/contact/note, persist to the store, then lock the button per browser. */
(() => {
  'use strict';
  const dialog = document.querySelector('#enquiry-dialog');
  const form = document.querySelector('#enquiry-form');
  const buttons = [...document.querySelectorAll('[data-enquiry]')];
  if (!dialog || !form || !buttons.length) return;
  const key = 'sarada-enquiry-' + form.elements.product_id.value;
  const status = document.querySelector('#enquiry-status');
  const lock = () => buttons.forEach(b => { b.disabled = true; b.textContent = 'Already enquired ✓'; });
  try { if (localStorage.getItem(key)) lock(); } catch (_) { /* storage blocked: button stays active */ }
  buttons.forEach(b => b.addEventListener('click', () => { if (!b.disabled) dialog.showModal(); }));
  dialog.querySelector('[data-enquiry-close]').addEventListener('click', () => dialog.close());
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = form.querySelector('button.button');
    submit.disabled = true; status.textContent = ''; status.className = 'note';
    try {
      const res = await fetch(form.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) });
      const body = await res.json().catch(() => ({}));
      if (!res.ok || !body.ok) throw new Error((body.error && body.error.message) || 'Could not send the enquiry. Please check your details and try again.');
      try { localStorage.setItem(key, '1'); } catch (_) { /* best effort */ }
      lock(); dialog.close();
    } catch (err) { status.className = 'note error'; status.textContent = err.message; submit.disabled = false; }
  });
})();

/* Record this material in the browser's recently-viewed list (this browser only, no server). */
(() => {
  'use strict';
  const data = document.querySelector('#pdp-recent');
  if (!data) return;
  let item;
  try { item = JSON.parse(data.textContent); } catch (e) { return; }
  if (!item || !item.slug) return;
  try {
    let list = JSON.parse(localStorage.getItem('sarada-recent') || '[]');
    if (!Array.isArray(list)) list = [];
    list = list.filter(p => p && p.slug !== item.slug);
    list.unshift(item);
    localStorage.setItem('sarada-recent', JSON.stringify(list.slice(0, 12)));
  } catch (e) { /* storage blocked: skip */ }
})();
