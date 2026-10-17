/* Reference homepage carousel. Product content is escaped by PHP/JSON and textContent. */
(() => {
  'use strict';
  const data = document.querySelector('#home-slides');
  if (!data) return;
  const slides = JSON.parse(data.textContent);
  const previous = document.querySelector('#h-prev'), next = document.querySelector('#h-next');
  let index = 0;
  function show(step) {
    index = (index + step + slides.length) % slides.length;
    const slide = slides[index];
    const title = document.querySelector('#h-hero-title');
    title.replaceChildren();
    slide.title.split('\n').forEach((line,i) => { if (i) title.append(document.createElement('br')); title.append(document.createTextNode(line)); });
    document.querySelector('#h-hero-description').textContent = slide.copy;
    document.querySelector('#h-hero-stone').src = slide.image;
    const link = document.querySelector('#h-hero-link');
    link.href = slide.href; link.textContent = slide.label;
    document.querySelector('#h-slide-number').textContent = String(index+1).padStart(2,'0');
    const progress = document.querySelector('#h-slide-progress');
    progress.style.width = `${100/slides.length}%`;
    progress.style.transform = `translateX(${index*100}%)`;
  }
  if (slides.length > 1) {
    previous.hidden = next.hidden = false;
    previous.addEventListener('click', () => show(-1)); next.addEventListener('click', () => show(1));
    show(0);
  }
})();
