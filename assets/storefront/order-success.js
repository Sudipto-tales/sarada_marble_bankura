/* Plays the bundled order-confirmation animation. No-op if the library or element is absent. */
(() => {
  'use strict';
  const el = document.getElementById('order-lottie');
  if (!el || typeof lottie === 'undefined' || !el.dataset.src) return;
  lottie.loadAnimation({ container: el, renderer: 'svg', loop: false, autoplay: true, path: el.dataset.src });
})();
