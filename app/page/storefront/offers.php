<?php
$title = 'Stone offers — Maa Sarada'; $page = 'offers';
$pageStyles = ['offers.css'];
require __DIR__ . '/header.php';
$items = $products['items'];
// Materials whose cheapest variant is marked down drive the offer rows and the hero discount.
$offers = array_values(array_filter($items, static function (array $p): bool {
    foreach ($p['variants'] as $v) if ((int)$v['unit_price_minor'] === (int)$p['min_price_minor'] && (int)($v['original_price_minor'] ?? 0) > (int)$v['unit_price_minor']) return true;
    return false;
}));
$deals = $offers ?: array_slice($items, 0, 8);
$spotlight = $offers[0] ?? ($items[0] ?? null);
$bestPercent = 0;
foreach ($offers as $p) foreach ($p['variants'] as $v) {
    $orig = (int)($v['original_price_minor'] ?? 0); $cur = (int)$v['unit_price_minor'];
    if ($orig > $cur) $bestPercent = max($bestPercent, (int)round(($orig - $cur) / $orig * 100));
}
$photo = static fn(?array $p, string $tex): string => $p && $p['images'] ? $u($p['images'][0]['path']) : $a('assets/textures/marble/' . $tex . '.webp');
$materialLink = static fn(?array $p): string => $p ? $u('product/' . $p['slug']) : $u('shop');
?>
<section class="off-hero" aria-label="Current offers">
  <div class="off-leaf off-leaf-tl" aria-hidden="true"></div>
  <div class="off-leaf off-leaf-br" aria-hidden="true"></div>
  <div class="off-hero-copy">
    <span class="off-eyebrow">Seasonal stone event</span>
    <h1>Beautiful stone,<br><em>better value.</em></h1>
    <p>Hand-selected marble and granite at their best price of the season. Plan your space and save on the surfaces you love.</p>
    <?php if ($bestPercent > 0): ?><span class="off-chip">Up to <?= $bestPercent ?>% off selected materials</span><?php endif; ?>
    <a class="button off-cta-btn" href="#off-deals">Shop the offers →</a>
  </div>
  <div class="off-hero-art">
    <span class="off-script" aria-hidden="true">Save beautifully ♥</span>
    <figure class="off-frame"><img src="<?= $photo($spotlight, 'calacatta_gold') ?>" alt="<?= $spotlight ? $e($spotlight['name']) : 'Featured stone' ?>" loading="eager">
      <?php if ($spotlight): ?><figcaption><strong><?= $e($spotlight['name']) ?></strong><span>From <?= $m($spotlight['min_price_minor']) ?></span></figcaption><?php endif; ?></figure>
  </div>
</section>

<section class="off-categories" aria-label="Shop offers by category">
  <div class="off-head"><span class="off-eyebrow">Shop by category</span><h2>Where to begin.</h2></div>
  <div class="off-category-grid">
    <?php foreach ($categoryCards as $i => $category): $tags = ['Comfort meets style', 'Gather. Dine. Connect.', 'Your perfect retreat', 'Work in style']; ?>
    <a class="off-category" href="<?= $u('category/' . $category['slug']) ?>">
      <img src="<?= $category['preview'] ? $u($category['preview']) : $a('assets/textures/marble/carrara_white.webp') ?>" alt="" loading="lazy">
      <span class="off-category-body"><strong><?= $e($category['name']) ?></strong><small><?= $e($tags[$i] ?? $category['material_count'] . ' materials') ?></small></span>
      <span class="off-arrow" aria-hidden="true">→</span>
    </a>
    <?php endforeach; ?>
    <?php if (!$categoryCards): ?><a class="off-category" href="<?= $u('shop') ?>"><img src="<?= $a('assets/textures/marble/carrara_white.webp') ?>" alt="" loading="lazy"><span class="off-category-body"><strong>Explore materials</strong><small>Browse the full collection</small></span><span class="off-arrow" aria-hidden="true">→</span></a><?php endif; ?>
  </div>
</section>

<section class="off-deals" id="off-deals" aria-label="Featured offers">
  <div class="off-head"><span class="off-eyebrow">Featured offers</span><h2>This season's best.</h2><a class="off-head-link" href="<?= $u('shop') ?>">View all ↗</a></div>
  <span class="off-note" aria-hidden="true">Quality<br>Design<br>Lasting<br>Value ♥</span>
  <div class="stone-grid off-deal-grid"><?php foreach (array_slice($deals, 0, 8) as $product) require __DIR__ . '/product_card.php'; ?></div>
</section>

<section class="off-why" aria-label="Why shop with Maa Sarada">
  <div class="off-why-panel">
    <span class="off-eyebrow light">Why Maa Sarada?</span>
    <h2>More than<br>a material.</h2>
    <div class="off-why-grid"><?php foreach ([['award', 'Curated stone', 'Hand-selected quality'], ['grid', 'Reliable delivery', 'Planned to your site'], ['slab', 'Secure checkout', '100% protected'], ['heart', 'Guidance & support', 'From shortlist to home']] as [$glyph, $label, $copy]): ?>
      <div class="off-why-item"><span class="off-why-icon"><?= $icon($glyph) ?></span><strong><?= $e($label) ?></strong><small><?= $e($copy) ?></small></div>
    <?php endforeach; ?></div>
    <span class="off-script light" aria-hidden="true">Your home, our priority ♥</span>
  </div>
  <div class="off-transform">
    <img src="<?= $a('assets/images/rooms/sunlit_living.webp') ?>" alt="Stone interior inspiration" loading="lazy">
    <div class="off-transform-card">
      <h3>Ready to transform your space?</h3>
      <p>Discover surfaces that fit your style and your life.</p>
      <a class="button" href="<?= $u('shop') ?>">Shop now →</a>
    </div>
  </div>
</section>

<section class="off-closing" aria-label="Plan your project">
  <span class="off-script" aria-hidden="true">Make it your home ♥</span>
  <h2>Pair your offer with a plan.</h2>
  <p>Estimate coverage, picture the finished room, and shortlist your favourites before you commit.</p>
  <div class="off-closing-actions"><a class="button" href="<?= $u('calculator') ?>">Plan your area ↗</a><a class="button secondary" href="<?= $u('visualizer') ?>">Visualize your room ↗</a></div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
