<?php
$title = 'Inside KasaMood | Maa Sarada';
$page = 'catalog';
$pageStyles = ['catalogue-reader.css'];
$pageScripts = ['vendor/page-flip/page-flip.browser.js', 'catalogue-reader.js'];
$catalogueManifest = json_decode(file_get_contents(__DIR__ . '/../../../assets/storefront/assets/catalogs/kasamood/pages/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
require __DIR__ . '/header.php';
$pdfUrl = $a('assets/catalogs/kasamood/KasaMood.pdf');
?>
<div class="breadcrumb"><a href="<?= $u() ?>">Home</a><span>/</span><a href="<?= $u('catalogues') ?>">Catalogues</a><span>/</span><span>KasaMood</span></div>
<div class="page-intro"><div><span class="eyebrow">KAJARIA ETERNITY</span><h1>Inside the collection.</h1><p>Explore the original KasaMood brand catalogue.</p></div></div>
<div class="document-layout">
    <aside class="document-aside"><img src="<?= $a('assets/catalogs/kasamood/cover.png') ?>" alt="KasaMood cover"><h2>KasaMood collection</h2><p class="note"><?= $e($catalogueManifest['pdfPageCount']) ?> PDF pages · <?= $e($catalogueManifest['pageCount']) ?> reader pages · Edition 2025-03-07</p><a class="button" href="<?= $pdfUrl ?>" download>Download PDF ↓</a></aside>
    <section class="catalogue-reader" aria-label="KasaMood catalogue reader" data-catalogue-reader data-version="<?= $e($catalogueManifest['version']) ?>">
        <div class="collection-toolbar"><span>Original brand document</span><a href="<?= $pdfUrl ?>" target="_blank" rel="noopener">Open PDF ↗</a></div>
        <div class="catalogue-interactive" hidden>
            <div class="catalogue-stage"><div class="catalogue-book" aria-label="Catalogue pages">
                <?php foreach ($catalogueManifest['pages'] as $index => $filename): ?>
                    <div class="catalogue-sheet" data-density="soft"><span class="catalogue-page-number">Page <?= $index + 1 ?></span><img data-src="<?= $a('assets/catalogs/kasamood/pages/' . $filename . '?v=' . $catalogueManifest['version']) ?>" alt="KasaMood catalogue, page <?= $index + 1 ?>" decoding="async" draggable="false"></div>
                <?php endforeach; ?>
            </div></div>
            <div class="catalogue-controls" role="group" aria-label="Catalogue navigation">
                <button type="button" data-reader-prev aria-label="Previous pages">← Previous</button>
                <label>Page <input data-reader-page type="number" min="1" max="<?= $e($catalogueManifest['pageCount']) ?>" value="1" inputmode="numeric" aria-label="Go to page"> <span>/ <?= $e($catalogueManifest['pageCount']) ?></span></label>
                <button type="button" data-reader-next aria-label="Next pages">Next →</button>
                <button type="button" data-reader-zoom>Zoom page</button>
                <button type="button" data-reader-fullscreen hidden>Full screen</button>
            </div>
            <p class="catalogue-status" role="status" aria-live="polite"></p>
            <p class="catalogue-hint">Drag a page corner or swipe to turn. Use the page number to jump.</p>
        </div>
        <div data-reader-fallback>
            <object class="pdf-frame" data="<?= $pdfUrl ?>" type="application/pdf" aria-label="KasaMood catalogue"><p>Your browser cannot display this PDF. <a href="<?= $pdfUrl ?>">Open or download the catalogue.</a></p></object>
        </div>
        <dialog class="catalogue-zoom" aria-label="Enlarged catalogue page"><div class="catalogue-zoom-toolbar"><span data-zoom-label></span><button type="button" data-zoom-prev aria-label="Previous enlarged page">←</button><button type="button" data-zoom-next aria-label="Next enlarged page">→</button><button type="button" data-reader-close>Close ×</button></div><div class="catalogue-zoom-scroll"><img alt=""></div></dialog>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
