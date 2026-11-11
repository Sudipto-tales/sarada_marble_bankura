// Cart quantity steppers: nudge the native number input (which enforces
// min/step) and submit the row's update action. Enter and no-JS fall back
// to editing the input directly (the hidden Update button is the default).
document.querySelectorAll('[data-cart-qty]').forEach(group => {
  const input = group.querySelector('input[name=quantity]');
  const form = group.closest('form');
  const apply = () => form.querySelector('[data-cart-update]').click();
  group.querySelector('[data-dec]').addEventListener('click', () => { input.stepDown(); apply(); });
  group.querySelector('[data-inc]').addEventListener('click', () => { input.stepUp(); apply(); });
});
