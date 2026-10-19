<?php
/** Shared review thread: rating summary, review list, likes and one level of replies. Expects $reviews and $actor. */
$stars = static fn($t): string => '<span class="stars"><i style="width:' . max(0, min(100, (int)$t * 2)) . '%">★★★★★</i>★★★★★</span>';
$initial = static fn($name): string => strtoupper(mb_substr(trim((string)$name) ?: 'C', 0, 1));
$csrfToken = RequestSecurity::csrfToken();
$loggedIn = !empty($actor);
?>
<div class="review-overview">
  <div class="review-score">
    <strong><?= $reviews['rating_tenths'] !== null ? number_format($reviews['rating_tenths'] / 10, 1) : '0.0' ?></strong><span>out of 5</span>
    <?= $stars($reviews['rating_tenths'] ?? 0) ?>
    <small data-review-count>(<?= $e($reviews['total']) ?> <?= $reviews['total'] === 1 ? 'Review' : 'Reviews' ?>)</small>
  </div>
  <div class="review-bars" data-review-bars>
    <?php for ($star = 5; $star >= 1; $star--): $pct = $reviews['total'] ? round($reviews['breakdown'][$star] / $reviews['total'] * 100) : 0; ?>
    <div><span><?= $star ?> Star</span><span class="bar"><i style="width:<?= $pct ?>%"></i></span><span data-bar="<?= $star ?>"><?= $e($reviews['breakdown'][$star]) ?></span></div>
    <?php endfor; ?>
  </div>
</div>
<div class="review-list" data-review-list>
  <p class="review-empty" data-review-empty<?= $reviews['items'] ? ' hidden' : '' ?>>No reviews yet. Be the first to share your experience.</p>
  <?php foreach ($reviews['items'] as $review): ?>
  <article class="pdp-review" data-review="<?= $e($review['id']) ?>">
    <header class="review-head">
      <span class="review-avatar" aria-hidden="true"><?= $e($initial($review['author'])) ?></span>
      <div class="review-who"><strong><?= $e($review['author'] ?: 'Customer') ?></strong><small>Verified account</small></div>
      <time><?= $e(gmdate('d M Y', (int)$review['created_at'])) ?></time>
    </header>
    <h4><?= $e($review['title']) ?></h4>
    <p><?= $e($review['body']) ?></p>
    <div class="review-rating"><?= $stars($review['rating_tenths']) ?> <b><?= number_format($review['rating_tenths'] / 10, 1) ?></b></div>
    <div class="review-actions">
      <?php if ($loggedIn): ?>
      <button type="button" class="like-btn<?= $review['liked'] ? ' on' : '' ?>" data-like="review" data-id="<?= $e($review['id']) ?>" aria-pressed="<?= $review['liked'] ? 'true' : 'false' ?>">👍 <span><?= $e($review['likes']) ?></span></button>
      <button type="button" class="reply-btn" data-reply="<?= $e($review['id']) ?>">Reply</button>
      <?php else: ?>
      <span class="like-btn off">👍 <span><?= $e($review['likes']) ?></span></span>
      <a class="reply-btn" href="<?= $u('account') ?>">Sign in to reply</a>
      <?php endif; ?>
    </div>
    <div class="review-replies" data-replies="<?= $e($review['id']) ?>">
      <?php foreach ($review['replies'] as $reply): ?>
      <div class="review-reply" data-reply-node="<?= $e($reply['id']) ?>">
        <span class="review-avatar sm" aria-hidden="true"><?= $e($initial($reply['author'])) ?></span>
        <div class="reply-body"><strong><?= $e($reply['author'] ?: 'Customer') ?></strong> <small><?= $e(gmdate('d M Y', (int)$reply['created_at'])) ?></small>
          <p><?= $e($reply['body']) ?></p>
          <?php if ($loggedIn): ?><button type="button" class="like-btn sm<?= $reply['liked'] ? ' on' : '' ?>" data-like="reply" data-id="<?= $e($reply['id']) ?>" aria-pressed="<?= $reply['liked'] ? 'true' : 'false' ?>">👍 <span><?= $e($reply['likes']) ?></span></button>
          <?php else: ?><span class="like-btn sm off">👍 <span><?= $e($reply['likes']) ?></span></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($loggedIn): ?>
    <form class="reply-form" data-reply-form="<?= $e($review['id']) ?>" hidden><input type="hidden" name="csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="review_id" value="<?= $e($review['id']) ?>"><textarea name="body" rows="2" maxlength="2000" placeholder="Write a reply…" required></textarea><button class="button secondary" type="submit">Reply</button></form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
