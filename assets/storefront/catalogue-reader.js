(() => {
    'use strict';
    const root = document.querySelector('[data-catalogue-reader]');
    if (!root || !window.St?.PageFlip) return;
    const interactive = root.querySelector('.catalogue-interactive');
    const fallback = root.querySelector('[data-reader-fallback]');
    const book = root.querySelector('.catalogue-book');
    const sheets = [...book.children];
    const images = sheets.map(page => page.querySelector('img'));
    const previous = root.querySelector('[data-reader-prev]');
    const next = root.querySelector('[data-reader-next]');
    const pageInput = root.querySelector('[data-reader-page]');
    const status = root.querySelector('.catalogue-status');
    const zoom = root.querySelector('.catalogue-zoom');
    const zoomButton = root.querySelector('[data-reader-zoom]');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    const storageKey = `sarada:catalogue:kasamood:${root.dataset.version}`;
    let startPage = 0;
    try { startPage = Math.max(0, Math.min(sheets.length - 1, Number(localStorage.getItem(storageKey)) || 0)); } catch (_) { /* Storage can be disabled. */ }
    let flip;
    let busy = false;
    let imageFailed = false;
    const loadNearby = index => {
        images.forEach((img, i) => {
            if (Math.abs(i - index) <= 4) {
                if (!img.hasAttribute('src')) img.src = img.dataset.src;
            } else img.removeAttribute('src');
        });
    };
    const refresh = () => {
        const index = flip.getCurrentPageIndex();
        const spread = flip.getOrientation() === 'landscape';
        const last = Math.min(index + (spread && index > 0 ? 1 : 0), sheets.length - 1);
        previous.disabled = busy || index === 0;
        next.disabled = busy || last >= sheets.length - 1;
        pageInput.disabled = busy;
        zoomButton.disabled = busy;
        pageInput.value = index + 1;
        sheets.forEach((sheet, i) => sheet.setAttribute('aria-hidden', String(i < index || i > last)));
        if (!imageFailed) status.textContent = last > index ? `Pages ${index + 1}–${last + 1} of ${sheets.length}` : `Page ${index + 1} of ${sheets.length}`;
        loadNearby(index);
        try { localStorage.setItem(storageKey, String(index)); } catch (_) { /* Reading still works. */ }
    };
    images.forEach(img => img.addEventListener('error', () => {
        imageFailed = true;
        status.textContent = 'A page could not load. Reopen the reader to retry, or use Open PDF above.';
    }));
    try {
        interactive.hidden = false;
        const stage = root.querySelector('.catalogue-stage');
        const height = Math.max(200, stage.clientHeight - 24);
        flip = new St.PageFlip(book, {
            width: Math.round(height * 594 / 846), height, size: 'stretch',
            minWidth: 320, maxWidth: 650, minHeight: 170, maxHeight: 925,
            startPage, showCover: true, usePortrait: true, autoSize: false,
            flippingTime: 750, maxShadowOpacity: 0.3,
            showPageCorners: !reducedMotion.matches,
            useMouseEvents: !reducedMotion.matches, mobileScrollSupport: false,
        });
        flip.on('flip', refresh);
        flip.on('changeOrientation', () => { if (flip.getPageCount()) refresh(); });
        flip.on('changeState', event => { busy = event.data !== 'read'; refresh(); });
        flip.on('init', refresh);
        loadNearby(startPage);
        flip.loadFromHTML(sheets);
        fallback.hidden = true;
        refresh();
    } catch (_) {
        interactive.hidden = true;
        fallback.hidden = false;
        return;
    }
    const navigate = direction => {
        if (busy || (direction < 0 ? previous.disabled : next.disabled)) return;
        if (reducedMotion.matches) {
            if (direction < 0) flip.turnToPrevPage(); else flip.turnToNextPage();
        } else if (direction < 0) flip.flipPrev(); else flip.flipNext();
        refresh();
    };
    previous.addEventListener('click', () => navigate(-1));
    next.addEventListener('click', () => navigate(1));
    pageInput.addEventListener('change', () => {
        const target = Number(pageInput.value);
        if (!busy && Number.isInteger(target) && target >= 1 && target <= sheets.length) flip.turnToPage(target - 1);
        refresh();
    });
    // Keep shortcuts local to the reader, and preserve number-input editing.
    root.tabIndex = 0;
    root.addEventListener('keydown', event => {
        if (event.target.matches('input') || zoom.open) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault(); navigate(event.key === 'ArrowLeft' ? -1 : 1);
        }
    });
    let zoomIndex = 0;
    const showZoomPage = index => {
        zoomIndex = Math.max(0, Math.min(images.length - 1, index));
        const img = zoom.querySelector('img');
        img.src = images[zoomIndex].dataset.src;
        img.alt = images[zoomIndex].alt;
        zoom.querySelector('[data-zoom-label]').textContent = `Page ${zoomIndex + 1} · Scroll to explore`;
        zoom.querySelector('[data-zoom-prev]').disabled = zoomIndex === 0;
        zoom.querySelector('[data-zoom-next]').disabled = zoomIndex === images.length - 1;
        zoom.querySelector('.catalogue-zoom-scroll').scrollTo(0, 0);
    };
    zoomButton.addEventListener('click', () => {
        showZoomPage(flip.getCurrentPageIndex());
        zoom.showModal();
    });
    zoom.querySelector('[data-zoom-prev]').addEventListener('click', () => showZoomPage(zoomIndex - 1));
    zoom.querySelector('[data-zoom-next]').addEventListener('click', () => showZoomPage(zoomIndex + 1));
    root.querySelector('[data-reader-close]').addEventListener('click', () => zoom.close());
    zoom.addEventListener('close', () => { zoom.querySelector('img').removeAttribute('src'); zoomButton.focus(); });
    const fullscreen = root.querySelector('[data-reader-fullscreen]');
    if (root.requestFullscreen && document.fullscreenEnabled) {
        fullscreen.hidden = false;
        fullscreen.addEventListener('click', async () => {
            try {
                if (document.fullscreenElement === root) await document.exitFullscreen();
                else await root.requestFullscreen();
            } catch (_) { status.textContent = 'Full screen is unavailable. You can continue reading here.'; }
        });
        document.addEventListener('fullscreenchange', () => {
            fullscreen.textContent = document.fullscreenElement === root ? 'Exit full screen' : 'Full screen';
        });
    }
})();
