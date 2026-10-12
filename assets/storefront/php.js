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
