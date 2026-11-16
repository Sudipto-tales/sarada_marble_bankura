<?php $module='products';$title=$product?'Edit Product':'Add Product';$description='Product details and original product photos.';require __DIR__.'/header.php';$editable=$can('admin.products.edit'); ?>
<?php
$enabledVariants = $product ? array_filter($product['variants'], static fn(array $variant): bool => (bool)$variant['enabled']) : [];
$published = $product && $product['status'] === 'active' && $enabledVariants
    && array_filter($taxonomies['categories'], static fn(array $category): bool => (int)$category['id'] === (int)$product['category_id'] && (bool)$category['enabled'])
    && (!$product['brand_id'] || array_filter($taxonomies['brands'], static fn(array $brand): bool => (int)$brand['id'] === (int)$product['brand_id'] && (bool)$brand['enabled']));
$visualizable = $published && array_filter($product['images'], static fn(array $image): bool => $image['variant_id'] === null
    || in_array((int)$image['variant_id'], array_map('intval', array_column($enabledVariants, 'id')), true));
// Storefront-readiness checklist (reflects saved / submitted values; category and status update live via admin.js).
$readyCategory = ($values['category_id'] ?? '') !== '';
$readyVariant = (bool)array_filter($rows, static fn($row): bool => is_array($row) && (string)($row['enabled'] ?? '1') === '1' && trim((string)($row['sku'] ?? '')) !== '' && (float)($row['price'] ?? 0) > 0);
$readyMedia = $product ? (bool)$product['images'] : false;
$readyStatus = ($values['status'] ?? 'active') === 'active';
$readyScore = (int)$readyCategory + (int)$readyVariant + (int)$readyMedia + (int)$readyStatus;
?>
<section class="panel"><div class="panel-heading"><div><h2>Basic information</h2><p>Core identity and surface details shown on the product page.</p></div><span class="pill"><?= $product?'Revision '.$e($product['revision']):'NEW MATERIAL' ?></span></div><div class="form-grid"><?php $field('name','Material name',$values['name']??'','text','required maxlength="190"');$field('slug','URL slug',$values['slug']??'','text','required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*"'); ?>
    <label class="field full"><span>Description</span><textarea name="description" rows="5" maxlength="10000"><?= $e($val($values['description']??'')) ?></textarea></label>
    <?php foreach(['colour'=>'Colour','origin'=>'Origin'] as $name=>$label)$field('details['.$name.']',$label,$values['details'][$name]??'','text'); ?>
    <?php $applications=$values['details']['applications']??''; if(is_array($applications))$applications=implode("\n",$applications); ?><label class="field full"><span>Applications (one per line, maximum 12)</span><textarea name="details[applications]" rows="3"><?= $e($val($applications)) ?></textarea></label>
    </div></section>

    <section class="panel">
  <div class="panel-heading">
    <div>
      <h2>Pricing & Dimensions</h2>
      <p>Price is in Indian Rupees. Dimensions are in millimetres.</p>
    </div>
  </div>
  <div class="form-grid three">
    <label class="field">
      <span>Price (₹) *</span>
      <input type="number" name="price_minor" step="0.01" min="0" required value="<?= $values['price_minor'] ?? '' ?>">
    </label>

    <label class="field">
      <span>Sell Unit *</span>
      <select name="sell_unit" required>
        <option value="sqft" <?= ($values['sell_unit']??'sqft')==='sqft'?'selected':'' ?>>Square feet</option>
        <option value="slab" <?= ($values['sell_unit']??'sqft')==='slab'?'selected':'' ?>>Slab</option>
        <option value="box" <?= ($values['sell_unit']??'sqft')==='box'?'selected':'' ?>>Box</option>
      </select>
    </label>

    <label class="field">
      <span>Thickness (mm)</span>
      <input type="number" name="thickness_mm_milli" step="0.001" min="0" value="<?= $values['thickness_mm_milli'] ?? '' ?>">
    </label>

    <label class="field">
      <span>Width (mm)</span>
      <input type="number" name="width_mm_milli" step="0.001" min="0" value="<?= $values['width_mm_milli'] ?? '' ?>">
    </label>

    <label class="field">
      <span>Length (mm)</span>
      <input type="number" name="length_mm_milli" step="0.001" min="0" value="<?= $values['length_mm_milli'] ?? '' ?>">
    </label>
  </div>
</section>

<section class="panel"><div class="panel-heading"><div><h2>Shipping & delivery logistics</h2><p>Configure lead times and serviceable PIN codes. Empty fields stay hidden on the storefront.</p></div></div><div class="form-grid">
    <?php foreach(['delivery_min_days'=>'Estimated delivery: minimum days','delivery_max_days'=>'Estimated delivery: maximum days'] as $name=>$label)$field('details['.$name.']',$label,$values['details'][$name]??'','number','min="1" max="365"'); ?>
    <?php $pincodes=$values['details']['delivery_pincodes']??''; if(is_array($pincodes))$pincodes=implode("\n",$pincodes); ?><label class="field full"><span>Supported delivery PIN codes (one per line, maximum 200)</span><textarea name="details[delivery_pincodes]" rows="3"><?= $e($val($pincodes)) ?></textarea></label>
    <?php $installation=$values['details']['installation_policy']??''; if(is_array($installation))$installation=implode("\n",$installation); ?><label class="field full"><span>Installation policy</span><textarea name="details[installation_policy]" rows="3"><?= $e($val($installation)) ?></textarea></label>
    </div><p class="help-text">Delivery estimates apply only to the exact configured PIN codes; shipping charges and taxes still come from checkout policy.</p></section>

<section class="panel"><div class="panel-heading"><div><h2>Quality, policies & merchandising</h2><p>Publish only verified material information and approved store policies. Empty fields remain hidden.</p></div></div><div class="form-grid">
    <?php foreach(['quality_policy'=>'Quality / grading policy','replacement_policy'=>'Replacement policy'] as $name=>$label): $detail=$values['details'][$name]??''; if(is_array($detail))$detail=implode("\n",$detail); ?><label class="field full"><span><?= $e($label) ?></span><textarea name="details[<?= $e($name) ?>" rows="3"><?= $e($val($detail)) ?></textarea></label><?php endforeach; ?>
    <?php $field('details[companion_product_id]','Companion product ID (optional)',$values['details']['companion_product_id']??'','text'); ?>
    <label class="field"><span>Product catalogue</span><select name="details[catalogue_slug]"><option value="">No mapped catalogue</option><option value="kasamood" <?= ($values['details']['catalogue_slug']??'')==='kasamood'?'selected':'' ?>>KasaMood (existing local PDF)</option></select></label>
    </div><p class="help-text">A companion ID creates a curated pairing without a bundle discount. Original prices are optional reference prices; the current price remains the amount charged.</p><?php if($product): ?><a class="text-button" href="<?= $u('admin/product-reviews') ?>">Moderate customer reviews ↗</a><?php endif; ?></section>

  </div>

  <!-- STICKY SIDEBAR -->
  <aside class="product-side">

    <section class="panel"><div class="panel-heading"><div><h2>Publishing & visibility</h2></div></div><div class="form-grid single">
    <label class="field"><span>Publication status</span><select name="status"><?php foreach(['active'=>'Published','draft'=>'Draft','archived'=>'Archived'] as $status=>$label): ?><option value="<?= $status ?>" <?= ($values['status']??'active')===$status?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Featured material</span><select name="featured"><option value="0" <?= ($values['featured']??0)==0?'selected':'' ?>>No</option><option value="1" <?= ($values['featured']??0)==1?'selected':'' ?>>Yes</option></select></label>
    </div>
    <div class="readiness" id="readiness"><div class="readiness-head"><span>Storefront readiness</span><span class="readiness-score"><?= $readyScore ?> / 4 ready</span></div><ul>
      <li data-check="category" class="<?= $readyCategory?'done':'' ?>"><span class="dot">✓</span><span>Category assigned</span></li>
      <li data-check="variant" class="<?= $readyVariant?'done':'' ?>"><span class="dot">✓</span><span>Price set</span></li>
      <li data-check="media" class="<?= $readyMedia?'done':'' ?>"><span class="dot">✓</span><span>Surface photo for Room Visualizer</span></li>
      <li data-check="status" class="<?= $readyStatus?'done':'' ?>"><span class="dot">✓</span><span>Status set to Published</span></li>
    </ul></div></section>

    <section class="panel"><div class="panel-heading"><div><h2>Organization & discovery</h2></div></div><div class="form-grid single">
    <label class="field"><span>Category *</span><select name="category_id" required><option value="">Select category</option><?php foreach($taxonomies['categories'] as $category): ?><option value="<?= $e($category['id']) ?>" <?= (string)$category['id']===(string)($values['category_id']??'')?'selected':'' ?>><?= $e($category['name']) ?><?= $category['enabled']?'':' (disabled)' ?></option><?php endforeach; ?></select><?php if(isset($errors['category_id'])): ?><small class="field-error"><?= $e($errors['category_id']) ?></small><?php endif; ?></label>
    <label class="field"><span>Brand</span><select name="brand_id"><option value="">No brand</option><?php foreach($taxonomies['brands'] as $brand): ?><option value="<?= $e($brand['id']) ?>" <?= (string)$brand['id']===(string)($values['brand_id']??'')?'selected':'' ?>><?= $e($brand['name']) ?><?= $brand['enabled']?'':' (disabled)' ?></option><?php endforeach; ?></select></label>
    <?php $field('tags','Search tags',$values['tags']??'','text','maxlength="1000"'); ?>
    </div><?php if(!$taxonomies['categories']): ?><p class="notice">Create a category before saving your first material. <a href="<?= $u('admin/categories') ?>">Add category ↗</a></p><?php endif; ?></section>

    <section class="panel"><div class="panel-heading"><div><h2>Payment & checkout rules</h2></div></div><div class="form-grid single">
    <?php $field('advance_rate','Advance payment % (blank inherits category/store, 0 = full payment upfront)',$values['advance_rate']??'','number','min="0" max="100" step="0.01"','advance_rate'); ?>
    </div></section>

  </aside>
</div>
<?php if(!$product): ?><section class="panel product-photo-entry">
  <div class="panel-heading">
    <div>
      <h2>Product Photographs</h2>
      <p>Upload clear surface photos. These will appear on the product page and in the Room Visualizer.</p>
    </div>
    <span class="pill">UP TO 12 IMAGES</span>
  </div>

  <div class="image-upload-zone">
    <div class="upload-drop-area" id="image-drop-area">
      <div class="upload-icon">📷</div>
      <p>Drag & drop images here or click to browse</p>
      <small>JPEG, PNG or WebP • Max 4 MB each • Up to 12 images</small>
      <input type="file" name="image[]" accept="image/jpeg,image/png,image/webp" multiple hidden id="image-input">
    </div>

    <div class="image-preview-grid" id="image-preview-grid"></div>
  </div>
</section><?php endif; ?>
<div class="form-actions sticky-actions"><p><?= $editable?'Review details before saving.':'Your account has read-only access.' ?></p><?php if($editable): ?><button class="button" type="submit">Save material</button><?php endif; ?><a class="button secondary" href="<?= $u('admin/products') ?>">Back to materials</a></div><input type="hidden" name="form_complete" value="1"></fieldset></form>
<?php if($product): ?><section class="panel product-photo-entry">
  <div class="panel-heading">
    <div>
      <h2>Product Photographs</h2>
      <p>Up to 12 images. Select multiple files, add another file picker, or paste image URLs. The original image files are kept without conversion.</p>
    </div>
    <span class="pill"><?= count($product['images']) ?>/12 IMAGES</span>
  </div>

  <div class="image-grid">
    <?php foreach($product['images'] as $image): ?>
    <article>
      <img src="<?= $u($image['path']) ?>" alt="<?= $e($image['alt_text']) ?>">
      <p><?= $e($image['alt_text']) ?></p>
      <?php if($editable): ?>
      <form method="post" action="<?= $u('admin/product/image') ?>" data-confirm="Remove this product image?">
        <?php $hidden(['csrf'=>$csrf,'id'=>$product['id'],'action'=>'remove','image_id'=>$image['id'],'operation_key'=>$key()]); ?>
        <button class="text-button danger">Remove image</button>
      </form>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
    </div>
    <?php if($editable): ?>
    <?php if(ProductImageService::available()): ?>
    <form method="post" action="<?= $u('admin/product/image') ?>" enctype="multipart/form-data" data-validate>
      <div class="form-grid"><div class="field"><span>Upload image files</span><div class="product-image-inputs" data-product-image-inputs><input type="file" name="image[]" accept="image/jpeg,image/png,image/webp" multiple><button class="text-button" type="button" data-add-product-image>＋ Add another file picker</button></div><small>JPEG, PNG or WebP. Select several files in each picker. Maximum 4 MB and 12 megapixels per image.</small></div>
      <?php if(ProductImageService::urlImportAvailable()): ?>
      <label class="field"><span>Or paste image URLs</span><textarea name="image_urls" rows="3" maxlength="2200" placeholder="https://example.com/surface.jpg&#10;One URL per line"></textarea><small>Public http(s) links only. Images are saved on this site, not hot-linked.</small></label>
      <?php endif; ?><?php $field('alt_text','Image description (optional, applied to each)',null,'text','maxlength="190"'); ?></form>
      <?php else: ?>
      <p class="notice">Image upload needs PHP Imagick or GD and the fileinfo extension. Ask the server operator to enable one of those image extensions, then reload this page.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if($product&&$productAnalytics): ?>
    <section class="panel product-analytics-panel"><div class="panel-heading"><div><h2>Product Analytics</h2><p>Views, net orders, paid revenue and conversion over the last 30 days.</p></div><span class="pill">UTC · LAST 30 DAYS</span></div>
    <div class="product-analytics-grid"><article class="product-analytics-stat"><span>Product views</span><strong><?= $e(number_format($productAnalytics['views'])) ?></strong></article><article class="product-analytics-stat"><span>Net orders</span><strong><?= $e(number_format($productAnalytics['orders'])) ?></strong></article><article class="product-analytics-stat"><span>Paid revenue</span><strong><?= $m($productAnalytics['revenue_minor']) ?></strong></article><article class="product-analytics-stat"><span>View to order</span><strong><?= $e(AdminReadService::decimal($productAnalytics['conversion_bps'],2)) ?>%</strong></article></div>
    <div class="panel-heading product-chart-heading"><div><h3>Daily views and net orders</h3><p>Event aggregates may update after the background metrics worker runs.</p></div></div><div class="admin-analytics-chart product-analytics-chart" id="product-analytics-chart" role="img" aria-label="Daily product views and orders for the last 30 days" data-chart-series="<?= $e(json_encode($productAnalytics['trend'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></div></section>
    <?php endif; ?>
    <?php require __DIR__.'/footer.php'; ?>