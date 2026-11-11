<?php
$title = $saved ? 'Saved photo designs | Maa Sarada' : 'Room visualizer | Maa Sarada';
$page = $saved ? 'designs' : 'visualizer';
$pageStyles = ['visualizer.css'];
$pageScripts = ['visualizer-renderer.js','visualizer-editor.js','visualizer-entry.js'];
require __DIR__ . '/header.php';
?>
<div class="breadcrumb"><a href="<?= $u() ?>">Home</a><span>/</span><span><?= $saved ? 'Saved photo designs' : 'Room visualizer' ?></span></div>
<div class="notice">Photo designs stay on this browser and device. No room photo is sent to the PHP server. Store materials use published product photographs; labelled samples are for planning only. Preview scale and colours are illustrative. <a href="<?= $u('visualizer/help') ?>">How to use the visualizer ↗</a></div>
<div id="visualizer-workspace"><?php if ($saved): ?><div class="page-intro"><div><span class="eyebrow">YOUR ROOM IDEAS</span><h1>Ideas to come back to.</h1><p>Photo designs saved in this browser for the current guest or account.</p></div><a class="button" href="<?= $u('visualizer') ?>">Create a room idea ↗</a></div><div class="empty-state"><h2>Your next space starts with an idea.</h2><p>Open the visualizer and save your first combination.</p></div><?php else: ?><div class="page-intro"><div><h1>See your stone at home.</h1><p>The interactive photo editor needs JavaScript and WebGL. Use a current browser with graphics acceleration enabled.</p></div></div><?php endif; ?></div>
<p id="tool-status" role="status" aria-live="polite"></p><noscript><p>Enable JavaScript to edit room photos or load locally saved designs. You can still <a href="<?= $u('shop') ?>">browse materials</a> or use the <a href="<?= $u('calculator') ?>">area calculator</a>.</p></noscript>
<script id="visualizer-config" type="application/json"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php require __DIR__ . '/footer.php'; ?>
