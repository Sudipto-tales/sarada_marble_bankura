<?php
/** Standalone one-time setup page. Self-contained: no admin layout, no external assets. */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$done = $done ?? false;
$result = $result ?? null;
$error = $error ?? null;
$email = $email ?? '';
$token = $token ?? '';
$csrf = $csrf ?? '';
$action = $action ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Store setup</title>
<style>
  :root { color-scheme: light dark; --bg:#f4f5f7; --card:#fff; --ink:#1f2430; --muted:#5a6473; --line:#dce0e6; --brand:#0d6efd; --ok:#0f7b4f; --okbg:#e7f6ee; --err:#b3261e; --errbg:#fdecea; }
  @media (prefers-color-scheme: dark) { :root { --bg:#14171c; --card:#1e232b; --ink:#e8ebf0; --muted:#9aa4b2; --line:#2c333d; --okbg:#133224; --errbg:#3a1613; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; display:flex; min-height:100vh; align-items:center; justify-content:center; padding:16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; max-width:520px; width:100%; padding:28px; box-shadow:0 10px 40px rgba(0,0,0,.08); }
  h1 { margin:0 0 4px; font-size:1.4rem; }
  p.lede { margin:0 0 20px; color:var(--muted); }
  label { display:block; margin:0 0 14px; font-weight:600; }
  input[type=email], input[type=password] { display:block; width:100%; margin-top:6px; padding:11px 12px; font-size:1rem; border:1px solid var(--line); border-radius:9px; background:var(--bg); color:var(--ink); }
  small { display:block; font-weight:400; color:var(--muted); margin-top:5px; }
  button { width:100%; padding:12px; font-size:1rem; font-weight:600; color:#fff; background:var(--brand); border:0; border-radius:9px; cursor:pointer; }
  button:hover { filter:brightness(.95); }
  .box { border-radius:9px; padding:12px 14px; margin:0 0 18px; font-size:.95rem; }
  .box.ok { background:var(--okbg); color:var(--ok); }
  .box.err { background:var(--errbg); color:var(--err); }
  ul { margin:8px 0 0; padding-left:18px; color:var(--muted); }
  code { background:var(--bg); padding:1px 5px; border-radius:5px; }
</style>
</head>
<body>
<main class="card">
<?php if ($done): ?>
  <h1>Setup complete</h1>
  <div class="box ok">
    Admin account <strong><?= $e($email) ?></strong> created, and the Kajaria catalog was imported.
  </div>
  <p>Import summary:</p>
  <ul>
    <li><strong><?= $e((int)($result['created_products'] ?? 0)) ?></strong> products added</li>
    <li><strong><?= $e((int)($result['skipped_products'] ?? 0)) ?></strong> already present</li>
    <li><strong><?= $e((int)($result['created_images'] ?? 0)) ?></strong> images copied</li>
  </ul>
  <p style="margin-top:18px">Products were imported as <strong>hidden drafts</strong> with no price or stock. Sign in to publish them:</p>
  <p><a href="<?= $e(Setup::url('admin')) ?>">Go to the admin panel →</a></p>
  <div class="box err" style="margin-top:18px">
    <strong>Now lock this down:</strong> remove <code>COMMERCE_SETUP_TOKEN</code> from the server <code>.env</code>. This installer already refuses to run again, and clearing the token makes <code>/setup</code> return 404.
  </div>
<?php else: ?>
  <h1>One-time store setup</h1>
  <p class="lede">Create the first administrator and import the starter Kajaria catalog. This page disables itself after it runs once.</p>
  <?php if ($error): ?><div class="box err"><?= $e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= $e($action) ?>" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <label>Administrator email
      <input type="email" name="email" value="<?= $e($email) ?>" maxlength="190" required autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password">
      <small>12 to 72 characters. You will use this to sign in at /admin.</small>
    </label>
    <label>Confirm password
      <input type="password" name="confirm" minlength="12" maxlength="72" required autocomplete="new-password">
    </label>
    <button type="submit">Create admin &amp; import catalog</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
