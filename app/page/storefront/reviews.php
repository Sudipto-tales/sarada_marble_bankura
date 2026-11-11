<?php $title='Reviews — '.$product['name'];$page='product';$pageStyles=['product-detail.css'];require __DIR__.'/header.php'; ?>
<div class="breadcrumb"><a href="<?= $u('product/'.$product['slug']) ?>"><?= $e($product['name']) ?></a><span>/</span><span>Customer reviews</span></div>
<section class="pdp-section"><h1>Ratings &amp; reviews</h1><?php require __DIR__.'/review_summary.php'; ?></section>
<section class="pdp-section" style="margin-top:24px"><h2><?= $own?'Edit your review':'Write a review' ?></h2>
<?php if(!$actor): ?><p><a class="text-button" href="<?= $u('account') ?>">Sign in</a> to write a review.</p><?php else: ?>
<p>Your first name and review are published immediately. Do not include contact or payment information.</p>
<form method="post" action="<?= $u('product/'.$product['slug'].'/reviews') ?>" class="pdp-review-form"><input type="hidden" name="csrf" value="<?= $e(RequestSecurity::csrfToken()) ?>"><input type="hidden" name="revision" value="<?= $e($own['revision']??0) ?>">
<label>Rating<select name="rating" required><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>" <?= (int)($own['rating']??5)===$i?'selected':'' ?>><?= $i ?> <?= $i===1?'star':'stars' ?></option><?php endfor; ?></select></label>
<label>Title<input name="title" required maxlength="120" value="<?= $e($own['title']??'') ?>"></label><label>Your experience<textarea name="body" required minlength="10" maxlength="2000" rows="5"><?= $e($own['body']??'') ?></textarea></label><button class="button">Submit for review</button></form>
<?php endif; ?></section>
<?php if($reviews['page']>1): ?><a class="text-button" href="?page=<?= $reviews['page']-1 ?>">← Previous</a><?php endif; ?> <?php if($reviews['page']<$reviews['pages']): ?><a class="text-button" href="?page=<?= $reviews['page']+1 ?>">Next reviews →</a><?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
