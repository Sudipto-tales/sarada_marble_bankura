/* Estimates are advisory; PHP cart/checkout remain authoritative. */
(() => {
  'use strict';
  const picker = document.querySelector('#variant-picker');
  if (!picker) return;
  const forms = [...document.querySelectorAll('.pdp-purchase form[data-variant-content]')];
  const money = value => {
    const major = (value / 100n).toLocaleString('en-IN');
    return `INR ${major}.${String(value % 100n).padStart(2, '0')}`;
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
