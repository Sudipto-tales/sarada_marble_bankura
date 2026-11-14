<?php
/**
 * Storefront invoice (print-first, standalone document).
 * Modern-branded layout: teal header band, status badge, billed-to / order cards,
 * line-item table, accent totals panel. Served from order/{id}/invoice via Cart::invoice().
 */
$e = [StorefrontController::class, 'escape'];
$m = [StorefrontController::class, 'money'];
$q = [StorefrontController::class, 'quantity'];
$u = static fn($path) => $e(StorefrontController::url($path));

// Store identity (env-configurable; GSTIN/phone/address shown only when set so we never fake a tax invoice).
$brand    = env('MAIL_FROM_NAME', 'Maa Sarada Marble & Sanitation');
$legal    = env('STORE_LEGAL_NAME', $brand);
$storeMail = env('STORE_EMAIL', env('MAIL_FROM_ADDRESS', ''));
$storePhone = env('STORE_PHONE', '');
$storeAddr = env('STORE_ADDRESS', '');
$gstin    = env('STORE_GSTIN', '');
$isTaxInvoice = $gstin !== '';

// Money / status helpers.
$tax  = (int)($order['tax_minor'] ?? 0);
$cgst = intdiv($tax, 2);
$sgst = $tax - $cgst;
$due  = (int)($order['amount_due_minor'] ?? $order['total_minor']);
$balance = (int)$order['total_minor'] - $due;
$label = static fn($s): string => $s === 'outForDelivery' ? 'Out for delivery' : ucfirst(str_replace('_', ' ', (string)$s));
$halfRate = rtrim(rtrim(number_format(((int)($order['tax_bps'] ?? 0)) / 200, 2, '.', ''), '0'), '.');

$payStatus = (string)$order['payment_status'];
$payClass = in_array($payStatus, ['paid', 'advance_paid', 'simulated'], true) ? 'is-paid'
    : ($payStatus === 'refunded' ? 'is-refunded' : 'is-due');
$payModeLabel = ['cod' => 'Cash on delivery', 'razorpay' => 'Online (Razorpay)', 'simulation' => 'Simulated'][$order['payment_mode']] ?? $order['payment_mode'];

$addr = $order['address'];
$docTitle = ($isTaxInvoice ? 'Tax Invoice ' : 'Invoice ') . $order['order_number'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($docTitle) ?></title>
<style>
  :root{
    --ink:#082c40;--deep:#061b29;--muted:#607887;--teal:#087e8b;--clay:#1a9fb5;
    --gold:#c9a24b;--cream:#f2f8fa;--ice:#eaf7f8;--line:#dbe7eb;--paper:#fff;
  }
  *{box-sizing:border-box}
  body{font:15px/1.6 Inter,-apple-system,Segoe UI,system-ui,sans-serif;color:var(--ink);background:var(--cream);margin:0;padding:28px 16px}
  .sheet{max-width:860px;margin:0 auto;background:var(--paper);border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 18px 50px -32px rgba(8,44,64,.4)}

  /* Actions (screen only) */
  .actions{max-width:860px;margin:0 auto 18px;display:flex;gap:12px;flex-wrap:wrap}
  .actions a,.actions button{font:600 14px/1 Inter,system-ui,sans-serif;padding:12px 20px;border-radius:9px;cursor:pointer;text-decoration:none;border:1px solid var(--line);background:var(--paper);color:var(--ink)}
  .actions .primary{background:var(--teal);border-color:var(--teal);color:#fff}

  /* Branded header band */
  .band{background:linear-gradient(120deg,var(--deep),var(--teal));color:#fff;padding:30px 34px;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}
  .band .brand{display:flex;gap:14px;align-items:center}
  .band .mark{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.14);display:grid;place-items:center;font-weight:800;font-size:20px;letter-spacing:.02em}
  .band .brand strong{display:block;font-size:19px;line-height:1.25;max-width:17rem}
  .band .brand small{color:rgba(255,255,255,.72);font-size:12px}
  .band .meta{text-align:right}
  .band .doc-type{font-size:12px;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.78)}
  .band .doc-no{font-size:22px;font-weight:800;margin:2px 0}
  .band .doc-date{font-size:13px;color:rgba(255,255,255,.8)}
  .badge{display:inline-block;margin-top:10px;padding:5px 13px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.03em}
  .badge.is-paid{background:#d9f3ea;color:#0a6b4f}
  .badge.is-due{background:#fdeecf;color:#8a5a08}
  .badge.is-refunded{background:#fbe0e0;color:#9a2a2a}

  .body{padding:30px 34px}

  /* Cards */
  .cards{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px}
  .card{background:var(--ice);border:1px solid var(--line);border-radius:12px;padding:16px 18px}
  .card h3{margin:0 0 8px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--teal)}
  .card p{margin:0;font-size:14px}
  .card .name{font-weight:700;font-size:15px}
  .card dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:13.5px}
  .card dt{color:var(--muted)}
  .card dd{margin:0;text-align:right;font-weight:600}

  /* Line items */
  table{width:100%;border-collapse:collapse;font-size:14px}
  thead th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:700;padding:0 10px 10px;border-bottom:2px solid var(--line)}
  thead th.num,tbody td.num{text-align:right}
  tbody td{padding:13px 10px;border-bottom:1px solid var(--line);vertical-align:top}
  tbody .item strong{display:block;font-weight:700}
  tbody .item small{color:var(--muted);font-size:12.5px}
  tbody .disc{color:#0a6b4f;font-size:12px}

  /* Totals */
  .foot{display:flex;justify-content:flex-end;margin-top:24px}
  .totals{width:min(340px,100%);background:var(--ice);border:1px solid var(--line);border-radius:12px;padding:18px 20px}
  .totals .row{display:flex;justify-content:space-between;gap:16px;font-size:14px;padding:5px 0}
  .totals .row.muted{color:var(--muted)}
  .totals .row.credit{color:#0a6b4f}
  .totals hr{border:0;border-top:1px solid var(--line);margin:8px 0}
  .totals .grand{display:flex;justify-content:space-between;gap:16px;font-size:19px;font-weight:800;color:var(--ink);padding-top:6px}
  .totals .grand span:last-child{color:var(--teal)}
  .totals .due{margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)}
  .totals .due .grand{font-size:16px}

  .note{margin:26px 0 0;padding:16px 18px;background:#fbfdfe;border:1px solid var(--line);border-radius:12px;font-size:12.5px;color:var(--muted);line-height:1.7}
  .note strong{color:var(--ink)}
  .thanks{margin-top:22px;text-align:center;color:var(--muted);font-size:13px}

  @media (max-width:640px){.cards{grid-template-columns:1fr}.band,.body{padding-left:20px;padding-right:20px}.hide-sm{display:none}}

  @media print{
    body{background:#fff;padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .actions{display:none}
    .sheet{max-width:none;border:0;border-radius:0;box-shadow:none}
    tr,.card,.totals{break-inside:avoid}
    @page{margin:14mm}
  }
</style>
</head>
<body>
<div class="actions">
  <button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>
  <a href="<?= $u('order/' . $order['id']) ?>">← Back to order</a>
</div>

<div class="sheet">
  <header class="band">
    <div class="brand">
      <span class="mark" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($brand, 0, 1))) ?></span>
      <span><strong><?= $e($brand) ?></strong>
        <?php if ($storeAddr !== ''): ?><small><?= $e($storeAddr) ?></small><br><?php endif; ?>
        <small><?php
          $bits = array_filter([$storeMail, $storePhone]);
          echo $e(implode(' · ', $bits));
          if ($gstin !== '') echo ($bits ? '<br>' : '') . 'GSTIN ' . $e($gstin);
        ?></small>
      </span>
    </div>
    <div class="meta">
      <div class="doc-type"><?= $isTaxInvoice ? 'Tax Invoice' : 'Invoice' ?></div>
      <div class="doc-no"><?= $e($order['order_number']) ?></div>
      <div class="doc-date"><?= $e(gmdate('d M Y', (int)$order['created_at'])) ?></div>
      <span class="badge <?= $payClass ?>"><?= $e($label($payStatus)) ?></span>
    </div>
  </header>

  <div class="body">
    <div class="cards">
      <div class="card">
        <h3>Billed to</h3>
        <p class="name"><?= $e($addr['recipient'] ?? '') ?></p>
        <p><?php
          foreach (['line1', 'line2'] as $f) if (($addr[$f] ?? '') !== '') echo $e($addr[$f]) . '<br>';
          $cityLine = array_filter([$addr['city'] ?? '', $addr['state'] ?? '', $addr['postal_code'] ?? '']);
          if ($cityLine) echo $e(implode(', ', $cityLine)) . '<br>';
          if (($addr['country_code'] ?? '') !== '') echo $e($addr['country_code']) . '<br>';
          if (($addr['phone'] ?? '') !== '') echo 'Phone: ' . $e($addr['phone']);
        ?></p>
      </div>
      <div class="card">
        <h3>Order details</h3>
        <dl>
          <dt>Order no.</dt><dd><?= $e($order['order_number']) ?></dd>
          <dt>Order date</dt><dd><?= $e(gmdate('d M Y, H:i', (int)$order['created_at'])) ?></dd>
          <dt>Status</dt><dd><?= $e($label($order['status'])) ?></dd>
          <dt>Payment</dt><dd><?= $e($payModeLabel) ?></dd>
        </dl>
      </div>
    </div>

    <table>
      <thead>
        <tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num hide-sm">GST</th><th class="num">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($order['items'] as $i => $item):
          $rate = rtrim(rtrim(number_format(((int)($item['tax_bps'] ?? $order['tax_bps'])) / 100, 2, '.', ''), '0'), '.'); ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td class="item">
            <strong><?= $e($item['name']) ?></strong>
            <small><?= $e($item['sku']) ?></small>
            <?php if ((int)$item['discount_minor'] > 0): ?><div class="disc">Discount −<?= $m($item['discount_minor']) ?></div><?php endif; ?>
          </td>
          <td class="num"><?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?></td>
          <td class="num"><?= $m($item['unit_price_minor']) ?></td>
          <td class="num hide-sm"><?= $e($rate) ?>%</td>
          <td class="num"><?= $m($item['line_total_minor']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="foot">
      <div class="totals">
        <div class="row"><span>Subtotal</span><span><?= $m($order['subtotal_minor']) ?></span></div>
        <?php if ((int)$order['discount_minor'] > 0): ?><div class="row credit"><span>Discount</span><span>−<?= $m($order['discount_minor']) ?></span></div><?php endif; ?>
        <div class="row muted"><span>CGST <?= $e($halfRate) ?>%</span><span><?= $m($cgst) ?></span></div>
        <div class="row muted"><span>SGST <?= $e($halfRate) ?>%</span><span><?= $m($sgst) ?></span></div>
        <div class="row"><span>Delivery</span><span><?= (int)$order['shipping_minor'] ? $m($order['shipping_minor']) : 'Free' ?></span></div>
        <hr>
        <div class="grand"><span>Total</span><span><?= $m($order['total_minor']) ?></span></div>
        <?php if ($balance > 0): ?>
        <div class="due">
          <div class="row"><span>Paid in advance</span><span><?= $m($due) ?></span></div>
          <div class="grand"><span>Balance on delivery</span><span><?= $m($balance) ?></span></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="note">
      <?php if ($isTaxInvoice): ?>
        <strong>Tax invoice.</strong> Amounts are inclusive of GST (CGST + SGST) as shown. Issued by <?= $e($legal) ?><?php if ($gstin !== ''): ?>, GSTIN <?= $e($gstin) ?><?php endif; ?>.
      <?php else: ?>
        <strong>Order summary with tax breakdown.</strong> This document is a computer-generated summary of your order and the GST applied; it is not a registered tax invoice.
      <?php endif; ?>
    </div>
    <p class="thanks">Thank you for shopping with <?= $e($brand) ?>.</p>
  </div>
</div>
</body>
</html>
