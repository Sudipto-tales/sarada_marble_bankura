/*
 * AJAX Phase 1 — add to cart without a full page reload, over the WEB-03 /api/v1/cart JSON endpoint.
 * Progressive enhancement: every add-to-cart control is a real <form> that still works with JS off or
 * if the request fails (we fall back to a native submit). The endpoint re-validates price and stock, so
 * nothing here is authoritative. Delegated so it covers the PDP buy box, product cards and home bag icons.
 */
(() => {
  'use strict';

  // Shared polite live region, created once, for success/error feedback.
  let toastEl, toastTimer;
  const toast = (message, kind, href) => {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'sf-toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = '';
    const text = document.createElement('span');
    text.textContent = message;
    toastEl.appendChild(text);
    if (href) {
      const link = document.createElement('a');
      link.href = href;
      link.textContent = 'View bag ↗';
      toastEl.appendChild(link);
    }
    toastEl.className = 'sf-toast is-show' + (kind ? ' is-' + kind : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('is-show'), 4000);
  };

  // Mirror PHP StorefrontController::quantity(): milli → trimmed decimal.
  const qtyText = (milli, unit) => {
    const whole = Math.trunc(milli / 1000);
    const frac = String(milli % 1000).padStart(3, '0').replace(/0+$/, '');
    return (frac ? whole + '.' + frac : String(whole)) + (unit ? ' ' + unit : '');
  };

  // Mirror the PHP nav badge: cap at 99+, hide when empty.
  const updateCount = (count) => {
    document.querySelectorAll('[data-cart-count]').forEach((badge) => {
      const shown = Math.max(0, Math.min(99, count));
      badge.textContent = count > 99 ? '99+' : String(shown);
      badge.classList.toggle('is-zero', count < 1);
      badge.setAttribute('aria-hidden', count < 1 ? 'true' : 'false');
    });
  };

  const bagHref = () => {
    const link = document.querySelector('a[aria-label="Shopping bag"]');
    return link ? link.getAttribute('href') : null;
  };

  // Capture phase so we decide before other handlers; we only act on add-to-cart forms.
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    // Read the attribute, not form.action: an <input name="action"> shadows the form's .action property.
    let url;
    try { url = new URL(form.getAttribute('action') || location.href, location.href); } catch (_) { return; }
    if (!/\/cart$/.test(url.pathname)) return;
    const actionField = form.querySelector('[name=action]');
    if (!actionField || actionField.value !== 'add') return;
    const variant = form.querySelector('[name=variant_id]');
    const quantity = form.querySelector('[name=quantity]');
    const csrf = form.querySelector('[name=csrf]');
    if (!variant || !quantity || !csrf) return; // not shaped as expected: let it submit normally

    event.preventDefault();
    const endpoint = url.pathname.replace(/\/cart$/, '/api/v1/cart');
    const button = form.querySelector('button, [type=submit]');
    if (button) button.disabled = true;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.value, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'add', variant_id: String(variant.value), quantity: String(quantity.value) }),
      });
      let payload = null;
      try { payload = await response.json(); } catch (_) { /* non-JSON */ }
      if (!response.ok || !payload || !payload.data) {
        const message = payload && payload.error ? payload.error.message : 'Could not add this item to your bag.';
        toast(message, 'error');
        if (button) button.disabled = false;
        return;
      }
      const cart = payload.data;
      const items = cart.items || [];
      updateCount(items.length);
      const line = items.find((item) => String(item.variant_id) === String(variant.value));
      const label = line
        ? 'Added — ' + line.name + ' (' + qtyText(Number(line.qty_milli), line.sell_unit) + ')'
        : 'Added to your bag';
      toast(label, 'ok', bagHref());
    } catch (err) {
      // Network or unexpected failure: fall back to the real form so the customer still succeeds.
      if (button) button.disabled = false;
      form.submit();
      return;
    }
    if (button) button.disabled = false;
  }, true);
})();
