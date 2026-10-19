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

/* Recently viewed rail: filled from localStorage written on product pages (this browser only). */
(() => {
  'use strict';
  const section = document.querySelector('#recently-viewed');
  if (!section) return;
  let list;
  try { list = JSON.parse(localStorage.getItem('sarada-recent') || '[]'); } catch (e) { return; }
  if (!Array.isArray(list) || !list.length) return;
  const row = section.querySelector('[data-recent-row]');
  const base = section.dataset.productBase || '';
  list.slice(0, 6).forEach(p => {
    if (!p || !p.slug) return;
    const href = base + encodeURIComponent(p.slug);
    const art = document.createElement('article'); art.className = 'h-product';
    const link = document.createElement('a'); link.className = 'h-product-image'; link.href = href;
    if (p.image) {
      const img = document.createElement('img'); img.src = p.image; img.alt = p.name || ''; img.loading = 'lazy'; link.appendChild(img);
    } else {
      const ph = document.createElement('span'); ph.className = 'material-placeholder'; ph.textContent = 'Product photograph'; link.appendChild(ph);
    }
    art.appendChild(link);
    const h3 = document.createElement('h3'); const title = document.createElement('a'); title.href = href; title.textContent = p.name || 'Material'; h3.appendChild(title); art.appendChild(h3);
    const prices = document.createElement('div'); prices.className = 'h-prices';
    const strong = document.createElement('strong'); strong.textContent = p.price || '';
    if (p.unit) { const unit = document.createElement('small'); unit.textContent = ' / ' + p.unit; strong.appendChild(unit); }
    prices.appendChild(strong); art.appendChild(prices);
    row.appendChild(art);
  });
  section.hidden = false;
})();
