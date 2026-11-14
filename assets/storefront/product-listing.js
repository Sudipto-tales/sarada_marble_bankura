/*
 * Shop filtering & pagination without a full-page reload. Progressive enhancement over the real
 * GET filter form and <a> pager: with JS off (or on failure) both still navigate normally. We fetch
 * the same /shop URL with ?partial=1 (an XHR header too), which returns just the results block, and
 * swap it into #catalog-results. The URL is kept in sync with history so back/forward and sharing work.
 */
(() => {
  'use strict';

  const results = document.getElementById('catalog-results');
  const form = document.getElementById('store-filters');
  if (!results || !form) return;

  // $u('shop') — same action the form would POST-less submit to; resolves against the app base path.
  const shopAction = form.getAttribute('action') || location.pathname;
  let busy = false;

  const setBusy = (on) => {
    busy = on;
    results.classList.toggle('is-loading', on);
    results.setAttribute('aria-busy', on ? 'true' : 'false');
  };

  const scrollToResults = () => {
    const anchor = results.querySelector('.collection-toolbar') || results;
    const top = anchor.getBoundingClientRect().top + window.pageYOffset - 90;
    if (top < window.pageYOffset) window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
  };

  async function load(rawUrl, push) {
    if (busy) return;
    let target;
    try { target = new URL(rawUrl, location.href); } catch (_) { window.location.href = rawUrl; return; }

    const fetchUrl = new URL(target.href);
    fetchUrl.searchParams.set('partial', '1');

    setBusy(true);
    try {
      const response = await fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error('bad status ' + response.status);
      results.innerHTML = await response.text();
      if (push) history.pushState({ shop: true }, '', target.pathname + target.search);
      scrollToResults();
    } catch (err) {
      // Network/parse failure: fall back to a real navigation so the customer still gets results.
      window.location.href = target.href;
      return;
    } finally {
      setBusy(false);
    }
  }

  // Apply filters: turn the form state into a clean query string (drop empties), fetch, push URL.
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const clean = new URLSearchParams();
    for (const [key, value] of new FormData(form)) {
      if (typeof value === 'string' && value !== '') clean.append(key, value);
    }
    const query = clean.toString();
    load(shopAction + (query ? '?' + query : ''), true);
  });

  // Pager links live inside the swapped block, so delegate from the stable container.
  results.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-page-link]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    event.preventDefault();
    load(link.getAttribute('href'), true);
  });

  window.addEventListener('popstate', () => load(location.href, false));
})();
