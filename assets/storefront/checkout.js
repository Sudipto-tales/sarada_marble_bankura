/* Live checkout: address/payment/coupon updates and inline add-address, server stays authoritative via the checkout key. */
(() => {
  'use strict';
  const root = document.querySelector('[data-checkout]');
  if (!root) return;
  const csrf = root.dataset.csrf, quoteUrl = root.dataset.quoteUrl, addressUrl = root.dataset.addressUrl, purchaseCart = root.dataset.purchaseCart || '';
  const $ = s => root.querySelector(s);
  const selForm = $('[data-selection-form]'), placeBtn = $('[data-place]'), placeHint = $('[data-place-hint]');
  const money = minor => { minor = Number(minor); const sign = minor < 0 ? '-' : ''; minor = Math.abs(minor); return sign + '₹' + Math.trunc(minor / 100).toLocaleString('en-IN') + '.' + String(minor % 100).padStart(2, '0'); };

  async function post(url, data) {
    const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
    let j = null; try { j = await res.json(); } catch (e) { /* non-JSON */ }
    if (!res.ok || !j || !j.ok) throw new Error(j && j.error ? j.error.message : 'Something went wrong. Please try again.');
    return j;
  }
  const picked = name => { const el = selForm.querySelector(`input[name=${name}]:checked`); return el ? el.value : ''; };
  const setSum = (k, v) => { const el = root.querySelector(`[data-sum="${k}"]`); if (!el) return; el.textContent = (k === 'shipping' && !Number(v)) ? 'Free' : money(v); };

  async function recompute() {
    const couponEl = $('[data-coupon]');
    const data = { csrf, purchase_cart: purchaseCart, address_id: picked('address_id'), payment_mode: picked('payment_mode'), coupon_code: couponEl ? couponEl.value : '' };
    let j;
    try { j = await post(quoteUrl, data); } catch (e) { return; }
    const b = j.breakdown;
    setSum('subtotal', b.subtotal_minor);
    const dRow = root.querySelector('[data-row="discount"]'); if (dRow) { dRow.hidden = !(b.discount_minor > 0); setSum('discount', -b.discount_minor); }
    setSum('cgst', b.cgst_minor); setSum('sgst', b.sgst_minor); setSum('shipping', b.shipping_minor); setSum('total', b.total_minor);
    const msg = $('[data-coupon-msg]');
    if (msg) { if (j.coupon_error) { msg.textContent = j.coupon_error; msg.classList.add('error'); } else { msg.classList.remove('error'); msg.textContent = j.coupon ? ('Coupon ' + j.coupon.code + ' applied') : ''; } }
    if (j.key && j.selection) {
      $('[data-key]').value = j.key; $('[data-place-address]').value = j.selection.address_id; $('[data-place-payment]').value = j.selection.payment_mode;
      placeBtn.disabled = false; placeHint.hidden = true;
    } else { $('[data-key]').value = ''; placeBtn.disabled = true; placeHint.hidden = false; }
  }

  selForm.addEventListener('change', e => { if (e.target.matches('[data-address],[data-payment]')) recompute(); });
  selForm.addEventListener('submit', e => { e.preventDefault(); recompute(); });
  const apply = $('[data-apply-coupon]'); if (apply) apply.addEventListener('click', recompute);
  const couponEl = $('[data-coupon]'); if (couponEl) couponEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); recompute(); } });

  // Inline add-address
  const addForm = $('[data-address-form]'), addList = $('[data-address-list]');
  const toggle = $('[data-add-toggle]'); if (toggle) toggle.addEventListener('click', () => { addForm.hidden = !addForm.hidden; });
  const cancel = $('[data-add-cancel]'); if (cancel) cancel.addEventListener('click', () => { addForm.hidden = true; });
  const save = $('[data-address-save]');
  if (save) save.addEventListener('click', async () => {
    const need = ['recipient', 'phone', 'line1', 'city', 'state', 'postal_code'];
    const data = { csrf, country_code: 'IN', is_default: addForm.querySelector('[name=is_default]').checked ? '1' : '0' };
    for (const f of ['recipient', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code']) { const el = addForm.querySelector(`[name=${f}]`); data[f] = el ? el.value.trim() : ''; }
    const msg = $('[data-address-msg]'); msg.className = 'form-msg';
    if (need.some(f => !data[f])) { msg.className = 'form-msg error'; msg.textContent = 'Please fill the required address fields.'; return; }
    msg.textContent = 'Saving…';
    let j; try { j = await post(addressUrl, data); } catch (e) { msg.className = 'form-msg error'; msg.textContent = e.message; return; }
    const a = j.address;
    const label = document.createElement('label'); label.className = 'address-option';
    label.innerHTML = '<input type="radio" name="address_id" required data-address><span class="address-card-inline"><strong></strong><small></small><small></small></span>';
    label.querySelector('input').value = a.id; label.querySelector('input').checked = true;
    label.querySelector('strong').textContent = a.recipient;
    label.querySelectorAll('small')[0].textContent = [a.line1, a.line2, a.city, a.state, a.postal_code].filter(Boolean).join(', ');
    label.querySelectorAll('small')[1].textContent = a.phone || '';
    addList.appendChild(label);
    const empty = root.querySelector('[data-address-empty]'); if (empty) empty.hidden = true;
    if (toggle) toggle.hidden = false;
    addForm.hidden = true; msg.textContent = '';
    addForm.querySelectorAll('input').forEach(i => { if (i.type === 'checkbox') i.checked = false; else if (i.name !== 'country_code') i.value = ''; });
    recompute();
  });

  recompute();
})();
